<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\ContributionGroupMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCP\AppFramework\Utility\ITimeFactory;

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
 * unvollständig.
 *
 * **Mandatsprüfung** (Spec §3.2 „bei der Forderungserzeugung: kein aktives
 * Mandat → kein Forderungseintrag, sondern Störfall"): nur für
 * `payment_method: direct_debit`. Eine Überweiser-Zuweisung (Spec §3.5
 * „Überweiser bekommen Forderungen … nie … Störfall") braucht kein Mandat und
 * wird immer erzeugt.
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

	public function __construct(
		private AssignmentMapper $assignments,
		private OpenItemMapper $openItems,
		private ContributionGroupMapper $groups,
		private MemberMapper $members,
		private DirectDebitEligibilityResolver $eligibility,
		private ContributionYearService $contributionYear,
		private DueDateScheduleService $schedule,
		private ContributionCycleSettings $settings,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Ein Tageslauf: erzeugt für jede aktive Zuweisung die jeweils nächste
	 * fällige Periode, sofern sie ins Vorwarnfenster fällt. Idempotent – ein
	 * zweiter Aufruf am selben Tag erzeugt nichts erneut, weil die zuletzt
	 * erzeugte Periode je Zuweisung aus den bereits gespeicherten Forderungen
	 * abgelesen wird.
	 *
	 * @param string|null $today Stichtag, sonst heute (für Tests)
	 * @return array{created:int, blocked:int} blocked = Zuweisungen, deren
	 *                                         nächste Periode an einem fehlenden Mandat hängt (Störfall, siehe
	 *                                         {@see ContributionCycleTaskService})
	 */
	public function generateDue(?string $today = null): array {
		$today ??= $this->today();
		$horizon = (new \DateTimeImmutable($today))->modify('+' . $this->settings->warningLeadDays() . ' days')->format('Y-m-d');
		$leadDays = $this->settings->prenotificationLeadDays();

		$created = 0;
		$blocked = 0;
		foreach ($this->assignments->findActiveAsOf($today) as $assignment) {
			[$c, $b] = $this->generateForAssignment($assignment, $today, $horizon, $leadDays);
			$created += $c;
			$blocked += $b;
		}
		return ['created' => $created, 'blocked' => $blocked];
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
			// Anker fuer die Terminplan-Berechnung: im Regelfall der
			// Periodenbeginn selbst, bei der allerersten (Prorata-)Periode
			// einer Zuweisung aber deren tatsaechlicher Beitritt - sonst
			// haette eine erst im Maerz beigetretene Zuweisung mit
			// Jahresturnus einen "natuerlichen" Termin am 1. Januar, den das
			// Mitglied nie erlebt hat (siehe DueDateScheduleService::dueDateForPeriod()).
			$dueAnchor = $isFirst ? max($periodStart, $assignment->getValidFrom()) : $periodStart;

			$naturalDue = $this->schedule->dueDateForPeriod($assignment->getIntervalMonths(), $dueAnchor);
			if ($naturalDue > $horizon) {
				return [$created, 0]; // noch nicht im Vorwarnfenster - morgen wieder versuchen
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
			$deadline = (new \DateTimeImmutable($due))->modify('-' . $leadDays . ' days')->format('Y-m-d');
			if ($today < $deadline) {
				return $due;
			}
			[$dueAnchor, $periodEnd] = $this->schedule->nextPeriod($intervalMonths, $periodEnd);
		}
		throw new \RuntimeException('Kein Einzugstermin mit ausreichendem Vorlauf gefunden (Terminplan/Vorlauffrist prüfen).');
	}

	private function createClaim(Assignment $assignment, string $periodStart, string $periodEnd, string $dueDate, bool $isFirst): OpenItem {
		// Prorata an beiden Enden (Spec §3.3): am Anfang bei der allerersten
		// Periode (Beitritt mitten in der Periode), am Ende, wenn die
		// Zuweisung (validTo) innerhalb dieser Periode endet. ProrataCalculator
		// liefert bei unbeschnittenen Grenzen automatisch den vollen
		// Turnusbetrag - keine separate Fallunterscheidung nötig.
		$effectiveFrom = $isFirst ? max($periodStart, $assignment->getValidFrom()) : $periodStart;
		$effectiveTo = ($assignment->getValidTo() !== null && $assignment->getValidTo() < $periodEnd)
			? $assignment->getValidTo()
			: $periodEnd;

		$group = $this->groups->find($assignment->getGroupId());

		$item = new OpenItem();
		$item->setDebtor($this->members->displayNameOr($assignment->getMemberId(), 'Mitglied #' . $assignment->getMemberId()));
		$item->setDescription($group->getName());
		$item->setAmountCents(ProrataCalculator::amountCents($assignment->getMonthlyAmountCents(), $effectiveFrom, $effectiveTo));
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
		return $this->time->getDateTime()->format('Y-m-d');
	}

	private function now(): string {
		return $this->time->getDateTime()->format(\DateTime::ATOM);
	}
}
