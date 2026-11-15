<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\Period;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Service\Report\BuiltinReportTemplates;
use OCA\Vereinsbuchhaltung\Service\Report\CostCenterResolver;
use OCA\Vereinsbuchhaltung\Service\Report\StructuredReportService;
use OCA\Vereinsbuchhaltung\Service\ReportService;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Der Gliederungsbericht mit festen Spalten und begrenztem Ist-Zeitraum.
 */
class StructuredReportServiceTest extends TestCase {

	private const USER = 'u1';

	private AccountMapper&MockObject $accountMapper;
	private BudgetMapper&MockObject $budgetMapper;
	private JournalLineMapper&MockObject $lineMapper;
	private PeriodService&MockObject $periods;
	private StructuredReportService $service;
	private Period $period;
	private IL10N $l10n;

	protected function setUp(): void {
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		$this->period = new Period();
		$this->period->setId(7);
		$this->period->setLabel('2026');
		$this->period->setStartDate('2026-01-01');
		$this->period->setEndDate('2026-12-31');

		$this->accountMapper = $this->createMock(AccountMapper::class);
		$this->accountMapper->method('findAll')->willReturn([$this->account(11, '51 01', 'expense')]);
		$this->budgetMapper = $this->createMock(BudgetMapper::class);
		$this->budgetMapper->method('findByPeriod')->willReturn([11 => ['amount' => 50_000, 'note' => '']]);
		$this->lineMapper = $this->createMock(JournalLineMapper::class);
		$this->periods = $this->createMock(PeriodService::class);
		$this->periods->method('selectedOrCurrent')->willReturn($this->period);
		$this->periods->method('previous')->willReturn(null);

		$reports = $this->createMock(ReportService::class);
		$reports->method('costCenterResolver')->willReturn(new CostCenterResolver('group', [], [], $this->l10n));

		$this->service = new StructuredReportService(
			$this->accountMapper,
			$this->budgetMapper,
			$this->lineMapper,
			$this->periods,
			$reports,
			$this->l10n,
		);
	}

	private function account(int $id, string $number, string $type): Account {
		$a = new Account();
		$a->setId($id);
		$a->setNumber($number);
		$a->setName('Konto ' . $number);
		$a->setType($type);
		$a->setActive(true);
		$a->setSphere('zweckbetrieb');
		return $a;
	}

	/** @return array{id:string, name:string, definition:array<string, mixed>} */
	private function template(): array {
		$tpl = (new BuiltinReportTemplates($this->l10n))->find(BuiltinReportTemplates::GRANTOR);
		$this->assertNotNull($tpl);
		return ['id' => 'tpl', 'name' => $tpl['name'], 'definition' => $tpl['definition']];
	}

	/** @return list<array<string, mixed>> */
	private function accountRows(array $report): array {
		return array_values(array_filter($report['rows'], static fn (array $r): bool => $r['kind'] === 'account' && $r['accountId'] === 11));
	}

	public function testBuildWithoutOverridesIsUnchanged(): void {
		$this->lineMapper->expects($this->exactly(2))->method('sumByAccount')
			->with(self::USER, '2026-01-01', '2026-12-31')
			->willReturn([11 => ['debit' => 12_300, 'credit' => 0]]);

		$plain = $this->service->build(self::USER, $this->template(), null);
		$explicit = $this->service->build(self::USER, $this->template(), null, null, null);

		$this->assertEquals($plain, $explicit);
	}

	public function testColumnOverrideReplacesTemplateColumns(): void {
		$this->lineMapper->method('sumByAccount')->willReturn([11 => ['debit' => 12_300, 'credit' => 0]]);

		$report = $this->service->build(self::USER, $this->template(), null, [
			['source' => 'plan', 'periodOffset' => 0],
			['source' => 'actual', 'periodOffset' => 0],
		]);

		$this->assertCount(2, $report['columns']);
		$this->assertSame(['plan', 'actual'], array_column($report['columns'], 'source'));
		$rows = $this->accountRows($report);
		$this->assertNotEmpty($rows);
		foreach ($report['rows'] as $row) {
			if (isset($row['values'])) {
				$this->assertCount(2, $row['values']);
			}
		}
		$this->assertSame([50_000, 12_300], $rows[0]['values']);
	}

	public function testActualUntilLimitsCurrentPeriod(): void {
		$this->lineMapper->expects($this->once())->method('sumByAccount')
			->with(self::USER, '2026-01-01', '2026-06-30')
			->willReturn([]);

		$this->service->build(self::USER, $this->template(), null, [
			['source' => 'actual', 'periodOffset' => 0],
		], '2026-06-30');
	}

	public function testActualUntilLeavesEarlierPeriodsFull(): void {
		$earlier = new Period();
		$earlier->setId(6);
		$earlier->setLabel('2025');
		$earlier->setStartDate('2025-01-01');
		$earlier->setEndDate('2025-12-31');
		$periods = $this->createMock(PeriodService::class);
		$periods->method('selectedOrCurrent')->willReturn($this->period);
		$periods->method('previous')->willReturn($earlier);
		$reports = $this->createMock(ReportService::class);
		$reports->method('costCenterResolver')->willReturn(new CostCenterResolver('group', [], [], $this->l10n));
		$service = new StructuredReportService($this->accountMapper, $this->budgetMapper, $this->lineMapper, $periods, $reports, $this->l10n);

		$ranges = [];
		$this->lineMapper->method('sumByAccount')->willReturnCallback(function (string $u, ?string $from, ?string $to) use (&$ranges): array {
			$ranges[] = [$from, $to];
			return [];
		});

		$service->build(self::USER, $this->template(), null, [
			['source' => 'actual', 'periodOffset' => -1],
			['source' => 'actual', 'periodOffset' => 0],
		], '2026-06-30');

		$this->assertContains(['2025-01-01', '2025-12-31'], $ranges);
		$this->assertContains(['2026-01-01', '2026-06-30'], $ranges);
	}

	public function testPlanActualMatchesBuild(): void {
		$this->lineMapper->method('sumByAccount')->willReturn([11 => ['debit' => 12_300, 'credit' => 0]]);

		$report = $this->service->build(self::USER, $this->template(), null, [
			['source' => 'plan', 'periodOffset' => 0],
			['source' => 'actual', 'periodOffset' => 0],
		], '2026-06-30');
		$pa = $this->service->planActual(self::USER, $this->period, '2026-06-30');

		$rows = $this->accountRows($report);
		$this->assertNotEmpty($rows);
		$this->assertSame($rows[0]['values'][0], $pa['plan'][11]);
		$this->assertSame($rows[0]['values'][1], $pa['actual'][11]);
		$this->assertCount(1, $pa['accounts']);
	}
}
