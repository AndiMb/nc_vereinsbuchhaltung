<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\ContributionGroup;
use OCA\Vereinsbuchhaltung\Db\ContributionGroupMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Service\CostCenter\CostCenterDefaults;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Pflege der Beitragsgruppen (Spec §2.2/§3.3 „Beitragsgruppe (Contribution
 * Group)"). Keine Historisierung an der Gruppe selbst – nur die
 * Untergrenzen-**Erhöhung** hat einen eigenen, geschützten Weg mit Vorschau
 * (Spec: „Auto-Anhebung mit Vorschau – betroffene Zuweisungen namentlich
 * alt→neu, individuelle Untergrenzen separat ausgewiesen"), weil sie
 * bestehende Zuweisungen zwingt, mitzuziehen. Eine Absenkung der Untergrenze
 * gefährdet niemanden und läuft über das normale {@see update()}.
 */
class ContributionGroupService {

	private ClubClock $clock;

	public function __construct(
		private ContributionGroupMapper $mapper,
		private AssignmentMapper $assignmentMapper,
		private MemberMapper $memberMapper,
		private AssignmentService $assignmentService,
		private ITimeFactory $time,
		private IL10N $l10n,
		?ClubClock $clock = null,
		// Vorgabe-Kostenstelle der Gruppe (Issue #333).
		private ?CostCenterDefaults $costCenterDefaults = null,
	) {
		$this->clock = $clock ?? new ClubClock($this->time);
	}

	/** @return ContributionGroup[] */
	public function findAll(): array {
		return $this->mapper->findAll();
	}

	public function find(int $id): ContributionGroup {
		return $this->mapper->find($id);
	}

	/**
	 * Untergrenze und Standardbeitrag stehen in der Betragsangabe der Gruppe
	 * (Issue #270): Monatsbeträge bei `month`, Jahresbeträge bei `year`.
	 *
	 * @param int[] $allowedIntervals
	 * @throws \InvalidArgumentException bei ungültigen Werten
	 */
	public function create(string $name, int $minAmountCents, int $defaultAmountCents, array $allowedIntervals, int $defaultInterval, bool $isActive, int|false|null $bookingCostCenterId = false, string $amountBasis = ContributionGroup::BASIS_MONTH, bool $prorate = true): ContributionGroup {
		$group = new ContributionGroup();
		$this->applyFields($group, $name, $minAmountCents, $defaultAmountCents, $allowedIntervals, $defaultInterval, $isActive, $amountBasis, $prorate);
		$this->applyCostCenter($group, $bookingCostCenterId);
		$group->setCreatedAt($this->now());
		return $this->mapper->insert($group);
	}

	/**
	 * Ändert alle Felder außer der Untergrenze in Erhöhungsrichtung – dafür
	 * siehe {@see previewMinAmountIncrease()}/{@see applyMinAmountIncrease()}.
	 * Eine Absenkung ist hier weiterhin erlaubt.
	 *
	 * @param int[] $allowedIntervals
	 * @throws \InvalidArgumentException bei ungültigen Werten oder einer
	 *                                   versuchten Erhöhung der Untergrenze
	 * @throws DoesNotExistException wenn es die Gruppe nicht gibt
	 */
	public function update(int $id, string $name, int $minAmountCents, int $defaultAmountCents, array $allowedIntervals, int $defaultInterval, bool $isActive, int|false|null $bookingCostCenterId = false, string $amountBasis = ContributionGroup::BASIS_MONTH, bool $prorate = true): ContributionGroup {
		$group = $this->mapper->find($id);
		$basisChanges = ($amountBasis === ContributionGroup::BASIS_YEAR) !== $group->isAnnual();
		if ($basisChanges) {
			// Bestehende Beträge müssten umgerechnet werden – das brächte genau die Rundungsfehler zurück,
			// die der Jahresbetrag vermeidet (Issue #270). Ohne Zuweisungen gibt es nichts umzurechnen.
			if ($this->assignmentMapper->findByGroup($id) !== []) {
				throw new \InvalidArgumentException($this->l10n->t('Die Betragsangabe (pro Monat oder pro Jahr) lässt sich nicht mehr ändern, sobald die Beitragsgruppe Zuweisungen hat.'));
			}
		} elseif ($minAmountCents > $group->minAmountCents()) {
			throw new \InvalidArgumentException($this->l10n->t('Eine Erhöhung der Untergrenze läuft über die eigene Vorschau-Funktion, nicht über das normale Speichern.'));
		}
		$this->applyFields($group, $name, $minAmountCents, $defaultAmountCents, $allowedIntervals, $defaultInterval, $isActive, $amountBasis, $prorate);
		$this->applyCostCenter($group, $bookingCostCenterId);
		return $this->mapper->update($group);
	}

	/** @throws \InvalidArgumentException wenn noch Zuweisungen an dieser Gruppe hängen */
	public function delete(int $id): void {
		$group = $this->mapper->find($id);
		if ($this->assignmentMapper->findByGroup($id) !== []) {
			throw new \InvalidArgumentException($this->l10n->t('Diese Beitragsgruppe hat noch Zuweisungen und kann nicht gelöscht werden. Stattdessen deaktivieren.'));
		}
		$this->mapper->delete($group);
	}

	/**
	 * Vorschau einer Untergrenzen-Erhöhung: welche laufenden oder künftigen Zuweisungen ohne
	 * individuelle Untergrenze müssten mit angehoben werden, welche
	 * Zuweisungen mit individueller Untergrenze bleiben unberührt.
	 *
	 * @return array{
	 *   groupId: int, oldMinAmountCents: int, newMinAmountCents: int,
	 *   affected: list<array{assignmentId:int, memberId:int, memberDisplayName:string, oldAmountCents:int, newAmountCents:int}>,
	 *   individualOverridesUnaffected: list<array{assignmentId:int, memberId:int, memberDisplayName:string, minAmountOverrideCents:int}>,
	 * }
	 * Alle Beträge stehen in der Betragsangabe der Gruppe (Issue #270);
	 * `$annual` sagt, in welcher der Aufrufer `$newMinAmountCents` meint.
	 *
	 * @throws \InvalidArgumentException wenn $newMinAmountCents keine Erhöhung ist
	 *                                   oder die Betragsangabe nicht zur Gruppe passt
	 * @throws DoesNotExistException wenn es die Gruppe nicht gibt
	 */
	public function previewMinAmountIncrease(int $id, int $newMinAmountCents, bool $annual = false): array {
		$group = $this->mapper->find($id);
		if ($annual !== $group->isAnnual()) {
			// Ein Monatswert, als Jahreswert gelesen (oder umgekehrt), läge um den Faktor 12 daneben.
			throw new \InvalidArgumentException($this->l10n->t('Die Betragsangabe passt nicht zur Beitragsgruppe. Bitte die Seite neu laden.'));
		}
		if ($newMinAmountCents <= $group->minAmountCents()) {
			throw new \InvalidArgumentException($this->l10n->t('Das ist keine Erhöhung der bisherigen Untergrenze.'));
		}

		$affected = [];
		$unaffected = [];
		$today = $this->clock->today();
		foreach ($this->assignmentMapper->findByGroup($id) as $assignment) {
			// Auch eine erst künftig beginnende Zuweisung zählt: sie würde sonst unter der neuen Untergrenze starten.
			if ($assignment->getValidTo() !== null && $assignment->getValidTo() < $today) {
				continue;
			}
			$displayName = $this->memberDisplayName($assignment->getMemberId());
			$override = $annual ? $assignment->getMinAnnualOverrideCents() : $assignment->getMinMonthlyAmountOverrideCents();
			if ($override !== null) {
				$unaffected[] = [
					'assignmentId' => $assignment->getId(),
					'memberId' => $assignment->getMemberId(),
					'memberDisplayName' => $displayName,
					'minAmountOverrideCents' => $override,
				];
				continue;
			}
			if ($assignment->amountCents() < $newMinAmountCents) {
				$affected[] = [
					'assignmentId' => $assignment->getId(),
					'memberId' => $assignment->getMemberId(),
					'memberDisplayName' => $displayName,
					'oldAmountCents' => $assignment->amountCents(),
					'newAmountCents' => $newMinAmountCents,
				];
			}
		}

		return [
			'groupId' => $id,
			'oldMinAmountCents' => $group->minAmountCents(),
			'newMinAmountCents' => $newMinAmountCents,
			'affected' => $affected,
			'individualOverridesUnaffected' => $unaffected,
		];
	}

	/**
	 * Wendet die Untergrenzen-Erhöhung an: setzt die neue Untergrenze an der
	 * Gruppe und hebt jede betroffene Zuweisung exakt auf den neuen Wert an
	 * (nie darüber hinaus). Zuweisungen mit individueller Untergrenze bleiben
	 * unangetastet.
	 *
	 * @return array{groupId:int, oldMinAmountCents:int, newMinAmountCents:int, raisedCount:int}
	 * @throws \InvalidArgumentException wenn $newMinAmountCents keine Erhöhung ist
	 * @throws DoesNotExistException wenn es die Gruppe nicht gibt
	 */
	public function applyMinAmountIncrease(int $id, int $newMinAmountCents, string $actorUid, bool $annual = false): array {
		$preview = $this->previewMinAmountIncrease($id, $newMinAmountCents, $annual);

		$group = $this->mapper->find($id);
		// Der Standardbeitrag der Gruppe darf nie unter ihrer Untergrenze liegen (applyFields() lehnt das ab): hebt die
		// Erhöhung die Untergrenze darüber, zieht er mit – sonst ließe sich die Gruppe danach nicht mehr ändern.
		$this->setAmounts($group, $annual, $newMinAmountCents, max($group->defaultAmountCents(), $newMinAmountCents));
		$this->mapper->update($group);

		foreach ($preview['affected'] as $entry) {
			$assignment = $this->assignmentMapper->find($entry['assignmentId']);
			$this->assignmentService->raiseToMinimum($assignment, $newMinAmountCents, $actorUid);
		}

		return [
			'groupId' => $id,
			'oldMinAmountCents' => $preview['oldMinAmountCents'],
			'newMinAmountCents' => $newMinAmountCents,
			'raisedCount' => count($preview['affected']),
		];
	}

	private function memberDisplayName(int $memberId): string {
		return $this->memberMapper->displayNameOr($memberId, $this->l10n->t('(unbekanntes Mitglied #%s)', [(string)$memberId]));
	}

	/** @param int[] $allowedIntervals */
	private function applyFields(ContributionGroup $group, string $name, int $minAmountCents, int $defaultAmountCents, array $allowedIntervals, int $defaultInterval, bool $isActive, string $amountBasis, bool $prorate): void {
		$name = trim($name);
		if ($name === '') {
			throw new \InvalidArgumentException($this->l10n->t('Name ist Pflicht.'));
		}
		if (!in_array($amountBasis, ContributionGroup::AMOUNT_BASES, true)) {
			throw new \InvalidArgumentException($this->l10n->t('Unbekannte Betragsangabe.'));
		}
		$annual = $amountBasis === ContributionGroup::BASIS_YEAR;
		if ($minAmountCents < 0 || $defaultAmountCents < 0) {
			throw new \InvalidArgumentException($this->l10n->t('Beträge dürfen nicht negativ sein.'));
		}
		if ($defaultAmountCents < $minAmountCents) {
			throw new \InvalidArgumentException($annual
				? $this->l10n->t('Der Standard-Jahresbeitrag darf die Untergrenze nicht unterschreiten.')
				: $this->l10n->t('Der Standard-Monatsbeitrag darf die Untergrenze nicht unterschreiten.'));
		}
		$intervals = array_values(array_unique(array_map('intval', $allowedIntervals)));
		if ($intervals === [] || array_diff($intervals, ContributionGroup::VALID_INTERVALS) !== []) {
			throw new \InvalidArgumentException($this->l10n->t('Erlaubte Turnusse müssen aus 1, 2, 3, 4, 6 oder 12 Monaten gewählt werden.'));
		}
		if (!in_array($defaultInterval, $intervals, true)) {
			throw new \InvalidArgumentException($this->l10n->t('Der Standard-Turnus muss einer der erlaubten Turnusse sein.'));
		}
		if ($annual && $intervals !== [12]) {
			// Ein Jahresbetrag lässt sich nicht ohne Rest auf kürzere Turnusse verteilen (Issue #270).
			throw new \InvalidArgumentException($this->l10n->t('Bei Beträgen pro Jahr ist nur der jährliche Turnus möglich.'));
		}

		$group->setName($name);
		$group->setAmountBasis($amountBasis);
		$group->setProrate($prorate);
		$this->setAmounts($group, $annual, $minAmountCents, $defaultAmountCents);
		$group->setAllowedIntervalsArray($intervals);
		$group->setDefaultInterval($defaultInterval);
		$group->setIsActive($isActive);
	}

	/**
	 * Untergrenze und Standardbeitrag in der Betragsangabe der Gruppe setzen.
	 * Bei Jahresgruppen tragen die Monatsspalten den gerundeten Zwölftel-Wert
	 * als Hilfswert, bei Monatsgruppen bleiben die Jahresspalten leer.
	 */
	private function setAmounts(ContributionGroup $group, bool $annual, int $minAmountCents, int $defaultAmountCents): void {
		$group->setMinAnnualAmountCents($annual ? $minAmountCents : null);
		$group->setDefaultAnnualAmountCents($annual ? $defaultAmountCents : null);
		$group->setMinMonthlyAmountCents($annual ? ContributionAmount::monthlyFromAnnual($minAmountCents) : $minAmountCents);
		$group->setDefaultMonthlyAmountCents($annual ? ContributionAmount::monthlyFromAnnual($defaultAmountCents) : $defaultAmountCents);
	}

	/**
	 * Vorgabe-Kostenstelle setzen (Issue #333). `false` heißt „nichts gesagt“
	 * (alter Client, Formular bei ausgeschaltetem Modul): der gespeicherte Wert
	 * bleibt. `null` oder 0 entfernt sie. Ein Wert wird nur bei eingeschaltetem
	 * Modul angenommen und muss eine vorhandene Kostenstelle sein; eine
	 * unveränderte Vorgabe bleibt auch dann stehen, wenn das Modul aus ist.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function applyCostCenter(ContributionGroup $group, int|false|null $costCenterId): void {
		if ($costCenterId === false) {
			return;
		}
		if ($costCenterId === null || $costCenterId <= 0) {
			$group->setBookingCcId(null);
			return;
		}
		if ($costCenterId === $group->getBookingCostCenterId()) {
			return;
		}
		$error = $this->costCenterDefaults === null ? 'disabled' : $this->costCenterDefaults->assignableError(Application::BOOK, $costCenterId);
		if ($error === 'disabled') {
			throw new \InvalidArgumentException($this->l10n->t('Kostenstellen sind in den Einstellungen ausgeschaltet.'));
		}
		if ($error !== null) {
			throw new \InvalidArgumentException($this->l10n->t('Kostenstelle nicht gefunden'));
		}
		$group->setBookingCcId($costCenterId);
	}

	private function now(): string {
		return $this->time->getDateTime()->format(\DateTime::ATOM);
	}
}
