<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Sepa;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\ContributionGroupMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Service\CostCenter\CostCenterDefaults;

/**
 * Vorgabe-Kostenstelle einer Beitragsforderung (Issue #333): Forderung →
 * Zuweisung → Beitragsgruppe → Kostenstelle.
 *
 * Jedes fehlende Glied heißt „keine Kostenstelle“, nie ein Fehler – die
 * Verbuchung eines Einzugs darf an einer Vorgabe nicht scheitern. Das gilt
 * auch für eine Kostenstelle, die inaktiv ist, und für das ausgeschaltete
 * Modul ({@see CostCenterDefaults::usableIds()}).
 */
class ContributionCostCenterResolver {

	/** @var array<int, int|null> Zuweisung => Kostenstelle ihrer Gruppe, für die Dauer des Requests */
	private array $byAssignment = [];

	public function __construct(
		private AssignmentMapper $assignments,
		private ContributionGroupMapper $groups,
		private CostCenterDefaults $defaults,
	) {
	}

	public function forOpenItem(OpenItem $item): ?int {
		$assignmentId = $item->getAssignmentId();
		if ($assignmentId === null) {
			return null;
		}
		if (!array_key_exists($assignmentId, $this->byAssignment)) {
			$this->byAssignment[$assignmentId] = $this->lookup($assignmentId);
		}
		return $this->byAssignment[$assignmentId];
	}

	private function lookup(int $assignmentId): ?int {
		try {
			$group = $this->groups->find($this->assignments->find($assignmentId)->getGroupId());
		} catch (\Throwable) {
			return null;
		}
		$costCenterId = $group->getBookingCostCenterId();
		if ($costCenterId === null || !in_array($costCenterId, $this->defaults->usableIds(Application::BOOK), true)) {
			return null;
		}
		return $costCenterId;
	}
}
