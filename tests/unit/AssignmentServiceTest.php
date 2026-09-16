<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentEvent;
use OCA\Vereinsbuchhaltung\Db\AssignmentEventMapper;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\ContributionGroup;
use OCA\Vereinsbuchhaltung\Db\ContributionGroupMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Service\AssignmentService;
use OCA\Vereinsbuchhaltung\Service\ContributionYearService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Die fachlichen Regeln um eine Zuweisung herum (Spec §2.2/§3.1/§3.3):
 * Turnus muss erlaubt sein, Beginn nie in der Vergangenheit, Untergrenze
 * (Gruppe oder individueller Override), keine Überlappung zur selben Gruppe,
 * und der Austritts-Hook beendet offene Zuweisungen.
 */
class AssignmentServiceTest extends TestCase {

	private AssignmentMapper&MockObject $mapper;
	private AssignmentEventMapper&MockObject $eventMapper;
	private ContributionGroupMapper&MockObject $groupMapper;
	private OpenItemMapper&MockObject $openItemMapper;

	protected function setUp(): void {
		$this->mapper = $this->createMock(AssignmentMapper::class);
		$this->eventMapper = $this->createMock(AssignmentEventMapper::class);
		$this->groupMapper = $this->createMock(ContributionGroupMapper::class);
		$this->openItemMapper = $this->createMock(OpenItemMapper::class);
		$this->openItemMapper->method('findByAssignment')->willReturn([]);
	}

	private function group(int $minCents = 500, array $allowedIntervals = [1, 12]): ContributionGroup {
		$group = new ContributionGroup();
		$group->setId(1);
		$group->setMinMonthlyAmountCents($minCents);
		$group->setAllowedIntervalsArray($allowedIntervals);
		return $group;
	}

	/**
	 * insert() bekommt in echt eine Entität ohne ID und liefert sie mit
	 * vergebener ID zurück (AUTO_INCREMENT) - AssignmentEventMapper::insert()
	 * (logEvent()) braucht danach eine echte assignmentId.
	 */
	private function stubInsertAssignsId(int $id): callable {
		return static function (Assignment $a) use ($id): Assignment {
			$a->setId($id);
			return $a;
		};
	}

