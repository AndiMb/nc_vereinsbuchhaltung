<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\ContributionGroupMapper;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\DunningNoticeMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Periodische Forderungserzeugung aus Zuweisungen (Spec §2.2/§3.3/§3.5, Issue
 * #70) – der Teil, den {@see ClaimService} in Issue #68 bewusst ausgelassen
 * hat (siehe dortiger Klassendoc): „Der Monatsbeitrag ist das Atom … jeder
 * Einzugsbetrag entsteht per Multiplikation (Monatsbeitrag × Turnusmonate)".
 *
 * Eine Zuweisung bekommt hier immer nur die JEWEILS NÄCHSTE noch fehlende
 * Periode – nie mehrere auf Vorrat. Zwei Gründe: (1) je später eine Periode
 * erzeugt wird, desto länger bleibt sie für {@see EffectivityRuleService}
 * änderbar (die Sperre kommt erst mit der Vorabinfo, nicht mit der
 * Forderungserzeugung); (2) eine blockierte Periode (fehlendes Mandat, siehe
 * unten) darf keine Lücke reißen – sie wird jeden Tag erneut versucht, bis sie
 * sich löst, und erst DANACH geht es mit der nächsten Periode weiter.
 *
 * **Generierungshorizont:** eine Periode wird erzeugt, sobald ihr natürlicher
 * Einzugstermin (vor einer eventuellen Nachzügler-Verschiebung) in das
 * Vorwarnfenster fällt (`heute + warningLeadDays >= Termin`) – genau der
 * Zeitpunkt, an dem {@see ContributionCycleTaskService} die
 * D−21-Vorwarn-Aufgabe zeigen soll. Vorher zu erzeugen brächte nichts (die
 * Zuweisung könnte sich bis dahin noch ändern), später wäre der D−21-Zähler
 * unvollständig. Das gilt auch für eine Zuweisung, die erst künftig beginnt:
 * Liegt der erste Termin eines neuen Mitglieds (Beitritt + Versatz) schon im
 * Vorwarnfenster, entsteht die Forderung vor dem Beitritt – wartete der Lauf
 * auf den Beitritt, wäre die Vorabinfo-Frist dann meist schon gerissen und
 * der erste Einzug rutschte einen ganzen Turnus nach hinten.
 *
 * **Mandatsprüfung** (Spec §3.2 „bei der Forderungserzeugung: kein aktives
 * Mandat → kein Forderungseintrag, sondern Störfall"): nur für
 * `payment_method: direct_debit`. Eine Überweiser-Zuweisung (Spec §3.5
 * „Überweiser bekommen Forderungen … nie … Störfall") braucht kein Mandat und
 * wird immer erzeugt.
 *
 * **Beitragsfreie Zuweisung** (Monatsbeitrag 0 €, z. B. eine Gruppe „Ruhend“
 * für Pausen): Die Periode wird trotzdem als Eintrag festgehalten, damit der
 * Lauf weiß, wie weit er ist – sonst würde eine spätere Betragserhöhung die
 * Perioden ab Beginn der Zuweisung rückwirkend nachfordern. Der Eintrag ist
 * aber nie eine Forderung: Betrag 0, sofort erledigt (`waived`), ohne Mandats-
 * prüfung, Vorabinfo, Einzug oder Mahnwesen, und die Listen blenden ihn aus
 * ({@see OpenItemMapper::findAll()}).
 *
 * **Nachzügler** (Spec §3.5): trägt eine neue Periode den natürlichen
 * Einzugstermin nicht mehr rechtzeitig vor Ablauf der Vorabinfo-Frist, fährt
 * sie am nächsten Termin desselben Turnus – die Periode selbst
 * (`period_start`/`period_end`, maßgeblich für Beitragsbescheinigungen, Spec
 * §3.7) bleibt unverändert, nur `due_date` verschiebt sich nach hinten.
 */
class ClaimGenerationService {

	/** Schutz gegen eine Endlosschleife bei einem kaputten/zirkulären Terminplan. */
	private const MAX_NACHZUEGLER_HOPS = 60;

	/** Schutz gegen eine Endlosschleife beim Turnuswechsel-Guard, siehe nextPendingPeriod(). */
	private const MAX_INTERVAL_CHANGE_HOPS = 60;

	private ClubClock $clock;

	public function __construct(
		private AssignmentMapper $assignments,
		private OpenItemMapper $openItems,
		private ContributionGroupMapper $groups,
		private MemberMapper $members,
		private DirectDebitEligibilityResolver $eligibility,
		private ContributionYearService $contributionYear,
		private DueDateScheduleService $schedule,
		private ContributionCycleSettings $settings,
		private DebitItemMapper $debitItems,
		private DunningNoticeMapper $dunningNotices,
		private ITimeFactory $time,
		?ClubClock $clock = null,
		private ?LoggerInterface $logger = null,
	) {
		$this->clock = $clock ?? new ClubClock($this->time);
	}

	/**
	 * Ein Tageslauf: erzeugt für jede aktive Zuweisung die jeweils nächste
	 * fällige Periode, sofern sie ins Vorwarnfenster fällt. Idempotent – ein
	 * zweiter Aufruf am selben Tag erzeugt nichts erneut, weil die zuletzt
	 * erzeugte Periode je Zuweisung aus den bereits gespeicherten Forderungen
	 * abgelesen wird.
	 *
	 * Jede Zuweisung läuft für sich: scheitert eine (kaputter Altbestand,
	 * Datenbankfehler), bekommen die übrigen trotzdem ihre Forderungen. Die
	 * gescheiterten merkt sich {@see ContributionCycleSettings::setFailedAssignmentIds()}
	 * bis zum nächsten Lauf, damit sie als Aufgabe erscheinen statt nur im Log.
	 *
	 * @param string|null $today Stichtag, sonst heute (für Tests)
	 * @return array{created:int, blocked:int, failed:int} blocked = Zuweisungen, deren
	 *                                                     nächste Periode an einem fehlenden Mandat hängt (Störfall, siehe
	 *                                                     {@see ContributionCycleTaskService}); failed = Zuweisungen, deren
	 *                                                     Erzeugung mit einem Fehler abbrach
	 */
	public function generateDue(?string $today = null): array {
		$today ??= $this->today();
		$horizon = (new \DateTimeImmutable($today))->modify('+' . $this->settings->warningLeadDays() . ' days')->format('Y-m-d');
		$leadDays = $this->settings->prenotificationLeadDays();

		$created = 0;
		$blocked = 0;
		$failed = [];
		foreach ($this->assignments->findActiveOrStartingBy($today, $horizon) as $assignment) {
			try {
				[$c, $b] = $this->generateForAssignment($assignment, $today, $horizon, $leadDays);
			} catch (\Throwable $e) {
				// \Throwable: ein Überlauf beim Rechnen ist ein TypeError, keine Exception.
				$failed[] = (int)$assignment->getId();
				$this->logger?->error('Einzugszyklus: Forderung für Zuweisung {assignment} (Mitglied {member}) nicht erzeugt', [
					'app' => Application::APP_ID,
					'assignment' => $assignment->getId(),
					'member' => $assignment->getMemberId(),
					'exception' => $e,
				]);
				continue;
			}
			$created += $c;
			$blocked += $b;
		}
		$this->settings->setFailedAssignmentIds($failed);
		return ['created' => $created, 'blocked' => $blocked, 'failed' => count($failed)];
	}

	/** @return array{0:int,1:int} [erzeugt, blockiert] */
	private function generateForAssignment(Assignment $assignment, string $today, string $horizon, int $leadDays): array {
		$created = 0;
		while (true) {
			$pending = $this->nextPendingPeriod($assignment);
			if ($pending === null) {
				return [$created, 0]; // Zuweisung ist zeitlich (validTo) beendet
			}
			[$periodStart, $periodEnd, $isFirst] = $pending;
			$dueAnchor = $this->dueAnchor($assignment, $periodStart, $isFirst);

			$naturalDue = $this->schedule->dueDateForPeriod($assignment->getIntervalMonths(), $dueAnchor);
			if ($naturalDue > $horizon) {
				return [$created, 0]; // noch nicht im Vorwarnfenster - morgen wieder versuchen
			}

			if ($this->periodAmountCents($assignment, $periodStart, $periodEnd, $isFirst) === 0) {
				// Beitragsfrei: nichts einzuziehen, also auch kein Mandat nötig und kein Nachzügler.
				$this->createFeeFreePeriod($assignment, $periodStart, $periodEnd, $naturalDue, $isFirst);
				continue;
			}

			if ($assignment->getPaymentMethod() === Assignment::PAYMENT_METHOD_DIRECT_DEBIT
				&& !$this->eligibility->hasCollectibleMandate($assignment->getMemberId())) {
				// Störfall "Kein Mandat + Lastschrift gewollt" (abgeleitete
				// Abfrage, siehe ContributionCycleTaskService) - dieselbe
				// Periode wird morgen erneut versucht, KEINE Luecke: die
				// naechste Periode darf nicht vorbeiziehen, solange diese hier
				// nicht aufgeloest ist.
				return [$created, 1];
			}

			$dueDate = $assignment->getPaymentMethod() === Assignment::PAYMENT_METHOD_DIRECT_DEBIT
				? $this->dueDateWithNachzuegler($assignment->getIntervalMonths(), $dueAnchor, $periodEnd, $today, $leadDays)
				: $naturalDue;

			$this->createClaim($assignment, $periodStart, $periodEnd, $dueDate, $isFirst);
			$created++;
		}
	}

	/**
	 * Der Einzugstermin, den der Tageslauf der ersten Forderung einer neuen
	 * Zuweisung geben wird, von `$today` aus gesehen – für die Vorschau beim
	 * Anlegen ({@see AssignmentService::previewFirstPeriod()}), damit sie nie
	 * einen Termin verspricht, den der Lauf nicht hält. Dieselbe Rechnung wie
	 * {@see generateForAssignment()}: Termin laut Terminplan ab dem Beitritt,
	 * bei Lastschrift dazu die Nachzügler-Regel an dem Tag, an dem der Lauf die
	 * Forderung anlegen wird (sobald Zuweisung und Termin im Vorwarnfenster
	 * liegen, frühestens heute).
	 *
	 * Ein fehlendes Mandat bleibt außen vor: Es hält die Forderung auf, bis es
	 * da ist, und wann das sein wird, weiß die Vorschau nicht.
	 *
	 * @param string|null $today Stichtag, sonst heute (für Tests)
	 * @return array{dueDate:string, scheduledDueDate:string} `dueDate` ist der
	 *                                                        Termin, an dem eingezogen wird, `scheduledDueDate` der laut Terminplan
	 *                                                        (verschieden, wenn die Nachzügler-Regel greift)
	 * @throws \InvalidArgumentException bei unmöglichem Datum
	 */
	public function firstClaimDueDate(Assignment $assignment, ?string $today = null): array {
		$today ??= $this->today();
		$intervalMonths = $assignment->getIntervalMonths();
		[$periodStart, $periodEnd] = $this->contributionYear->periodContaining($intervalMonths, $assignment->getValidFrom());
		$dueAnchor = $this->dueAnchor($assignment, $periodStart, true);
		$scheduled = $this->schedule->dueDateForPeriod($intervalMonths, $dueAnchor);

		// Beitragsfrei steht am Betrag der Zuweisung selbst; die Vorschau kennt bei Monatsbeträgen keine Gruppe.
		$feeFree = ($assignment->isAnnual() ? $assignment->getAnnualAmountCents() : $assignment->getMonthlyAmountCents()) === 0;
		if ($assignment->getPaymentMethod() !== Assignment::PAYMENT_METHOD_DIRECT_DEBIT || $feeFree) {
			return ['dueDate' => $scheduled, 'scheduledDueDate' => $scheduled];
		}

		$warningDays = $this->settings->warningLeadDays();
		$generationDay = max(
			$today,
			self::minusDays($scheduled, $warningDays),
			self::minusDays($assignment->getValidFrom(), $warningDays),
		);
		return [
			'dueDate' => $this->dueDateWithNachzuegler($intervalMonths, $dueAnchor, $periodEnd, $generationDay, $this->settings->prenotificationLeadDays()),
			'scheduledDueDate' => $scheduled,
		];
	}

	/**
	 * Zieht die schon erzeugten, aber noch nicht gesperrten Forderungen einer
	 * Zuweisung nach einer Änderung (Betrag, Gruppe, Untergrenze) nach – Spec
	 * §3.3 „Eine Wirksamkeitsregel für alles": eine Änderung wirkt ab der
	 * ersten Periode ohne versandte Vorabinfo, also auch auf eine Forderung,
	 * die der Tageslauf im Vorwarnfenster schon angelegt hat. Ohne das bliebe
	 * eine Korrektur zwischen Vorwarnung (D−21) und Vorabinfo (D−14) in der
	 * nächsten Forderung wirkungslos.
	 *
	 * Gerechnet wird an Ort und Stelle, mit derselben Rechnung wie beim Anlegen:
	 * Betrag und Bezeichnung folgen der Zuweisung, Zeitraum und Einzugstermin
	 * bleiben – eine Korrektur am Tag der Vorabinfo soll den Einzug nicht um
	 * einen Turnus verschieben, und eine gerissene Vorlauffrist verschiebt
	 * nichts automatisch (Spec §3.5). An Ort und Stelle statt neu erzeugt, weil
	 * an einer Forderung Zahlungen, Mahnungen und Lastschriftposten hängen
	 * können. Fällt der Betrag auf 0, wird die Forderung zum beitragsfreien
	 * Eintrag (siehe Klassendoc); wird ein beitragsfreier Eintrag zahlungspflichtig,
	 * wird er zur Forderung, mit dem Termin, den der Lauf ihr heute gäbe.
	 *
	 * Angefasst wird nur, was noch niemand angekündigt oder bezahlt hat: offen
	 * (bzw. vom Lauf beitragsfrei gestellt), keine Vorabinfo, keine
	 * Zahlungsaufforderung oder Mahnung (bei Überweisern übernimmt die
	 * Zahlungsaufforderung die Rolle der Vorabinfo, {@see DunningLadderService}),
	 * Termin nach heute, nichts bezahlt, in keinem lebenden Lastschriftlauf.
	 *
	 * @param string|null $today Stichtag, sonst heute (für Tests)
	 * @return int Anzahl nachgezogener Forderungen
	 */
	public function refreshUnlockedClaims(Assignment $assignment, ?string $today = null): int {
		$today ??= $this->today();
		$group = null;
		$refreshed = 0;
		foreach ($this->openItems->findByAssignment((int)$assignment->getId()) as $item) {
			$periodStart = $item->getPeriodStart();
			$periodEnd = $item->getPeriodEnd();
			$feeFreeEntry = $item->getStatus() === 'waived' && $item->getAmountCents() === 0 && $item->getSettledBy() === null;
			if ($periodStart === null || $periodEnd === null
				|| ($item->getStatus() !== 'open' && !$feeFreeEntry)
				|| $item->getPrenotifiedAt() !== null
				|| $item->getCancelledAt() !== null
				|| $item->getDueDate() === null || $item->getDueDate() <= $today
				|| $item->getSettledCents() > 0 || $item->getPaidJournalId() !== null
				|| $this->debitItems->findLiveBatchStatusByOpenItem((int)$item->getId()) !== null
				|| $this->dunningNotices->findByOpenItem((int)$item->getId()) !== []) {
				continue;
			}

			$group ??= $this->groups->find($assignment->getGroupId());
			$amountCents = ContributionAmount::forPeriod($assignment, $group, $periodStart, $periodEnd);
			if ($amountCents === $item->getAmountCents() && $item->getDescription() === $group->getName()) {
				continue;
			}
			$item->setDescription($group->getName());
			$item->setAmountCents($amountCents);
			if ($amountCents === 0 && !$feeFreeEntry) {
				$item->setStatus('waived');
				$item->setSettledAt($this->now());
			} elseif ($amountCents > 0 && $feeFreeEntry) {
				$item->setStatus('open');
				$item->setSettledAt(null);
				$item->setDueDate($this->dueDateIfCreatedToday($assignment, $periodStart, $today));
			}
			$this->openItems->update($item);
			$refreshed++;
		}
		return $refreshed;
	}

	/**
	 * Der Termin, den {@see generateForAssignment()} einer Periode mit diesem
	 * (tatsächlichen) Beginn heute gäbe: Terminplan, bei Lastschrift mit der
	 * Nachzügler-Regel. Der gespeicherte Beginn ist schon der Anker – bei der
	 * ersten Periode der Beitritt, sonst der Periodenbeginn.
	 */
	private function dueDateIfCreatedToday(Assignment $assignment, string $periodStart, string $today): string {
		$intervalMonths = $assignment->getIntervalMonths();
		if ($assignment->getPaymentMethod() !== Assignment::PAYMENT_METHOD_DIRECT_DEBIT) {
			return $this->schedule->dueDateForPeriod($intervalMonths, $periodStart);
		}
		[, $naturalEnd] = $this->contributionYear->periodContaining($intervalMonths, $periodStart);
		return $this->dueDateWithNachzuegler($intervalMonths, $periodStart, $naturalEnd, $today, $this->settings->prenotificationLeadDays());
	}

	/**
	 * Anker für die Terminplan-Berechnung: im Regelfall der Periodenbeginn
	 * selbst, bei der allerersten (Prorata-)Periode einer Zuweisung aber deren
	 * tatsächlicher Beitritt – sonst hätte eine erst im März beigetretene
	 * Zuweisung mit Jahresturnus einen „natürlichen" Termin am 1. Januar, den
	 * das Mitglied nie erlebt hat (siehe {@see DueDateScheduleService::dueDateForPeriod()}).
	 */
	private function dueAnchor(Assignment $assignment, string $periodStart, bool $isFirst): string {
		return $isFirst ? max($periodStart, $assignment->getValidFrom()) : $periodStart;
	}

	private static function minusDays(string $date, int $days): string {
		return (new \DateTimeImmutable($date))->modify('-' . $days . ' days')->format('Y-m-d');
	}

	/**
	 * Die jeweils nächste noch nicht erzeugte Periode einer Zuweisung, oder
	 * null, wenn die Zuweisung (validTo) bereits vollständig abgedeckt ist.
	 *
	 * @return array{0:string,1:string,2:bool}|null [periodStart, periodEnd, istErstePeriode]
	 */
	private function nextPendingPeriod(Assignment $assignment): ?array {
		$latestPeriodEnd = $this->latestGeneratedPeriodEnd($assignment);

		// Die zuletzt erzeugte Forderung reicht bereits bis zum (oder über
		// das) Ende der Zuweisung: fertig. Wichtig bei einer am `validTo`
		// gekuerzten letzten Periode (Prorata am Ende, siehe createClaim()) -
		// ohne diese Prüfung würde {@see DueDateScheduleService::nextPeriod()}
		// vom gekürzten `period_end` aus wieder in dieselbe natürliche Periode
		// zurückrechnen (z. B. Turnus 12: `validTo` mitten im Jahr,
		// `nextDay(validTo)` liegt immer noch im selben Kalenderjahr) und in
		// einer Endlosschleife identische Forderungen erzeugen.
		if ($latestPeriodEnd !== null && $assignment->getValidTo() !== null && $latestPeriodEnd >= $assignment->getValidTo()) {
			return null;
		}

		if ($latestPeriodEnd === null) {
			[$periodStart, $periodEnd] = $this->contributionYear->periodContaining($assignment->getIntervalMonths(), $assignment->getValidFrom());
			$isFirst = true;
		} else {
			[$periodStart, $periodEnd] = $this->schedule->nextPeriod($assignment->getIntervalMonths(), $latestPeriodEnd);
			// Turnuswechsel-Sonderfall (Spec §3.4, Issue #76 "Self-Service
			// Beitrag-Aktionen"): ein Turnuswechsel wirkt "ab der ersten Periode
			// des NEUEN Turnus, die vollständig hinter der letzten eingezogenen
			// liegt". Wechselt der Turnus zwischen zwei Perioden, kann das neue
			// Perioden-Raster anders liegen als das alte - die per nextPeriod()
			// im neuen Raster gefundene Periode kann dann noch vor oder
			// überlappend mit der zuletzt erzeugten (ggf. schon
			// vorabinformierten) Periode liegen. In diesem Fall im neuen Raster
			// so lange weiterspringen, bis die Periode vollständig dahinter
			// liegt - "kein Überholen", analog zum Terminplan-Guard in
			// DueDateScheduleService::assertGuards(). Im Normalfall
			// (unveränderter Turnus) liegt periodStart ohnehin immer schon exakt
			// einen Tag nach $latestPeriodEnd - die Schleife dreht dann kein
			// einziges Mal (kein Verhaltensunterschied zum bisherigen Code).
			for ($hop = 0; $periodStart <= $latestPeriodEnd && $hop < self::MAX_INTERVAL_CHANGE_HOPS; $hop++) {
				[$periodStart, $periodEnd] = $this->schedule->nextPeriod($assignment->getIntervalMonths(), $periodEnd);
			}
			$isFirst = false;
		}

		if ($assignment->getValidTo() !== null && $periodStart > $assignment->getValidTo()) {
			return null;
		}
		return [$periodStart, $periodEnd, $isFirst];
	}

	/**
	 * `period_end` der zuletzt erzeugten periodischen Forderung dieser
	 * Zuweisung. `findByAssignment()` ist nach `period_start` aufsteigend
	 * sortiert; da sich Perioden derselben Zuweisung nie überlappen, trägt der
	 * letzte Eintrag automatisch das größte `period_end`.
	 */
	private function latestGeneratedPeriodEnd(Assignment $assignment): ?string {
		$periods = array_values(array_filter(
			$this->openItems->findByAssignment((int)$assignment->getId()),
			static fn (OpenItem $item): bool => $item->getPeriodEnd() !== null,
		));
		if ($periods === []) {
			return null;
		}
		return $periods[count($periods) - 1]->getPeriodEnd();
	}

	/**
	 * Nachzügler-Regel (Spec §3.5): so lange zur nächsten Periode desselben
	 * Turnus weiterrücken, bis deren Vorabinfo-Frist noch nicht angebrochen
	 * ist ("noch nicht angebrochen" = die volle Vorlauffrist liegt noch vor
	 * uns, `heute < Termin - Vorlauftage`).
	 */
	private function dueDateWithNachzuegler(int $intervalMonths, string $dueAnchor, string $periodEnd, string $today, int $leadDays): string {
		for ($hop = 0; $hop < self::MAX_NACHZUEGLER_HOPS; $hop++) {
			$due = $this->schedule->dueDateForPeriod($intervalMonths, $dueAnchor);
			$deadline = self::minusDays($due, $leadDays);
			if ($today < $deadline) {
				return $due;
			}
			[$dueAnchor, $periodEnd] = $this->schedule->nextPeriod($intervalMonths, $periodEnd);
		}
		throw new \RuntimeException('Kein Einzugstermin mit ausreichendem Vorlauf gefunden (Terminplan/Vorlauffrist prüfen).');
	}

	/**
	 * Betrag einer Periode: Prorata an beiden Enden (Spec §3.3) – am Anfang bei
	 * der allerersten Periode (Beitritt mitten in der Periode), am Ende, wenn die
	 * Zuweisung (validTo) innerhalb dieser Periode endet. ProrataCalculator
	 * liefert bei unbeschnittenen Grenzen automatisch den vollen Turnusbetrag –
	 * keine separate Fallunterscheidung nötig.
	 *
	 * @return array{0:int,1:string,2:string} Betrag in Cent, tatsächlicher Beginn und Ende der Periode
	 */
	private function periodFigures(Assignment $assignment, string $periodStart, string $periodEnd, bool $isFirst): array {
		$effectiveFrom = $isFirst ? max($periodStart, $assignment->getValidFrom()) : $periodStart;
		$effectiveTo = ($assignment->getValidTo() !== null && $assignment->getValidTo() < $periodEnd)
			? $assignment->getValidTo()
			: $periodEnd;
		// Monatsgruppen: Monatsbeitrag × Monate. Jahresgruppen (Issue #270): volles Jahr exakt der Jahresbetrag,
		// angebrochenes Jahr anteilig gerundet oder – wenn die Gruppe nicht anteilig rechnet – voll.
		$group = $this->groups->find($assignment->getGroupId());
		return [ContributionAmount::forPeriod($assignment, $group, $effectiveFrom, $effectiveTo), $effectiveFrom, $effectiveTo];
	}

	private function periodAmountCents(Assignment $assignment, string $periodStart, string $periodEnd, bool $isFirst): int {
		return $this->periodFigures($assignment, $periodStart, $periodEnd, $isFirst)[0];
	}

	/** Hält eine beitragsfreie Periode fest (siehe Klassendoc): Betrag 0, sofort erledigt, nie eine Forderung. */
	private function createFeeFreePeriod(Assignment $assignment, string $periodStart, string $periodEnd, string $dueDate, bool $isFirst): OpenItem {
		$item = $this->createClaim($assignment, $periodStart, $periodEnd, $dueDate, $isFirst);
		$item->setStatus('waived');
		$item->setSettledAt($this->now());
		return $this->openItems->update($item);
	}

	private function createClaim(Assignment $assignment, string $periodStart, string $periodEnd, string $dueDate, bool $isFirst): OpenItem {
		[$amountCents, $effectiveFrom, $effectiveTo] = $this->periodFigures($assignment, $periodStart, $periodEnd, $isFirst);

		$group = $this->groups->find($assignment->getGroupId());

		$item = new OpenItem();
		$item->setDebtor($this->members->displayNameOr($assignment->getMemberId(), 'Mitglied #' . $assignment->getMemberId()));
		$item->setDescription($group->getName());
		$item->setAmountCents($amountCents);
		$item->setDueDate($dueDate);
		$item->setStatus('open');
		$item->setMemberId($assignment->getMemberId());
		$item->setType(OpenItem::TYPE_CONTRIBUTION);
		$item->setAssignmentId($assignment->getId());
		$item->setPeriodStart($effectiveFrom);
		$item->setPeriodEnd($effectiveTo);
		$item->setCreatedAt($this->now());
		return $this->openItems->insert($item);
	}

	private function today(): string {
		return $this->clock->today();
	}

	private function now(): string {
		return $this->time->getDateTime()->format(\DateTime::ATOM);
	}
}
