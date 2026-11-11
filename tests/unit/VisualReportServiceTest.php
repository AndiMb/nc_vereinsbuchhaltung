<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
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
	private JournalLineMapper&MockObject $lines;
	private Period $period;

	protected function setUp(): void {
		$this->period = new Period();
		$this->period->setId(1);
		$this->period->setLabel('2026');
		$this->period->setStartDate('2026-01-01');
		$this->period->setEndDate('2026-12-31');

		$this->structured = $this->createMock(StructuredReportService::class);
		$this->periods = $this->createMock(PeriodService::class);
		$this->lines = $this->createMock(JournalLineMapper::class);
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
		return new VisualReportService($this->structured, $this->periods, $this->lines, ClubClockStub::at($utcNow));
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

	public function testSubAccountsCoveredByParentPlanAreNeutral(): void {
		$row = static fn (int $id, string $number, int $plan, int $actual): array => [
			'kind' => 'account', 'depth' => 1, 'label' => 'Konto ' . $number, 'nature' => 'expense',
			'accountId' => $id, 'number' => $number, 'flags' => [], 'values' => [$plan, $actual],
		];
		$report = $this->report();
		$report['rows'] = [$row(1, '5000', 100000, 50000), $row(2, '5000 01', 0, 30000), $row(4, '6000', 0, 10000)];
		$this->structured = $this->createMock(StructuredReportService::class);
		$this->structured->method('build')->willReturn($report);
		$this->structured->method('planActual')->willReturn([
			'accounts' => [
				$this->account(1, null, 'expense', '5000'),
				$this->account(2, 1, 'expense', '5000 01'),
				$this->account(3, 1, 'expense', '5000 02'),
				$this->account(4, null, 'expense', '6000'),
			],
			'plan' => [1 => 100000],
			'actual' => [2 => 30000, 3 => 20000, 4 => 10000],
		]);

		$rows = $this->service()->build('u', self::TEMPLATE, null, null)['rows'];

		$this->assertSame([], $rows[0]['flags']);
		$this->assertSame('good', $rows[0]['status']['year']['level']);
		$this->assertSame(['covered'], $rows[1]['flags']);
		$this->assertSame('none', $rows[1]['status']['year']['level']);
		$this->assertSame('none', $rows[1]['status']['time']['level']);
		$this->assertSame([], $rows[2]['flags']);
		$this->assertSame('unplanned', $rows[2]['status']['year']['level']);
	}

	public function testSeriesIsCumulatedNetPerAccountType(): void {
		$this->structured = $this->createMock(StructuredReportService::class);
		$this->structured->method('build')->willReturn($this->report());
		$this->structured->method('planActual')->willReturn([
			'accounts' => [
				$this->account(1, null, 'income', '4000'),
				$this->account(2, null, 'expense', '5000'),
				$this->account(3, null, 'asset', '1000'),
			],
			'plan' => [1 => 100000, 2 => 80000],
			'actual' => [1 => 35000, 2 => 12000],
		]);
		$this->lines->expects($this->once())->method('sumByAccountAndMonth')
			->with('u', '2026-01-01', '2026-02-28')
			->willReturn([
				'2026-01' => [
					1 => ['debit' => 0, 'credit' => 20000],
					2 => ['debit' => 5000, 'credit' => 0],
					3 => ['debit' => 99999, 'credit' => 0],
				],
				'2026-02' => [
					1 => ['debit' => 1000, 'credit' => 16000],
					2 => ['debit' => 8000, 'credit' => 1000],
				],
			]);

		$result = $this->service()->build('u', self::TEMPLATE, null, '2026-02');

		$this->assertSame(['2026-01', '2026-02'], $result['series']['months']);
		$this->assertSame([20000, 35000], $result['series']['income']);
		$this->assertSame([5000, 12000], $result['series']['expense']);
		$this->assertSame($result['kpis']['income']['actual'], end($result['series']['income']));
		$this->assertSame($result['kpis']['expense']['actual'], end($result['series']['expense']));
	}

	public function testSeriesForCurrentEndsAtTodaysMonth(): void {
		$this->structured->method('build')->willReturn($this->report());
		$this->lines->expects($this->once())->method('sumByAccountAndMonth')
			->with('u', '2026-01-01', '2026-12-31')
			->willReturn([]);

		$series = $this->service('2026-03-10 10:00:00')->build('u', self::TEMPLATE, null, 'current')['series'];

		$this->assertSame(['2026-01', '2026-02', '2026-03'], $series['months']);
		$this->assertSame([0, 0, 0], $series['income']);
	}

	public function testSeriesIsEmptyWithoutElapsedMonths(): void {
		$this->structured->method('build')->willReturn($this->report());
		$this->lines->expects($this->never())->method('sumByAccountAndMonth');

		$series = $this->service('2025-11-10 10:00:00')->build('u', self::TEMPLATE, null, 'current')['series'];

		$this->assertSame(['months' => [], 'income' => [], 'expense' => []], $series);
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