	private function service(string $today = '2026-06-15'): AssignmentService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime($today));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		return new AssignmentService($this->mapper, $this->eventMapper, $this->groupMapper, $this->openItemMapper, new ContributionYearService($this->createMock(\OCP\IConfig::class)), $time, $l10n);
	}

	public function testCreateLegtDieZuweisungAnUndLogtEinEreignis(): void {
		$this->groupMapper->method('find')->with(1)->willReturn($this->group());
		$this->mapper->method('findByMemberAndGroup')->willReturn([]);
		$this->mapper->method('insert')->willReturnCallback($this->stubInsertAssignsId(101));
		$this->eventMapper->expects($this->once())->method('insert')
			->with($this->callback(fn (AssignmentEvent $e) => $e->getType() === AssignmentEvent::TYPE_ASSIGNMENT_STARTED));

		$assignment = $this->service()->create(7, 1, 12, 800, Assignment::PAYMENT_METHOD_DIRECT_DEBIT, '2026-06-15', null, null, null, AssignmentEvent::ACTOR_STAFF, 'kassenwart');

		$this->assertSame(7, $assignment->getMemberId());
		$this->assertSame(800, $assignment->getMonthlyAmountCents());
	}

	public function testCreateLehntNichtErlaubtenTurnusAb(): void {
		$this->groupMapper->method('find')->willReturn($this->group(500, [1, 12]));
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->create(7, 1, 3, 800, Assignment::PAYMENT_METHOD_DIRECT_DEBIT, '2026-06-15', null, null, null, 'staff', 'kassenwart');
	}

	public function testCreateLehntBetragUnterDerGruppenUntergrenzeAb(): void {
		$this->groupMapper->method('find')->willReturn($this->group(500));
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->create(7, 1, 12, 400, Assignment::PAYMENT_METHOD_DIRECT_DEBIT, '2026-06-15', null, null, null, 'staff', 'kassenwart');
	}

	public function testCreateErlaubtBetragUnterGruppenUntergrenzeMitPassendemOverride(): void {
		$this->groupMapper->method('find')->willReturn($this->group(500));
		$this->mapper->method('findByMemberAndGroup')->willReturn([]);
		$this->mapper->method('insert')->willReturnCallback($this->stubInsertAssignsId(102));
		$assignment = $this->service()->create(7, 1, 12, 300, Assignment::PAYMENT_METHOD_DIRECT_DEBIT, '2026-06-15', null, 200, 'Sozialermäßigung', 'staff', 'kassenwart');
		$this->assertSame(300, $assignment->getMonthlyAmountCents());
	}

	public function testCreateLehntBeginnInDerVergangenheitAb(): void {
		$this->groupMapper->method('find')->willReturn($this->group());
		$this->expectException(\InvalidArgumentException::class);
		$this->service('2026-06-15')->create(7, 1, 12, 800, Assignment::PAYMENT_METHOD_DIRECT_DEBIT, '2026-06-01', null, null, null, 'staff', 'kassenwart');
	}

	public function testCreateLehntUeberlappendeZuweisungZurSelbenGruppeAb(): void {
		$this->groupMapper->method('find')->willReturn($this->group());
		$existing = new Assignment();
		$existing->setId(99);
		$existing->setValidFrom('2026-01-01');
		$existing->setValidTo(null);
		$this->mapper->method('findByMemberAndGroup')->willReturn([$existing]);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->create(7, 1, 12, 800, Assignment::PAYMENT_METHOD_DIRECT_DEBIT, '2026-06-15', null, null, null, 'staff', 'kassenwart');
	}

	public function testCreateErlaubtNichtUeberlappendeZuweisungZurSelbenGruppe(): void {
		$this->groupMapper->method('find')->willReturn($this->group());
		$existing = new Assignment();
		$existing->setId(99);
		$existing->setValidFrom('2025-01-01');
		$existing->setValidTo('2026-05-31');
		$this->mapper->method('findByMemberAndGroup')->willReturn([$existing]);
		$this->mapper->method('insert')->willReturnCallback($this->stubInsertAssignsId(103));

		$assignment = $this->service()->create(7, 1, 12, 800, Assignment::PAYMENT_METHOD_DIRECT_DEBIT, '2026-06-15', null, null, null, 'staff', 'kassenwart');
		$this->assertSame(7, $assignment->getMemberId());
	}

	public function testSetMinAmountOverrideHebtZuNiedrigenBetragAn(): void {
		$assignment = new Assignment();
		$assignment->setId(5);
		$assignment->setMonthlyAmountCents(300);
		$this->mapper->method('find')->with(5)->willReturn($assignment);
		$this->mapper->method('update')->willReturnArgument(0);

		$updated = $this->service()->setMinAmountOverride(5, 400, 'Härtefall', 'staff', 'kassenwart');

		$this->assertSame(400, $updated->getMonthlyAmountCents());
		$this->assertSame(400, $updated->getMinMonthlyAmountOverrideCents());
	}

	public function testSetMinAmountOverrideRuehrtHoehereBetraegeNichtAn(): void {
		$assignment = new Assignment();
		$assignment->setId(5);
		$assignment->setMonthlyAmountCents(1000);
		$this->mapper->method('find')->with(5)->willReturn($assignment);
		$this->mapper->method('update')->willReturnArgument(0);

		$updated = $this->service()->setMinAmountOverride(5, 400, null, 'staff', 'kassenwart');

		$this->assertSame(1000, $updated->getMonthlyAmountCents());
	}

	public function testOnMemberLeftBeendetOffeneZuweisungenZumAustrittsdatum(): void {
		$open = new Assignment();
		$open->setId(3);
		$open->setMemberId(7);
		$open->setValidFrom('2020-01-01');
		$open->setValidTo(null);
		$this->mapper->method('findOpenAsOf')->with(7, '2026-06-01')->willReturn([$open]);
		$this->mapper->expects($this->once())->method('update')->with($this->callback(
			fn (Assignment $a) => $a->getValidTo() === '2026-06-01',
		))->willReturnArgument(0);

		$count = $this->service()->onMemberLeft(7, '2026-06-01');

		$this->assertSame(1, $count);
	}

	public function testPreviewFirstPeriodRechnetProrataUeberDasBeitragsjahr(): void {
		$assignment = new Assignment();
		$assignment->setIntervalMonths(12);
		$assignment->setMonthlyAmountCents(1000);
		$assignment->setValidFrom('2026-03-15');

		$preview = $this->service()->previewFirstPeriod($assignment);

		$this->assertSame('2026-01-01', $preview['periodStart']);
		$this->assertSame('2026-12-31', $preview['periodEnd']);
		// Maerz bis Dezember = 10 Monate, voll gezaehlt trotz Start am 15.
		$this->assertSame(10, $preview['months']);
		$this->assertSame(10000, $preview['amountCents']);
	}
}
