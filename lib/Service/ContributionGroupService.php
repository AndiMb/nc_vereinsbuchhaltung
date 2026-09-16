<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\ContributionGroup;
use OCA\Vereinsbuchhaltung\Db\ContributionGroupMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
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

	public function __construct(
		private ContributionGroupMapper $mapper,
		private AssignmentMapper $assignmentMapper,
		private MemberMapper $memberMapper,
		private AssignmentService $assignmentService,
		private ITimeFactory $time,
		private IL10N $l10n,
	) {
	}

	/** @return ContributionGroup[] */
	public function findAll(): array {
		return $this->mapper->findAll();
	}

	public function find(int $id): ContributionGroup {
		return $this->mapper->find($id);
	}

	/**
	 * @param int[] $allowedIntervals
	 * @throws \InvalidArgumentException bei ungültigen Werten
	 */
	public function create(string $name, int $minMonthlyAmountCents, int $defaultMonthlyAmountCents, array $allowedIntervals, int $defaultInterval, bool $isActive): ContributionGroup {
		$group = new ContributionGroup();
		$this->applyFields($group, $name, $minMonthlyAmountCents, $defaultMonthlyAmountCents, $allowedIntervals, $defaultInterval, $isActive);
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
	public function update(int $id, string $name, int $minMonthlyAmountCents, int $defaultMonthlyAmountCents, array $allowedIntervals, int $defaultInterval, bool $isActive): ContributionGroup {
		$group = $this->mapper->find($id);
		if ($minMonthlyAmountCents > $group->getMinMonthlyAmountCents()) {
			throw new \InvalidArgumentException($this->l10n->t('Eine Erhöhung der Untergrenze läuft über die eigene Vorschau-Funktion, nicht über das normale Speichern.'));
		}
		$this->applyFields($group, $name, $minMonthlyAmountCents, $defaultMonthlyAmountCents, $allowedIntervals, $defaultInterval, $isActive);
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
	 * Vorschau einer Untergrenzen-Erhöhung: welche aktiven Zuweisungen ohne
	 * individuelle Untergrenze müssten mit angehoben werden, welche
	 * Zuweisungen mit individueller Untergrenze bleiben unberührt.
	 *
	 * @return array{
	 *   groupId: int, oldMinAmountCents: int, newMinAmountCents: int,
	 *   affected: list<array{assignmentId:int, memberId:int, memberDisplayName:string, oldAmountCents:int, newAmountCents:int}>,
	 *   individualOverridesUnaffected: list<array{assignmentId:int, memberId:int, memberDisplayName:string, minAmountOverrideCents:int}>,
	 * }
	 * @throws \InvalidArgumentException wenn $newMinAmountCents keine Erhöhung ist
	 * @throws DoesNotExistException wenn es die Gruppe nicht gibt
	 */
	public function previewMinAmountIncrease(int $id, int $newMinAmountCents): array {
		$group = $this->mapper->find($id);
		if ($newMinAmountCents <= $group->getMinMonthlyAmountCents()) {
			throw new \InvalidArgumentException($this->l10n->t('Das ist keine Erhöhung der bisherigen Untergrenze.'));
		}

		$affected = [];
		$unaffected = [];
		foreach ($this->assignmentMapper->findByGroup($id) as $assignment) {
			if (!$assignment->isActive()) {
				continue;
			}
			$displayName = $this->memberDisplayName($assignment->getMemberId());
			if ($assignment->getMinMonthlyAmountOverrideCents() !== null) {
				$unaffected[] = [
					'assignmentId' => $assignment->getId(),
					'memberId' => $assignment->getMemberId(),
					'memberDisplayName' => $displayName,
					'minAmountOverrideCents' => $assignment->getMinMonthlyAmountOverrideCents(),
				];
				continue;
			}
			if ($assignment->getMonthlyAmountCents() < $newMinAmountCents) {
				$affected[] = [
					'assignmentId' => $assignment->getId(),
					'memberId' => $assignment->getMemberId(),
					'memberDisplayName' => $displayName,
					'oldAmountCents' => $assignment->getMonthlyAmountCents(),
					'newAmountCents' => $newMinAmountCents,
				];
			}
		}

		return [
			'groupId' => $id,
			'oldMinAmountCents' => $group->getMinMonthlyAmountCents(),
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
	public function applyMinAmountIncrease(int $id, int $newMinAmountCents, string $actorUid): array {
		$preview = $this->previewMinAmountIncrease($id, $newMinAmountCents);

		$group = $this->mapper->find($id);
		$group->setMinMonthlyAmountCents($newMinAmountCents);
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
	private function applyFields(ContributionGroup $group, string $name, int $minMonthlyAmountCents, int $defaultMonthlyAmountCents, array $allowedIntervals, int $defaultInterval, bool $isActive): void {
		$name = trim($name);
		if ($name === '') {
			throw new \InvalidArgumentException($this->l10n->t('Name ist Pflicht.'));
		}
		if ($minMonthlyAmountCents < 0 || $defaultMonthlyAmountCents < 0) {
			throw new \InvalidArgumentException($this->l10n->t('Beträge dürfen nicht negativ sein.'));
		}
		if ($defaultMonthlyAmountCents < $minMonthlyAmountCents) {
			throw new \InvalidArgumentException($this->l10n->t('Der Standard-Monatsbeitrag darf die Untergrenze nicht unterschreiten.'));
		}
		$intervals = array_values(array_unique(array_map('intval', $allowedIntervals)));
		if ($intervals === [] || array_diff($intervals, ContributionGroup::VALID_INTERVALS) !== []) {
			throw new \InvalidArgumentException($this->l10n->t('Erlaubte Turnusse müssen aus 1, 2, 3, 4, 6 oder 12 Monaten gewählt werden.'));
		}
		if (!in_array($defaultInterval, $intervals, true)) {
			throw new \InvalidArgumentException($this->l10n->t('Der Standard-Turnus muss einer der erlaubten Turnusse sein.'));
		}

		$group->setName($name);
		$group->setMinMonthlyAmountCents($minMonthlyAmountCents);
		$group->setDefaultMonthlyAmountCents($defaultMonthlyAmountCents);
		$group->setAllowedIntervalsArray($intervals);
		$group->setDefaultInterval($defaultInterval);
		$group->setIsActive($isActive);
	}

	private function now(): string {
		return $this->time->getDateTime()->format(\DateTime::ATOM);
	}
}
