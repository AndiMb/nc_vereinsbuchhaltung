<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\Period;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Service\Report\BudgetStatus;
use OCA\Vereinsbuchhaltung\Service\Report\StructuredReportService;
use OCA\Vereinsbuchhaltung\Service\Report\VisualReportService;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\ClubClockStub;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class VisualReportServiceTest extends TestCase {

	private const COLUMNS = [['source' => 'plan', 'periodOffset' => 0], ['source' => 'actual', 'periodOffset' => 0]];
	private const TEMPLATE = ['id' => 't1', 'name' => 'Vorlage', 'definition' => []];

	private StructuredReportService&MockObject $structured;
	private PeriodService&MockObject $periods;
	private Period $period;

	protected function setUp(): void {
		$this->period = new Period();
		$this->period->setId(1);
		$this->period->setLabel('2026');
		$this->period->setStartDate('2026-01-01');
		$this->period->setEndDate('2026-12-31');

		$this->structured = $this->createMock(StructuredReportService::class);
		$this->periods = $this->createMock(PeriodService::class);
		$this->periods->method('selectedOrCurrent')->willReturn($this->period);
		$this->structured->method('planActual')->willReturn(['accounts' => [], 'plan' => [], 'actual' => []]);
	}

	private function account(int $id, ?int $parent, string $type, string $number): Account {
		$a = new Account();
		$a->setId($id);
		$a->setParentId($parent);
		$a->setType($type);
		$a->setNumber($number);
		$a->setName('Konto ' . $number);
		return $a;
	}

	private function service(string $utcNow = '2026-11-10 10:00:00'): VisualReportService {
		return new VisualReportService($this->structured, $this->periods, ClubClockStub::at($utcNow));
	}

	private function report(): array {
		return ['template' => ['id' => 't1', 'name' => 'Vorlage'], 'period' => $this->period, 'columns' => [], 'rows' => [], 'warnings' => [], 'hasSpheres' => false];
	}

	public function testPassesFixedColumnsAndUntil(): void {
		$this->structured->expects($this->once())->method('build')
			->with('u', self::TEMPLATE, null, self::COLUMNS, '2026-10-31')
			->willReturn($this->report());

		$result = $this->service()->build('u', self::TEMPLATE, null, null);

		$this->assertSame('2026-10', $result['asOf']['value']);
	}

	public function testCurrentUsesPeriodEnd(): void {
		$this->structured->expects($this->once())->method('build')
			->with('u', self::TEMPLATE, null, self::COLUMNS, '2026-12-31')
			->willReturn($this->report());

		$result = $this->service()->build('u', self::TEMPLATE, null, 'current');

		$this->assertSame(11, $result['asOf']['elapsed']);
	}

	public function testPlanLevelUsesLeavesOfPlanDepth(): void {
		$this->structured = $this->createMock(StructuredReportService::class);
		$this->structured->method('build')->willReturn($this->report());
		$this->structured->method('planActual')->willReturn([
			'accounts' => [
				$this->account(1, null, 'expense', '5000'),
				$this->account(2, 1, 'expense', '5000 01'),
				$this->account(3, 1, 'expense', '5000 02'),
			],
			'plan' => [1 => 100000],
			'actual' => [2 => 30000, 3 => 20000],
		]);

		$result = $this->service()->build('u', self::TEMPLATE, null, null);

		$this->assertSame(1, array_sum($result['distribution']['year']['expense']));
		$this->assertSame(100000, $result['kpis']['expense']['plan']);
		$this->assertSame(50000, $result['kpis']['expense']['actual']);
	}

	public function testInvalidAsOfThrows(): void {
		$this->structured->expects($this->never())->method('build');
		$this->expectException(\InvalidArgumentException::class);

		$this->service()->build('u', self::TEMPLATE, null, '2026-13');
	}

	public function testThresholdsAreDefaults(): void {
		$this->structured->method('build')->willReturn($this->report());

		$result = $this->service()->build('u', self::TEMPLATE, null, null);

		$this->assertSame(BudgetStatus::DEFAULTS, $result['thresholds']);
	}
}
