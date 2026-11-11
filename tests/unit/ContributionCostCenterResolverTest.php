<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\ContributionGroup;
use OCA\Vereinsbuchhaltung\Db\ContributionGroupMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Service\CostCenter\CostCenterDefaults;
use OCA\Vereinsbuchhaltung\Service\Sepa\ContributionCostCenterResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Forderung → Zuweisung → Beitragsgruppe → Vorgabe-Kostenstelle (Issue #333).
 * Jedes fehlende Glied heißt „keine Kostenstelle“, nie ein Fehler: die
 * Verbuchung eines Einzugs darf an einer Vorgabe nicht scheitern.
 */
class ContributionCostCenterResolverTest extends TestCase {

	private AssignmentMapper&MockObject $assignments;
	private ContributionGroupMapper&MockObject $groups;
	private CostCenterDefaults&MockObject $defaults;

	protected function setUp(): void {
		$this->assignments = $this->createMock(AssignmentMapper::class);
		$this->groups = $this->createMock(ContributionGroupMapper::class);
		$this->defaults = $this->createMock(CostCenterDefaults::class);
		$this->defaults->method('usableIds')->willReturn([3, 5]);
	}

	private function resolver(): ContributionCostCenterResolver {
		return new ContributionCostCenterResolver($this->assignments, $this->groups, $this->defaults);
	}

	private function item(?int $assignmentId): OpenItem {
		$item = new OpenItem();
		$item->setId(100);
		$item->setAssignmentId($assignmentId);
		return $item;
	}

	private function assignment(int $groupId): Assignment {
		$assignment = new Assignment();
		$assignment->setGroupId($groupId);
		return $assignment;
	}

	private function group(?int $costCenterId): ContributionGroup {
		$group = new ContributionGroup();
		$group->setBookingCcId($costCenterId);
		return $group;
	}

	public function testKostenstelleDerGruppe(): void {
		$this->assignments->method('find')->with(7)->willReturn($this->assignment(2));
		$this->groups->method('find')->with(2)->willReturn($this->group(3));

		$this->assertSame(3, $this->resolver()->forOpenItem($this->item(7)));
	}

	public function testOhneZuweisungNull(): void {
		$this->assignments->expects($this->never())->method('find');

		$this->assertNull($this->resolver()->forOpenItem($this->item(null)));
	}

	public function testGruppeOhneKostenstelleNull(): void {
		$this->assignments->method('find')->willReturn($this->assignment(2));
		$this->groups->method('find')->willReturn($this->group(null));

		$this->assertNull($this->resolver()->forOpenItem($this->item(7)));
	}

	/** Inaktive Kostenstelle oder ausgeschaltetes Modul: die Vorgabe greift nicht. */
	public function testNichtNutzbareKostenstelleNull(): void {
		$this->assignments->method('find')->willReturn($this->assignment(2));
		$this->groups->method('find')->willReturn($this->group(9));

		$this->assertNull($this->resolver()->forOpenItem($this->item(7)));
	}

	public function testUnbekannteZuweisungNull(): void {
		$this->assignments->method('find')->willThrowException(new DoesNotExistException('weg'));

		$this->assertNull($this->resolver()->forOpenItem($this->item(7)));
	}

	public function testUnbekannteGruppeNull(): void {
		$this->assignments->method('find')->willReturn($this->assignment(2));
		$this->groups->method('find')->willThrowException(new DoesNotExistException('weg'));

		$this->assertNull($this->resolver()->forOpenItem($this->item(7)));
	}

	/** Ein Sammeleinzug hat viele Posten derselben Zuweisung über die Monate – einmal lesen reicht. */
	public function testGleicheZuweisungWirdNurEinmalGeladen(): void {
		$this->assignments->expects($this->once())->method('find')->with(7)->willReturn($this->assignment(2));
		$this->groups->expects($this->once())->method('find')->with(2)->willReturn($this->group(5));
		$resolver = $this->resolver();

		$this->assertSame([5, 5], [$resolver->forOpenItem($this->item(7)), $resolver->forOpenItem($this->item(7))]);
	}
}
