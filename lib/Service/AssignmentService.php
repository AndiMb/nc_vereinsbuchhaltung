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
 *
 * {@see previewFirstPeriod()} zeigt seit Issue #69 zusätzlich den
 * vorgeschlagenen Einzugstermin der Prorata-Erstforderung (Spec §3.1 „Schritt
 * 3 … schlägt den Einzugstermin vor") – dieselbe Vorschau bedient sowohl den
 * Aufnahme-Assistenten als auch die bestehende manuelle Zuweisungs-Erfassung
 * (AssignmentDialog.vue).
 */
class AssignmentService {

	private ClubClock $clock;

	public function __construct(
		private AssignmentMapper $mapper,
		private AssignmentEventMapper $eventMapper,
		private ContributionGroupMapper $groupMapper,
		private OpenItemMapper $openItemMapper,
		private ContributionYearService $contributionYear,
		// Erst mit Issue #70 hinzugekommen (Terminplan) – gebraucht sowohl für
		// die Einzugstermin-Vorschau in previewFirstPeriod() (Issue #69) als
		// auch für previewChange() (Issue #76).
		private DueDateScheduleService $dueDateSchedule,
		private ITimeFactory $time,
		private IL10N $l10n,
		private ClubCurrency $currency,
		?ClubClock $clock = null,
	) {
		$this->clock = $clock ?? new ClubClock($this->time);
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
	 * Bei Gruppen mit Jahresbetrag (Issue #270) zählt `$annualAmountCents`
	 * (Pflicht) samt `$minAnnualOverrideCents`; `$monthlyAmountCents` und
	 * `$minMonthlyAmountOverrideCents` bleiben dort unbeachtet. Bei
	 * Monatsgruppen ist es umgekehrt, ein Jahresbetrag wird abgelehnt.
	 *
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
		?int $annualAmountCents = null,
		?int $minAnnualOverrideCents = null,
	): Assignment {
		$group = $this->groupMapper->find($groupId);
		$annual = $group->isAnnual();
		$amountCents = $this->amountInBasis($group, $monthlyAmountCents, $annualAmountCents);
		if ($amountCents === null) {
			throw new \InvalidArgumentException($this->l10n->t('Diese Beitragsgruppe erwartet einen Jahresbetrag.'));
		}
		$overrideCents = $annual ? $minAnnualOverrideCents : $minMonthlyAmountOverrideCents;
		$this->assertValidInterval($group, $intervalMonths);
		$this->assertNotInPast($validFrom);
		if ($validTo !== null && $validTo < $validFrom) {
			throw new \InvalidArgumentException($this->l10n->t('Ende der Zuweisung darf nicht vor ihrem Beginn liegen.'));
		}
		$this->assertValidPaymentMethod($paymentMethod);
		$this->assertDirectDebitAvailable($paymentMethod, null);
		$this->assertNotBelow($annual, $amountCents, $overrideCents ?? $group->minAmountCents());
		$this->assertNoOverlap($memberId, $groupId, $validFrom, $validTo, null);

		$assignment = new Assignment();
		$assignment->setMemberId($memberId);
		$assignment->setGroupId($groupId);
		$assignment->setIntervalMonths($intervalMonths);
		$this->applyAmount($assignment, $annual, $amountCents);
		$this->applyMinOverride($assignment, $annual, $overrideCents);
		$assignment->setOverrideReason($overrideReason !== null && trim($overrideReason) !== '' ? trim($overrideReason) : null);
		$assignment->setPaymentMethod($paymentMethod);
		$assignment->setValidFrom($validFrom);
		$assignment->setValidTo($validTo);
		$assignment->setCreatedAt($this->now());
		$assignment = $this->mapper->insert($assignment);

		$this->logEvent($assignment->getId(), AssignmentEvent::TYPE_ASSIGNMENT_STARTED, $actorType, $actorUid, $onBehalfNote, [
			'groupId' => $groupId,
			'intervalMonths' => $intervalMonths,
			'monthlyAmountCents' => $assignment->getMonthlyAmountCents(),
			'validFrom' => $validFrom,
		] + ($annual ? ['annualAmountCents' => $amountCents] : []));
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
		?string $paymentMethod = null,
		?int $annualAmountCents = null,
	): Assignment {
		$assignment = $this->mapper->find($id);
		$group = $this->groupMapper->find($groupId ?? $assignment->getGroupId());
		$this->assertSameBasis($assignment, $group);
		$annual = $group->isAnnual();
		// Neuer Betrag in der Betragsangabe der Gruppe (Monats- oder Jahresbetrag), null = unverändert.
		$amountCents = $this->amountInBasis($group, $monthlyAmountCents, $annualAmountCents);
		$effectiveFrom = $this->effectiveFromFor($assignment);
		if ($paymentMethod !== null) {
			$this->assertValidPaymentMethod($paymentMethod);
			$this->assertDirectDebitAvailable($paymentMethod, $assignment->getPaymentMethod());
		}

		if ($groupId !== null && $groupId !== $assignment->getGroupId()) {
			$this->assertNoOverlap($assignment->getMemberId(), $groupId, $effectiveFrom, $assignment->getValidTo(), $assignment->getId());
			// Turnus und Betrag bleiben beim Gruppenwechsel stehen, müssen aber zur NEUEN Gruppe passen
			// (erlaubter Turnus, Untergrenze) – sonst entstünde eine Zuweisung, die die Gruppe nie zugelassen hätte.
			$this->assertValidInterval($group, $intervalMonths ?? $assignment->getIntervalMonths());
			$this->assertAtLeastMinimum($assignment, $group, $amountCents ?? $assignment->amountCents());
			$this->logEvent($id, AssignmentEvent::TYPE_GROUP_CHANGED, $actorType, $actorUid, $onBehalfNote, [
				'from' => $assignment->getGroupId(), 'to' => $groupId, 'effectiveFrom' => $effectiveFrom,
			]);
			$assignment->setGroupId($groupId);
		}

		if ($intervalMonths !== null && $intervalMonths !== $assignment->getIntervalMonths()) {
			$this->assertValidInterval($group, $intervalMonths);
			// Turnuswechsel-Sonderfall (Spec §3.4): eigene Wirksamkeitsberechnung
			// statt der generischen $effectiveFrom von oben - siehe
			// effectiveFromFor()/EffectivityRuleService::firstUnlockedDay().
			$intervalEffectiveFrom = $this->effectiveFromFor($assignment, $intervalMonths);
			$this->logEvent($id, AssignmentEvent::TYPE_INTERVAL_CHANGED, $actorType, $actorUid, $onBehalfNote, [
				'from' => $assignment->getIntervalMonths(), 'to' => $intervalMonths, 'effectiveFrom' => $intervalEffectiveFrom,
			]);
			$assignment->setIntervalMonths($intervalMonths);
		}

		if ($amountCents !== null && $amountCents !== $assignment->amountCents()) {
			$this->assertAtLeastMinimum($assignment, $group, $amountCents);
			$this->logEvent($id, AssignmentEvent::TYPE_AMOUNT_CHANGED, $actorType, $actorUid, $onBehalfNote, [
				'from' => $assignment->amountCents(), 'to' => $amountCents, 'effectiveFrom' => $effectiveFrom,
			] + self::basisDetail($annual));
			$this->applyAmount($assignment, $annual, $amountCents);
		}

		if ($paymentMethod !== null && $paymentMethod !== $assignment->getPaymentMethod()) {
			// Zahlungsart wirkt sofort und kennt kein Sperrfenster: die
			// Einzugsfähigkeit liest DirectDebitEligibilityResolver bei jedem
			// Lauf frisch von der Zuweisung – eine Überweiser-Zuweisung liefert
			// ab jetzt keine Einzugsposten mehr, offene Forderungen bleiben
			// bestehen und werden zur Überweisung angemahnt. Das Mandat selbst
			// bleibt unberührt (es wird nur nicht mehr genutzt), siehe Spec §3.5.
			$this->logEvent($id, AssignmentEvent::TYPE_PAYMENT_METHOD_CHANGED, $actorType, $actorUid, $onBehalfNote, [
				'from' => $assignment->getPaymentMethod(), 'to' => $paymentMethod,
			]);
			$assignment->setPaymentMethod($paymentMethod);
		}

		return $this->mapper->update($assignment);
	}

	/**
	 * Individuelle Untergrenze setzen/ändern/entfernen – nur `buchhalter`
	 * (Gate am Controller, siehe AssignmentController). Ersetzt die
	 * Gruppen-Untergrenze in beide Richtungen; unterschreitet der aktuelle
	 * Monatsbeitrag die neue Untergrenze, wird er analog zur
	 * Untergrenzen-Erhöhung (ContributionGroupService) automatisch mit
	 * angehoben statt einen inkonsistenten Zustand stehen zu lassen. Bei einer
	 * Zuweisung mit Jahresbetrag (Issue #270) ist `$overrideCents` ein
	 * Jahresbetrag.
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
		$annual = $assignment->isAnnual();
		$this->logEvent($id, AssignmentEvent::TYPE_MIN_AMOUNT_OVERRIDE_SET, $actorType, $actorUid, $onBehalfNote, [
			'from' => $annual ? $assignment->getMinAnnualOverrideCents() : $assignment->getMinMonthlyAmountOverrideCents(), 'to' => $overrideCents,
		] + self::basisDetail($annual));
		$this->applyMinOverride($assignment, $annual, $overrideCents);
		$assignment->setOverrideReason($reason !== null && trim($reason) !== '' ? trim($reason) : null);

		if ($overrideCents !== null && $assignment->amountCents() < $overrideCents) {
			$this->logEvent($id, AssignmentEvent::TYPE_AMOUNT_CHANGED, $actorType, $actorUid, $onBehalfNote, [
				'from' => $assignment->amountCents(), 'to' => $overrideCents, 'reason' => 'min_amount_override',
			] + self::basisDetail($annual));
			$this->applyAmount($assignment, $annual, $overrideCents);
		}
		return $this->mapper->update($assignment);
	}

	/**
	 * Zuweisung beenden (manuell, z.B. Sparte gewechselt) – rückt `validTo`
	 * nur vor, verlängert es nie über einen bereits gesetzten Wert hinaus.
	 *
	 * @throws \InvalidArgumentException wenn $validTo vor dem Beginn läge (und die Zuweisung schon begonnen hat)
	 * @throws DoesNotExistException wenn es die Zuweisung nicht gibt
	 */
	public function end(int $id, string $validTo, string $actorType, ?string $actorUid, ?string $onBehalfNote = null): Assignment {
		$assignment = $this->mapper->find($id);
		if ($validTo < $assignment->getValidFrom()) {
			if ($assignment->getValidFrom() <= $this->today()) {
				throw new \InvalidArgumentException($this->l10n->t('Ende der Zuweisung darf nicht vor ihrem Beginn liegen.'));
			}
			// Noch nicht begonnen: „beenden“ heißt zurücknehmen. Das Ende liegt dann einen Tag vor dem Beginn, die
			// Zuweisung wird nie wirksam, erzeugt nie eine Forderung und bleibt als Ereignis nachvollziehbar.
			$withdrawnTo = (new \DateTimeImmutable($assignment->getValidFrom()))->modify('-1 day')->format('Y-m-d');
			if ($assignment->getValidTo() !== null && $assignment->getValidTo() <= $withdrawnTo) {
				return $assignment;
			}
			$assignment->setValidTo($withdrawnTo);
			$this->logEvent($id, AssignmentEvent::TYPE_ASSIGNMENT_ENDED, $actorType, $actorUid, $onBehalfNote, ['validTo' => $withdrawnTo, 'reason' => 'withdrawn']);
			return $this->mapper->update($assignment);
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
		// Bei einer Zuweisung mit Jahresbetrag (Issue #270) ist $newMinCents ein Jahresbetrag.
		$annual = $assignment->isAnnual();
		$old = $assignment->amountCents();
		$this->applyAmount($assignment, $annual, $newMinCents);
		$this->mapper->update($assignment);
		$this->logEvent($assignment->getId(), AssignmentEvent::TYPE_AMOUNT_CHANGED, AssignmentEvent::ACTOR_STAFF, $actorUid, null, [
			'from' => $old, 'to' => $newMinCents, 'reason' => 'min_amount_increase',
		] + self::basisDetail($annual));
	}

	/**
	 * Vorschau der ersten (typischerweise anteiligen) Periode einer Zuweisung
	 * – Prorata-Mathematik (Spec §3.3): angebrochene Monate zählen an beiden
	 * Enden voll, Einzugsbetrag = Monatsbeitrag × Turnusmonate.
	 *
	 * `dueDate` ist der vorgeschlagene, überschreibbare Einzugstermin der
	 * Prorata-Erstforderung (Spec §3.1/§3.5, Issue #69/#70): derselbe Anker
	 * (`max(periodStart, validFrom)`), den {@see \OCA\Vereinsbuchhaltung\Service\ClaimGenerationService}
	 * für die allererste Periode einer Zuweisung verwendet – die tatsächliche
	 * Forderung entsteht weiterhin erst über den Tageslauf dort, hier ist es
	 * eine reine Vorschau vor dem Speichern.
	 *
	 * @return array{periodStart:string, periodEnd:string, months:int, amountCents:int, dueDate:string}
	 */
	public function previewFirstPeriod(Assignment $assignment): array {
		[$periodStart, $periodEnd] = $this->contributionYear->periodContaining($assignment->getIntervalMonths(), $assignment->getValidFrom());
		$from = max($periodStart, $assignment->getValidFrom());
		$months = ProrataCalculator::monthsSpanned($from, $periodEnd);
		// Ob ein angebrochenes Jahr anteilig zählt, steht an der Gruppe – nur beim Jahresbetrag von Belang (Issue #270).
		$prorate = !$assignment->isAnnual() || $this->groupMapper->find($assignment->getGroupId())->prorates();
		return [
			'periodStart' => $periodStart,
			'periodEnd' => $periodEnd,
			'months' => $months,
			'amountCents' => ContributionAmount::cents((int)$assignment->getMonthlyAmountCents(), $assignment->getAnnualAmountCents(), $prorate, $from, $periodEnd),
			'dueDate' => $this->dueDateSchedule->dueDateForPeriod($assignment->getIntervalMonths(), $from),
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
	 * Zuweisung je vorabinformiert wurde, ist das schlicht heute.
	 *
	 * @param int|null $newIntervalMonths Turnuswechsel-Sonderfall (Spec §3.4,
	 *                                    Issue #76): wechselt der Turnus, wirkt die Änderung nicht einfach ab
	 *                                    dem Tag nach der letzten gesperrten Periode (das alte Perioden-Raster
	 *                                    ist dann irrelevant), sondern "ab der ersten Periode des NEUEN Turnus,
	 *                                    die vollständig hinter der letzten eingezogenen liegt" - deshalb hier
	 *                                    ab der Sperrgrenze im NEUEN Raster weitergerechnet.
	 *                                    `null`/gleicher Wert wie bisher = generische Regel.
	 */
	private function effectiveFromFor(Assignment $assignment, ?int $newIntervalMonths = null): string {
		// Eine Zuweisung, die erst künftig beginnt, wirkt nie vor ihrem Beginn – auch wenn heute noch nichts gesperrt ist.
		return max($this->effectiveFromWithoutStart($assignment, $newIntervalMonths), $assignment->getValidFrom());
	}

	private function effectiveFromWithoutStart(Assignment $assignment, ?int $newIntervalMonths): string {
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
		$today = $this->today();

		if ($newIntervalMonths === null || $newIntervalMonths === $assignment->getIntervalMonths()) {
			return EffectivityRuleService::firstEffectiveDate($periods, $today);
		}

		$blockedUntil = EffectivityRuleService::firstUnlockedDay($periods);
		if ($blockedUntil === null) {
			return $today;
		}
		// Das Raster des NEUEN Turnus muss nicht an derselben Stelle beginnen
		// wie das alte - periodContaining() kann deshalb eine Periode liefern,
		// die VOR $blockedUntil beginnt (aber $blockedUntil noch enthält). So
		// lange weiterspringen, bis eine Periode vollständig dahinter liegt
		// ("kein Überholen", analog zum Guard in ClaimGenerationService).
		[$periodStart, $periodEnd] = $this->contributionYear->periodContaining($newIntervalMonths, $blockedUntil);
		while ($periodStart < $blockedUntil) {
			[$periodStart, $periodEnd] = $this->contributionYear->periodContaining($newIntervalMonths, PeriodRule::nextDay($periodEnd));
		}
		return max($periodStart, $today);
	}

	/**
	 * Vorschau einer Betrags-/Turnus-/Zahlungsartänderung, OHNE zu speichern (Spec §3.4
	 * Pflicht-UI: "Vorschau vor jedem Speichern - Wirkt ab … · erster Einzug
	 * am … · Betrag"). Dieselbe Validierung wie {@see update()} (Untergrenze,
	 * erlaubter Turnus), damit Vorschau und tatsächliche Änderung nie
	 * auseinanderlaufen - ruft dafür bewusst dieselben privaten
	 * Prüfmethoden auf.
	 *
	 * @return array{effectiveFrom:string, periodStart:string, periodEnd:string, firstDueDate:string, amountCents:int}
	 * @throws \InvalidArgumentException bei ungültigen Werten
	 * @throws DoesNotExistException wenn es die Zuweisung/Gruppe nicht gibt
	 */
	public function previewChange(int $id, ?int $monthlyAmountCents, ?int $intervalMonths, ?int $groupId = null, ?string $paymentMethod = null, ?int $annualAmountCents = null): array {
		$assignment = $this->mapper->find($id);
		if ($paymentMethod !== null) {
			// Nur die Gültigkeit – die Zahlungsart ändert weder Periode noch Betrag, die Vorschau soll aber
			// dieselbe Ablehnung liefern wie update() gleich.
			$this->assertValidPaymentMethod($paymentMethod);
			$this->assertDirectDebitAvailable($paymentMethod, $assignment->getPaymentMethod());
		}
		$groupChanged = $groupId !== null && $groupId !== $assignment->getGroupId();
		$group = $this->groupMapper->find($groupChanged ? $groupId : $assignment->getGroupId());
		$this->assertSameBasis($assignment, $group);
		$annual = $group->isAnnual();
		// Neuer Betrag in der Betragsangabe der Gruppe (Monats- oder Jahresbetrag), null = unverändert.
		$amountCents = $this->amountInBasis($group, $monthlyAmountCents, $annualAmountCents);

		$effectiveIntervalMonths = $intervalMonths ?? $assignment->getIntervalMonths();
		// Bei einem Gruppenwechsel zählt der Turnus auch dann, wenn er unverändert bleibt: die neue Gruppe muss ihn erlauben.
		if ($intervalMonths !== null || $groupChanged) {
			$this->assertValidInterval($group, $effectiveIntervalMonths);
		}

		$effectiveAmountCents = $amountCents ?? $assignment->amountCents();
		if ($amountCents !== null || $groupChanged) {
			$this->assertAtLeastMinimum($assignment, $group, $effectiveAmountCents);
		}

		$intervalChanged = $intervalMonths !== null && $intervalMonths !== $assignment->getIntervalMonths();
		$amountChanged = $amountCents !== null && $amountCents !== $assignment->amountCents();
		if ($intervalChanged) {
			$effectiveFrom = $this->effectiveFromFor($assignment, $intervalMonths);
		} elseif ($amountChanged || $groupChanged) {
			$effectiveFrom = $this->effectiveFromFor($assignment);
		} else {
			// Nur die Zahlungsart ändert sich (oder gar nichts): das Sperrfenster nach der Vorabinfo
			// schützt den angekündigten BETRAG, die Zahlungsart wirkt sofort – frühestens ab Beginn der
			// Zuweisung (siehe update()). Ein angekündigter Einzug entfällt dann, die Forderung bleibt
			// offen, und der tägliche Mahnlauf schickt die Zahlungsaufforderung zur Überweisung.
			$effectiveFrom = max($this->today(), $assignment->getValidFrom());
		}
		if ($groupChanged) {
			$this->assertNoOverlap($assignment->getMemberId(), $groupId, $effectiveFrom, $assignment->getValidTo(), $assignment->getId());
		}

		[$periodStart, $periodEnd] = $this->contributionYear->periodContaining($effectiveIntervalMonths, $effectiveFrom);
		$from = max($periodStart, $effectiveFrom);

		return [
			'effectiveFrom' => $effectiveFrom,
			'periodStart' => $periodStart,
			'periodEnd' => $periodEnd,
			'firstDueDate' => $this->dueDateSchedule->dueDateForPeriod($effectiveIntervalMonths, $from),
			'amountCents' => $annual
				? ContributionAmount::cents(0, $effectiveAmountCents, $group->prorates(), $from, $periodEnd)
				: ContributionAmount::cents($effectiveAmountCents, null, true, $from, $periodEnd),
		];
	}

	/** Der Beitrag (in der Betragsangabe der Gruppe) darf die wirksame Untergrenze (individuelle, sonst die der Gruppe) nicht unterschreiten. */
	private function assertAtLeastMinimum(Assignment $assignment, ContributionGroup $group, int $amountCents): void {
		$this->assertNotBelow($group->isAnnual(), $amountCents, $assignment->effectiveMinAmountCents($group));
	}

	private function assertNotBelow(bool $annual, int $amountCents, int $effectiveMin): void {
		if ($amountCents >= $effectiveMin) {
			return;
		}
		throw new \InvalidArgumentException($annual
			? $this->l10n->t('Der Jahresbeitrag darf die Untergrenze von %s nicht unterschreiten.', [$this->currency->format($effectiveMin)])
			: $this->l10n->t('Der Monatsbeitrag darf die Untergrenze von %s nicht unterschreiten.', [$this->currency->format($effectiveMin)]));
	}

	/**
	 * Der übergebene Betrag in der Betragsangabe der Gruppe (Issue #270):
	 * Jahresbetrag bei Jahresgruppen, sonst Monatsbetrag; null, wenn keiner
	 * mitkam. Ein Betrag in der jeweils anderen Angabe wird abgelehnt – als
	 * Monats- statt Jahreswert gelesen läge er um den Faktor 12 daneben.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function amountInBasis(ContributionGroup $group, ?int $monthlyAmountCents, ?int $annualAmountCents): ?int {
		if ($group->isAnnual()) {
			if ($annualAmountCents === null && $monthlyAmountCents !== null && $monthlyAmountCents !== 0) {
				throw new \InvalidArgumentException($this->l10n->t('Diese Beitragsgruppe erwartet einen Jahresbetrag.'));
			}
			return $annualAmountCents;
		}
		if ($annualAmountCents !== null) {
			throw new \InvalidArgumentException($this->l10n->t('Diese Beitragsgruppe erwartet einen Monatsbetrag.'));
		}
		return $monthlyAmountCents;
	}

	/**
	 * Eine Zuweisung bleibt bei ihrer Betragsangabe: ihr Betrag bliebe beim
	 * Wechsel in eine Gruppe der anderen Angabe stehen, hätte dort aber eine
	 * andere Bedeutung. Beenden und neu zuweisen ist der Weg.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function assertSameBasis(Assignment $assignment, ContributionGroup $group): void {
		if ($assignment->getId() !== null && $assignment->isAnnual() !== $group->isAnnual() && $assignment->getGroupId() !== $group->getId()) {
			throw new \InvalidArgumentException($this->l10n->t('Ein Wechsel zwischen Gruppen mit unterschiedlicher Betragsangabe (pro Monat und pro Jahr) ist nicht möglich. Bitte die Zuweisung beenden und neu anlegen.'));
		}
	}

	/** Betrag in der Betragsangabe setzen; bei Jahresbeträgen trägt die Monatsspalte den gerundeten Zwölftel-Wert. */
	private function applyAmount(Assignment $assignment, bool $annual, int $amountCents): void {
		$assignment->setAnnualAmountCents($annual ? $amountCents : null);
		$assignment->setMonthlyAmountCents($annual ? ContributionAmount::monthlyFromAnnual($amountCents) : $amountCents);
	}

	private function applyMinOverride(Assignment $assignment, bool $annual, ?int $overrideCents): void {
		$assignment->setMinAnnualOverrideCents($annual ? $overrideCents : null);
		$assignment->setMinMonthlyAmountOverrideCents($annual ? null : $overrideCents);
	}

	/** Kennzeichnet Ereignis-Beträge als Jahreswerte; ohne den Schlüssel sind es Monatswerte (alle Altereignisse). */
	private static function basisDetail(bool $annual): array {
		return $annual ? ['basis' => ContributionGroup::BASIS_YEAR] : [];
	}

	private function assertValidInterval(ContributionGroup $group, int $intervalMonths): void {
		if (!in_array($intervalMonths, $group->getAllowedIntervalsArray(), true)) {
			throw new \InvalidArgumentException($this->l10n->t('Dieser Turnus ist für die gewählte Beitragsgruppe nicht erlaubt.'));
		}
	}

	/**
	 * Vereinswährung (#202): Lastschrift gibt es nur in Euro. Eine bestehende
	 * Lastschrift-Zuweisung bleibt änderbar (Betrag, Turnus), solange die
	 * Zahlungsart gleich bleibt – nur der Wechsel auf Lastschrift ist gesperrt.
	 */
	private function assertDirectDebitAvailable(string $paymentMethod, ?string $current): void {
		if ($paymentMethod === Assignment::PAYMENT_METHOD_DIRECT_DEBIT && $current !== Assignment::PAYMENT_METHOD_DIRECT_DEBIT) {
			$this->currency->assertSepaAvailable($this->l10n);
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
		return $this->clock->today();
	}

	private function now(): string {
		return $this->time->getDateTime()->format(\DateTime::ATOM);
	}
}
