<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Sepa;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\ContributionGroupMapper;
use OCA\Vereinsbuchhaltung\Db\LineAllocationMapper;
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

	/** @var array<int, list<int>> Buchung => Kostenstellen, die sie trägt */
	private array $byJournal = [];

	public function __construct(
		private AssignmentMapper $assignments,
		private ContributionGroupMapper $groups,
		private CostCenterDefaults $defaults,
		private ?LineAllocationMapper $allocations = null,
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

	/**
	 * Kostenstelle für die Rückgabe eines Einzugs: die Vorgabe der Gruppe –
	 * aber nur, wenn die Buchung des Einzugs sie auch trug. Wurde der Einzug
	 * ohne Kostenstelle verbucht (Modul damals aus, Gruppe noch ohne Vorgabe)
	 * oder ist die Vorgabe inzwischen eine andere, bliebe sonst ein Minus auf
	 * einer Kostenstelle stehen, die den Erlös nie bekommen hat.
	 */
	public function forReturn(OpenItem $item): ?int {
		$costCenterId = $this->forOpenItem($item);
		$journalId = $item->getPaidJournalId();
		if ($costCenterId === null || $journalId === null || $this->allocations === null) {
			return null;
		}
		if (!isset($this->byJournal[$journalId])) {
			$ids = [];
			foreach ($this->allocations->findByJournal($journalId) as $lineAllocations) {
				foreach ($lineAllocations as $allocation) {
					$ids[] = $allocation['costCenterId'];
				}
			}
			$this->byJournal[$journalId] = array_values(array_unique($ids));
		}
		return in_array($costCenterId, $this->byJournal[$journalId], true) ? $costCenterId : null;
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
