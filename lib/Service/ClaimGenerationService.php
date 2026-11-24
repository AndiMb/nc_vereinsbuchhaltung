<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\ContributionGroup;
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
		$horizon = $this->settings->warningHorizon($today);

		$created = 0;
		$blocked = 0;
		$failed = [];
		foreach ($this->assignments->findActiveOrStartingBy($today, $horizon) as $assignment) {
			try {
				[$c, $b] = $this->generateForAssignment($assignment, $today, $horizon);
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
	private function generateForAssignment(Assignment $assignment, string $today, string $horizon): array {
		$created = 0;
		while (true) {
			$pending = $this->nextPendingPeriod($assignment);
			if ($pending === null) {
				return [$created, 0]; // Zuweisung ist zeitlich (validTo) beendet
			}
			[$periodStart, $periodEnd, $isFirst] = $pending;
			$dueAnchor = $this->effectiveStart($assignment, $periodStart, $isFirst);

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

			$this->createClaim($assignment, $periodStart, $periodEnd, $this->dueDateFor($assignment, $dueAnchor, $periodEnd, $today), $isFirst);
			$created++;
		}
	}

	/**
	 * Der Einzugstermin, den der Tageslauf der ersten Forderung einer neuen
	 * Zuweisung geben wird – für die Vorschau beim Anlegen
	 * ({@see AssignmentService::previewFirstPeriod()}). Dieselbe
	 * Terminentscheidung wie im Lauf ({@see dueDateFor()}), getroffen an dem Tag,
	 * an dem der Lauf die Forderung anlegen wird. Ein fehlendes Mandat bleibt
	 * außen vor: wann es kommt, weiß die Vorschau nicht.
	 *
	 * @return array{dueDate:string, scheduledDueDate:string} Termin des Einzugs und Termin laut Terminplan (verschieden, wenn die Nachzügler-Regel greift)
	 */
	public function firstClaimDueDate(Assignment $assignment): array {
		// Anker der ersten Periode ist der Beitritt, siehe effectiveStart().
		$anchor = $assignment->getValidFrom();
		[, $periodEnd] = $this->contributionYear->periodContaining($assignment->getIntervalMonths(), $anchor);
		$scheduled = $this->schedule->dueDateForPeriod($assignment->getIntervalMonths(), $anchor);
		if ($assignment->amountCents() === 0) {
			return ['dueDate' => $scheduled, 'scheduledDueDate' => $scheduled]; // beitragsfrei: kein Nachzügler
		}
		// Der Lauf legt die Forderung an, sobald Zuweisung und Termin im Vorwarnfenster liegen – frühestens heute.
		$warningDays = $this->settings->warningLeadDays();
		$generationDay = max($this->today(), self::minusDays($scheduled, $warningDays), self::minusDays($anchor, $warningDays));
		return ['dueDate' => $this->dueDateFor($assignment, $anchor, $periodEnd, $generationDay), 'scheduledDueDate' => $scheduled];
	}

	/**
	 * Zieht die schon angelegten, aber noch nicht angekündigten Forderungen
	 * einer Zuweisung nach einer Änderung von Betrag oder Gruppe nach – Spec
	 * §3.3: eine Änderung wirkt ab der ersten Periode ohne Vorabinfo, auch wenn
	 * der Lauf deren Forderung im Vorwarnfenster schon angelegt hat.
	 *
	 * An Ort und Stelle statt neu erzeugt, weil an einer Forderung Zahlungen,
	 * Mahnungen und Lastschriftposten hängen können: Betrag und Bezeichnung
	 * folgen der Zuweisung, Zeitraum und Termin bleiben (eine gerissene
	 * Vorlauffrist verschiebt nichts automatisch, Spec §3.5). Nur ein
	 * beitragsfreier Eintrag, der zahlungspflichtig wird, bekommt den Termin,
	 * den der Lauf ihm heute gäbe.
	 *
	 * @param ContributionGroup|null $group die Gruppe der Zuweisung, falls schon geladen
	 * @return int Anzahl nachgezogener Forderungen
	 */
	public function refreshUnlockedClaims(Assignment $assignment, ?ContributionGroup $group = null): int {
		$today = $this->today();
		$refreshed = 0;
		foreach ($this->openItems->findByAssignment((int)$assignment->getId()) as $item) {
			$feeFreeEntry = self::isFeeFreeEntry($item);
			if (!self::isUnannounced($item, $feeFreeEntry, $today)) {
				continue;
			}
			$group ??= $this->groups->find($assignment->getGroupId());
			$periodStart = (string)$item->getPeriodStart();
			$amountCents = ContributionAmount::forPeriod($assignment, $group, $periodStart, (string)$item->getPeriodEnd());
			if (($amountCents === $item->getAmountCents() && $item->getDescription() === $group->getName())
				|| $this->isUnderway($item)) {
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
				[, $naturalEnd] = $this->contributionYear->periodContaining($assignment->getIntervalMonths(), $periodStart);
				$item->setDueDate($this->dueDateFor($assignment, $periodStart, $naturalEnd, $today));
			}
			$this->openItems->update($item);
			$refreshed++;
		}
		return $refreshed;
	}

	/** Der vom Lauf festgehaltene Eintrag einer beitragsfreien Periode (siehe Klassendoc), kein Erlass von Hand. */
	private static function isFeeFreeEntry(OpenItem $item): bool {
		return $item->getStatus() === 'waived' && $item->getAmountCents() === 0 && $item->getSettledBy() === null;
	}

	/**
	 * Periodische Forderung, die noch niemand angekündigt oder bezahlt hat:
	 * offen (oder beitragsfreier Eintrag), keine Vorabinfo, Termin nach heute,
	 * nichts bezahlt. Ohne Datenbank; die Abfragen stehen in {@see isUnderway()}.
	 */
	private static function isUnannounced(OpenItem $item, bool $feeFreeEntry, string $today): bool {
		return $item->getPeriodStart() !== null && $item->getPeriodEnd() !== null
			&& ($feeFreeEntry || ClaimStateResolver::resolveForItem($item) === ClaimStateResolver::STATE_OPEN)
			&& !EffectivityRuleService::isLocked($item->getPrenotifiedAt())
			&& $item->getDueDate() !== null && $item->getDueDate() > $today
			&& $item->getSettledCents() <= 0 && $item->getPaidJournalId() === null;
	}

	/**
	 * Steckt in einem lebenden Lastschriftlauf, oder eine Zahlungsaufforderung
	 * bzw. Mahnung ist verschickt – bei Überweisern das Gegenstück der
	 * Vorabinfo ({@see DunningLadderService}).
	 */
	private function isUnderway(OpenItem $item): bool {
		return $this->debitItems->findLiveBatchStatusByOpenItem((int)$item->getId()) !== null
			|| $this->dunningNotices->findByOpenItem((int)$item->getId()) !== [];
	}

	/**
	 * Die Terminentscheidung des Laufs, getroffen am Tag `$day`: Terminplan ab
	 * `$anchor`, bei Lastschrift mit der Nachzügler-Regel. Eine Stelle für
	 * Tageslauf, Vorschau und Nachziehen.
	 */
	private function dueDateFor(Assignment $assignment, string $anchor, string $periodEnd, string $day): string {
		if ($assignment->getPaymentMethod() !== Assignment::PAYMENT_METHOD_DIRECT_DEBIT) {
			return $this->schedule->dueDateForPeriod($assignment->getIntervalMonths(), $anchor);
		}
		return $this->dueDateWithNachzuegler($assignment->getIntervalMonths(), $anchor, $periodEnd, $day, $this->settings->prenotificationLeadDays());
	}

	/**
	 * Tatsächlicher Beginn einer Periode und zugleich Anker des Terminplans:
	 * der Periodenbeginn, bei der allerersten (Prorata-)Periode einer
	 * Zuweisung aber der Beitritt – sonst hätte eine erst im März beigetretene
	 * Zuweisung mit Jahresturnus einen „natürlichen" Termin am 1. Januar, den
	 * das Mitglied nie erlebt hat (siehe {@see DueDateScheduleService::dueDateForPeriod()}).
	 */
	private function effectiveStart(Assignment $assignment, string $periodStart, bool $isFirst): string {
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
		$effectiveFrom = $this->effectiveStart($assignment, $periodStart, $isFirst);
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
