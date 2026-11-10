<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Report\BudgetStatus;
use OCA\Vereinsbuchhaltung\Service\Report\VisualReportBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Die Daten der visuellen Gliederungsansicht: Zeilen, Kennzahlen, Verteilung, Abweichungen.
 */
class VisualReportBuilderTest extends TestCase {

	private const TOTALS = [
		'income' => ['plan' => 200000, 'actual' => 90000],
		'expense' => ['plan' => 100000, 'actual' => 60000],
	];

	/**
	 * @return array{id:int, type:string, number:string, name:string, plan:int, actual:int}
	 */
	private static function account(int $id, string $type, int $plan, int $actual): array {
		return ['id' => $id, 'type' => $type, 'number' => (string)(1000 + $id), 'name' => 'Konto ' . $id, 'plan' => $plan, 'actual' => $actual];
	}

	/**
	 * @param list<array<string, mixed>> $planLevel
	 * @return array<string, mixed>
	 */
	private static function build(array $planLevel, int $elapsed = 6, array $rows = []): array {
		return VisualReportBuilder::build($rows, $planLevel, self::TOTALS, $elapsed, 12, BudgetStatus::DEFAULTS);
	}

	public function testRowsDropNotesAndReplaceValues(): void {
		$rows = [
			['kind' => 'heading', 'depth' => 0, 'label' => 'Ausgaben'],
			['kind' => 'account', 'depth' => 1, 'label' => 'Miete', 'nature' => 'expense', 'accountId' => 7, 'number' => '4000', 'values' => [100000, 60000]],
			['kind' => 'note', 'depth' => 0, 'label' => 'Hinweis'],
			['kind' => 'footer', 'depth' => 0, 'label' => 'Ergebnis', 'nature' => 'result', 'values' => [100000, null]],
		];
		$out = self::build([], 6, $rows)['rows'];

		$this->assertCount(3, $out);
		$this->assertSame($rows[0], $out[0]);
		$this->assertArrayNotHasKey('values', $out[1]);
		$this->assertSame(100000, $out[1]['plan']);
		$this->assertSame(60000, $out[1]['actual']);
		$this->assertSame('Miete', $out[1]['label']);
		$this->assertSame(7, $out[1]['accountId']);
		$this->assertSame('good', $out[1]['status']['year']['level']);
		$this->assertSame('warn', $out[1]['status']['time']['level']);
		$this->assertSame(0, $out[2]['actual']);
	}

	public function testKpis(): void {
		$kpis = self::build([])['kpis'];

		$this->assertSame(180000, $kpis['income']['forecast']);
		$this->assertSame(100000, $kpis['result']['plan']);
		$this->assertSame(30000, $kpis['result']['actual']);
		$this->assertSame(60000, $kpis['result']['forecast']);
		$this->assertSame('crit', $kpis['result']['status']['year']['level']);
		$this->assertSame('crit', $kpis['result']['status']['time']['level']);
	}

	public function testDistribution(): void {
		$planLevel = [
			self::account(1, 'expense', 100000, 10000),
			self::account(2, 'expense', 100000, 130000),
			self::account(3, 'expense', 0, 0),
			self::account(4, 'expense', 0, 5000),
		];
		$dist = self::build($planLevel)['distribution'];

		$this->assertSame(['good' => 1, 'warn' => 0, 'crit' => 1, 'unplanned' => 1], $dist['year']['expense']);
		$this->assertSame(['good' => 0, 'warn' => 0, 'crit' => 0, 'unplanned' => 0], $dist['year']['income']);
		$this->assertSame(['good', 'warn', 'crit', 'unplanned'], array_keys($dist['time']['income']));
	}

	public function testDuplicateAccountRowsCountOnce(): void {
		$row = ['kind' => 'account', 'depth' => 1, 'label' => 'Miete', 'nature' => 'expense', 'accountId' => 1, 'values' => [100000, 10000]];
		$dist = self::build([self::account(1, 'expense', 100000, 10000)], 6, [$row, $row])['distribution'];

		$this->assertSame(1, $dist['year']['expense']['good']);
	}

	public function testDeviations(): void {
		$planLevel = [];
		for ($i = 1; $i <= 9; $i++) {
			$planLevel[] = self::account($i, 'expense', 10000, 10000 + $i * 1000);
		}
		$planLevel[] = self::account(10, 'expense', 10000, 10040);
		$planLevel[] = self::account(11, 'expense', 0, 500);
		$planLevel[] = self::account(12, 'income', 0, 9000);
		$dev = self::build($planLevel)['deviations'];

		$list = $dev['year']['expense'];
		$this->assertCount(7, $list);
		$amounts = array_column($list, 'amount');
		$sorted = $amounts;
		rsort($sorted);
		$this->assertSame($sorted, $amounts);
		$this->assertSame(9000, $list[0]['amount']);
		$this->assertSame(9, $list[0]['accountId']);
		$this->assertNotContains(10, array_column($list, 'accountId'));
		$this->assertSame('crit', $list[0]['level']);
		$this->assertSame([], $dev['year']['income']);

		$unplanned = self::build([self::account(11, 'expense', 0, 500)])['deviations']['year']['expense'];
		$this->assertSame(500, $unplanned[0]['amount']);
		$this->assertSame('unplanned', $unplanned[0]['level']);
	}

	public function testIncomeShortfall(): void {
		$dev = self::build([self::account(1, 'income', 100000, 40000)])['deviations'];

		$this->assertSame(60000, $dev['year']['income'][0]['amount']);
		$this->assertSame(10000, $dev['time']['income'][0]['amount']);
	}

	public function testNoElapsedMonths(): void {
		$out = self::build([self::account(1, 'expense', 100000, 130000)], 0, [
			['kind' => 'account', 'depth' => 1, 'label' => 'Miete', 'nature' => 'expense', 'accountId' => 1, 'values' => [100000, 130000]],
		]);

		$this->assertSame('none', $out['rows'][0]['status']['time']['level']);
		$this->assertNull($out['kpis']['income']['forecast']);
		$this->assertNull($out['kpis']['expense']['forecast']);
		$this->assertNull($out['kpis']['result']['forecast']);
		$this->assertSame([], $out['deviations']['time']['expense']);
		$this->assertSame([], $out['deviations']['time']['income']);
		$this->assertCount(1, $out['deviations']['year']['expense']);
	}
}
