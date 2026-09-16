<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentEvent;
use OCA\Vereinsbuchhaltung\Db\AssignmentEventMapper;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\ContributionGroup;
use OCA\Vereinsbuchhaltung\Db\ContributionGroupMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Pflege der Zuweisungen (Spec §2.2/§3.3 „Zuweisung (Assignment)"):
 * zeitraumbehaftete Kante Mitglied↔Beitragsgruppe, append-only Historie in
 * {@see AssignmentEvent}. Eine Wirksamkeitsregel für Gruppenwechsel/
 * Turnuswechsel/Betragsänderung/Untergrenzen-Erhöhung –
 * {@see EffectivityRuleService} – gilt für alle Änderungsmethoden gleich.
 */
class AssignmentService {

	public function __construct(
		private AssignmentMapper $mapper,
		private AssignmentEventMapper $eventMapper,
		private ContributionGroupMapper $groupMapper,
		private OpenItemMapper $openItemMapper,
		private ContributionYearService $contributionYear,
		private ITimeFactory $time,
		private IL10N $l10n,
	) {
	}

	/** @return Assignment[] */
	public function findAll(): array {
		return $this->mapper->findAll();
	}

	public function find(int $id): Assignment {
		return $this->mapper->find($id);
	}

	/** @return Assignment[] */
	public function findByMember(int $memberId): array {
		return $this->mapper->findByMember($memberId);
	}

	/**
	 * @throws \InvalidArgumentException bei ungültigen Werten oder Überlappung
	 * @throws DoesNotExistException wenn es die Gruppe nicht gibt
	 */
	public function create(
		int $memberId,
		int $groupId,
		int $intervalMonths,
		int $monthlyAmountCents,
		string $paymentMethod,
		string $validFrom,
		?string $validTo,
		?int $minMonthlyAmountOverrideCents,
		?string $overrideReason,
		string $actorType,
		?string $actorUid,
		?string $onBehalfNote = null,
	): Assignment {
		$group = $this->groupMapper->find($groupId);
		$this->assertValidInterval($group, $intervalMonths);
		$this->assertNotInPast($validFrom);
		if ($validTo !== null && $validTo < $validFrom) {
			throw new \InvalidArgumentException($this->l10n->t('Ende der Zuweisung darf nicht vor ihrem Beginn liegen.'));
		}
		$this->assertValidPaymentMethod($paymentMethod);
		$effectiveMin = $minMonthlyAmountOverrideCents ?? $group->getMinMonthlyAmountCents();
		if ($monthlyAmountCents < $effectiveMin) {
			throw new \InvalidArgumentException($this->l10n->t('Der Monatsbeitrag darf die Untergrenze von %s € nicht unterschreiten.', [number_format($effectiveMin / 100, 2, ',', '.')]));
		}
		$this->assertNoOverlap($memberId, $groupId, $validFrom, $validTo, null);

		$assignment = new Assignment();
		$assignment->setMemberId($memberId);
		$assignment->setGroupId($groupId);
		$assignment->setIntervalMonths($intervalMonths);
		$assignment->setMonthlyAmountCents($monthlyAmountCents);
		$assignment->setMinMonthlyAmountOverrideCents($minMonthlyAmountOverrideCents);
		$assignment->setOverrideReason($overrideReason !== null && trim($overrideReason) !== '' ? trim($overrideReason) : null);
		$assignment->setPaymentMethod($paymentMethod);
		$assignment->setValidFrom($validFrom);
		$assignment->setValidTo($validTo);
		$assignment->setCreatedAt($this->now());
		$assignment = $this->mapper->insert($assignment);

		$this->logEvent($assignment->getId(), AssignmentEvent::TYPE_ASSIGNMENT_STARTED, $actorType, $actorUid, $onBehalfNote, [
			'groupId' => $groupId,
			'intervalMonths' => $intervalMonths,
			'monthlyAmountCents' => $monthlyAmountCents,
			'validFrom' => $validFrom,
		]);
		return $assignment;
	}

	/**
	 * Betrag, Turnus und/oder Gruppe ändern – die allgemeinen, künftig auch
	 * per Self-Service erreichbaren Felder (Spec §3.4 Aktionskatalog:
	 * Monatsbeitrag/Turnus „darf", individuelle Untergrenze „darf nicht" –
	 * letztere hat deshalb eine eigene Methode, {@see setMinAmountOverride()}).
	 *
	 * @throws \InvalidArgumentException bei ungültigen Werten
	 * @throws DoesNotExistException wenn es die Zuweisung/Gruppe nicht gibt
	 */
	public function update(
		int $id,
		?int $monthlyAmountCents,
		?int $intervalMonths,
		?int $groupId,
		string $actorType,
		?string $actorUid,
		?string $onBehalfNote = null,
	): Assignment {
		$assignment = $this->mapper->find($id);
		$group = $this->groupMapper->find($groupId ?? $assignment->getGroupId());
		$effectiveFrom = $this->effectiveFromFor($assignment);

		if ($groupId !== null && $groupId !== $assignment->getGroupId()) {
			$this->assertNoOverlap($assignment->getMemberId(), $groupId, $effectiveFrom, $assignment->getValidTo(), $assignment->getId());
			$this->logEvent($id, AssignmentEvent::TYPE_GROUP_CHANGED, $actorType, $actorUid, $onBehalfNote, [
				'from' => $assignment->getGroupId(), 'to' => $groupId, 'effectiveFrom' => $effectiveFrom,
			]);
			$assignment->setGroupId($groupId);
		}

		if ($intervalMonths !== null && $intervalMonths !== $assignment->getIntervalMonths()) {
			$this->assertValidInterval($group, $intervalMonths);
			$this->logEvent($id, AssignmentEvent::TYPE_INTERVAL_CHANGED, $actorType, $actorUid, $onBehalfNote, [
				'from' => $assignment->getIntervalMonths(), 'to' => $intervalMonths, 'effectiveFrom' => $effectiveFrom,
			]);
			$assignment->setIntervalMonths($intervalMonths);
		}

		if ($monthlyAmountCents !== null && $monthlyAmountCents !== $assignment->getMonthlyAmountCents()) {
			$effectiveMin = $assignment->effectiveMinMonthlyAmountCents($group);
			if ($monthlyAmountCents < $effectiveMin) {
				throw new \InvalidArgumentException($this->l10n->t('Der Monatsbeitrag darf die Untergrenze von %s € nicht unterschreiten.', [number_format($effectiveMin / 100, 2, ',', '.')]));
			}
			$this->logEvent($id, AssignmentEvent::TYPE_AMOUNT_CHANGED, $actorType, $actorUid, $onBehalfNote, [
				'from' => $assignment->getMonthlyAmountCents(), 'to' => $monthlyAmountCents, 'effectiveFrom' => $effectiveFrom,
			]);
			$assignment->setMonthlyAmountCents($monthlyAmountCents);
		}

		return $this->mapper->update($assignment);
	}

	/**
	 * Individuelle Untergrenze setzen/ändern/entfernen – nur `buchhalter`
	 * (Gate am Controller, siehe AssignmentController). Ersetzt die
	 * Gruppen-Untergrenze in beide Richtungen; unterschreitet der aktuelle
	 * Monatsbeitrag die neue Untergrenze, wird er analog zur
	 * Untergrenzen-Erhöhung (ContributionGroupService) automatisch mit
	 * angehoben statt einen inkonsistenten Zustand stehen zu lassen.
	 *
	 * @throws DoesNotExistException wenn es die Zuweisung nicht gibt
	 */
	public function setMinAmountOverride(
		int $id,
		?int $overrideCents,
		?string $reason,
		string $actorType,
		?string $actorUid,
		?string $onBehalfNote = null,
	): Assignment {
		$assignment = $this->mapper->find($id);
		$this->logEvent($id, AssignmentEvent::TYPE_MIN_AMOUNT_OVERRIDE_SET, $actorType, $actorUid, $onBehalfNote, [
			'from' => $assignment->getMinMonthlyAmountOverrideCents(), 'to' => $overrideCents,
		]);
		$assignment->setMinMonthlyAmountOverrideCents($overrideCents);
		$assignment->setOverrideReason($reason !== null && trim($reason) !== '' ? trim($reason) : null);

		if ($overrideCents !== null && $assignment->getMonthlyAmountCents() < $overrideCents) {
			$this->logEvent($id, AssignmentEvent::TYPE_AMOUNT_CHANGED, $actorType, $actorUid, $onBehalfNote, [
				'from' => $assignment->getMonthlyAmountCents(), 'to' => $overrideCents, 'reason' => 'min_amount_override',
			]);
			$assignment->setMonthlyAmountCents($overrideCents);
		}
		return $this->mapper->update($assignment);
	}

	/**
	 * Zuweisung beenden (manuell, z.B. Sparte gewechselt) – rückt `validTo`
	 * nur vor, verlängert es nie über einen bereits gesetzten Wert hinaus.
	 *
	 * @throws \InvalidArgumentException wenn $validTo vor dem Beginn läge
	 * @throws DoesNotExistException wenn es die Zuweisung nicht gibt
	 */
	public function end(int $id, string $validTo, string $actorType, ?string $actorUid, ?string $onBehalfNote = null): Assignment {
		$assignment = $this->mapper->find($id);
		if ($validTo < $assignment->getValidFrom()) {
			throw new \InvalidArgumentException($this->l10n->t('Ende der Zuweisung darf nicht vor ihrem Beginn liegen.'));
		}
		if ($assignment->getValidTo() !== null && $assignment->getValidTo() <= $validTo) {
			return $assignment; // bereits (frueher oder gleich) beendet - nichts zu tun
		}
		$assignment->setValidTo($validTo);
		$this->logEvent($id, AssignmentEvent::TYPE_ASSIGNMENT_ENDED, $actorType, $actorUid, $onBehalfNote, ['validTo' => $validTo]);
		return $this->mapper->update($assignment);
	}

	/**
	 * Austritts-Hook (Spec §3.1/Issue #68 AK 8): beendet automatisch alle noch
	 * offenen Zuweisungen eines Mitglieds zum Austrittsdatum. Aufgerufen vom
	 * {@see \OCA\Vereinsbuchhaltung\BackgroundJob\MemberDepartureJob} und –
	 * sobald es sie gibt – direkt vom künftigen MemberService (#65), damit die
	 * Wirkung nicht erst mit dem nächsten Tageslauf sichtbar wird.
	 *
	 * @return int Anzahl beendeter Zuweisungen
	 */
	public function onMemberLeft(int $memberId, string $leftAt): int {
		$count = 0;
		foreach ($this->mapper->findOpenAsOf($memberId, $leftAt) as $assignment) {
			$assignment->setValidTo($leftAt);
			$this->mapper->update($assignment);
			$this->logEvent($assignment->getId(), AssignmentEvent::TYPE_ASSIGNMENT_ENDED, AssignmentEvent::ACTOR_SYSTEM, null, null, [
				'validTo' => $leftAt, 'reason' => 'member_left',
			]);
			$count++;
		}
		return $count;
	}

	/**
	 * Untergrenzen-Erhöhung anwenden (ContributionGroupService::
	 * applyMinAmountIncrease() ruft dies je betroffener Zuweisung auf) – hebt
	 * den Monatsbeitrag exakt auf die neue Untergrenze, nie darüber hinaus.
	 * Zuweisungen mit individueller Untergrenze sind hier nie betroffen (siehe
	 * ContributionGroupService::previewMinAmountIncrease()).
	 */
	public function raiseToMinimum(Assignment $assignment, int $newMinCents, string $actorUid): void {
		$old = $assignment->getMonthlyAmountCents();
		$assignment->setMonthlyAmountCents($newMinCents);
		$this->mapper->update($assignment);
		$this->logEvent($assignment->getId(), AssignmentEvent::TYPE_AMOUNT_CHANGED, AssignmentEvent::ACTOR_STAFF, $actorUid, null, [
			'from' => $old, 'to' => $newMinCents, 'reason' => 'min_amount_increase',
		]);
	}

	/**
	 * Vorschau der ersten (typischerweise anteiligen) Periode einer Zuweisung
	 * – Prorata-Mathematik (Spec §3.3): angebrochene Monate zählen an beiden
	 * Enden voll, Einzugsbetrag = Monatsbeitrag × Turnusmonate.
	 *
	 * @return array{periodStart:string, periodEnd:string, months:int, amountCents:int}
	 */
	public function previewFirstPeriod(Assignment $assignment): array {
		[$periodStart, $periodEnd] = $this->contributionYear->periodContaining($assignment->getIntervalMonths(), $assignment->getValidFrom());
		$from = max($periodStart, $assignment->getValidFrom());
		$months = ProrataCalculator::monthsSpanned($from, $periodEnd);
		return [
			'periodStart' => $periodStart,
			'periodEnd' => $periodEnd,
			'months' => $months,
			'amountCents' => ProrataCalculator::amountCents($assignment->getMonthlyAmountCents(), $from, $periodEnd),
		];
	}

	/** @return AssignmentEvent[] */
	public function findEvents(int $assignmentId): array {
		return $this->eventMapper->findByAssignment($assignmentId);
	}

	/**
	 * Der früheste laut Wirksamkeitsregel zulässige Stichtag für eine
	 * Änderung an dieser Zuweisung, ausgehend von heute (siehe
	 * {@see EffectivityRuleService}). Solange keine Forderung dieser
	 * Zuweisung je vorabinformiert wurde – in Issue #68 immer der Fall,
	 * `prenotified_at` wird erst in Ticket #70 gesetzt –, ist das schlicht
	 * heute.
	 */
	private function effectiveFromFor(Assignment $assignment): string {
		$periods = array_map(
			static fn ($item) => [
				'periodStart' => (string)$item->getPeriodStart(),
				'periodEnd' => (string)$item->getPeriodEnd(),
				'prenotifiedAt' => $item->getPrenotifiedAt(),
			],
			array_values(array_filter(
				$this->openItemMapper->findByAssignment($assignment->getId()),
				static fn ($item) => $item->getPeriodStart() !== null && $item->getPeriodEnd() !== null,
			)),
		);
		return EffectivityRuleService::firstEffectiveDate($periods, $this->today());
	}

	private function assertValidInterval(ContributionGroup $group, int $intervalMonths): void {
		if (!in_array($intervalMonths, $group->getAllowedIntervalsArray(), true)) {
			throw new \InvalidArgumentException($this->l10n->t('Dieser Turnus ist für die gewählte Beitragsgruppe nicht erlaubt.'));
		}
	}

	private function assertValidPaymentMethod(string $paymentMethod): void {
		if (!in_array($paymentMethod, Assignment::PAYMENT_METHODS, true)) {
			throw new \InvalidArgumentException($this->l10n->t('Unbekannte Zahlungsart.'));
		}
	}

	/** „valid_from (nie in der Vergangenheit)" – Spec §2.2/§3.1. */
	private function assertNotInPast(string $validFrom): void {
		if ($validFrom < $this->today()) {
			throw new \InvalidArgumentException($this->l10n->t('Der Beginn einer Zuweisung darf nicht in der Vergangenheit liegen.'));
		}
	}

	/**
	 * „keine zeitlich überlappende Doppelzuweisung zur selben Gruppe" – zwei
	 * Zeiträume mit NULL als „unbegrenzt" überlappen, wenn keiner ganz vor dem
	 * anderen endet.
	 *
	 * @throws \InvalidArgumentException bei Überlappung
	 */
	private function assertNoOverlap(int $memberId, int $groupId, string $validFrom, ?string $validTo, ?int $excludeId): void {
		$newEnd = $validTo ?? '9999-12-31';
		foreach ($this->mapper->findByMemberAndGroup($memberId, $groupId) as $existing) {
			if ($existing->getId() === $excludeId) {
				continue;
			}
			$existingEnd = $existing->getValidTo() ?? '9999-12-31';
			if ($existing->getValidFrom() <= $newEnd && $validFrom <= $existingEnd) {
				throw new \InvalidArgumentException($this->l10n->t('Das Mitglied hat für diesen Zeitraum bereits eine Zuweisung zu dieser Beitragsgruppe.'));
			}
		}
	}

	private function logEvent(int $assignmentId, string $type, string $actorType, ?string $actorUid, ?string $onBehalfNote, array $details): void {
		$event = new AssignmentEvent();
		$event->setAssignmentId($assignmentId);
		$event->setType($type);
		$event->setActorType($actorType);
		$event->setActorUid($actorUid);
		$event->setOnBehalfNote($onBehalfNote !== null && trim($onBehalfNote) !== '' ? trim($onBehalfNote) : null);
		$event->setDetailsArray($details);
		$event->setCreatedAt($this->now());
		$this->eventMapper->insert($event);
	}

	private function today(): string {
		return $this->time->getDateTime()->format('Y-m-d');
	}

	private function now(): string {
		return $this->time->getDateTime()->format(\DateTime::ATOM);
	}
}
