<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\BudgetOverviewService;
use OCA\Vereinsbuchhaltung\Service\BudgetTree;
use PHPUnit\Framework\TestCase;

/**
 * Der Finanzplan als Baum: Plan auf „xxx xx“, Buchungen auf „xxx xx xx“.
 *
 * Die Fälle bilden nach, woran der flache Vergleich gescheitert ist: Plan
 * auf dem Sammelkonto, Buchungen nur auf den Unterkonten. Die Beträge sind
 * ausgedacht.
 */
class BudgetTreeTest extends TestCase {

	/** @var list<array{id:int, parentId:?int, type:string, number:string}> */
	private array $accounts = [];

	/**
	 * Konto-ID je Nummer. Rein numerische Nummern („5“, „544“) werden als
	 * Array-Schlüssel zu int, daher array-key.
	 *
	 * @var array<array-key, int>
	 */
	private array $ids = [];

	private function konto(string $number, string $type = 'expense', ?string $parent = null): int {
		$id = count($this->accounts) + 1;
		$this->accounts[] = [
			'id' => $id,
			'parentId' => $parent !== null ? $this->ids[$parent] : null,
			'type' => $type,
			'number' => $number,
		];
		$this->ids[$number] = $id;
		return $id;
	}

	/**
	 * @param array<string, int> $byNumber
	 * @return array<int, int>
	 */
	private function cents(array $byNumber): array {
		$out = [];
		foreach ($byNumber as $number => $value) {
			$out[$this->ids[$number]] = $value;
		}
		return $out;
	}

	/**
	 * @param array<string, int> $plan
	 * @param array<string, int> $actual
	 * @return array<array-key, array<string, mixed>> Knoten je Kontonummer
	 */
	private function tree(array $plan, array $actual = []): array {
		$nodes = BudgetTree::annotate(
			BudgetTree::build($this->accounts, ['plan' => $this->cents($plan), 'actual' => $this->cents($actual)]),
			'plan',
			'actual',
		);
		$out = [];
		foreach ($nodes as $n) {
			$out[$n['number']] = $n;
		}
		return $out;
	}

	private function mitgliedsbeitraege(): void {
		$this->konto('5');
		$this->konto('544', 'expense', '5');
		$this->konto('544 01', 'expense', '544');
		$this->konto('544 01 01', 'expense', '544 01');
		$this->konto('544 01 02', 'expense', '544 01');
	}

	public function testSummiertIstUeberUnterkontenAufDenPlanposten(): void {
		$this->mitgliedsbeitraege();
		$t = $this->tree(['544 01' => 5000000], ['544 01 01' => 4000000, '544 01 02' => 2000000]);

		$this->assertSame(5000000, $t['544 01']['sum']['plan']);
		$this->assertSame(6000000, $t['544 01']['sum']['actual']);
		$this->assertSame(6000000, $t['5']['sum']['actual']);
		$this->assertSame(0, $t['544 01']['own']['actual']);
		$this->assertContains(BudgetTree::FLAG_OVERRUN, $t['544 01']['flags']);
	}

	public function testVorordnungUndTiefe(): void {
		$this->mitgliedsbeitraege();
		$nodes = BudgetTree::build($this->accounts, ['plan' => []]);

		$this->assertSame(['5', '544', '544 01', '544 01 01', '544 01 02'], array_column($nodes, 'number'));
		$this->assertSame([0, 1, 2, 3, 3], array_column($nodes, 'depth'));
		$this->assertSame([true, true, true, false, false], array_column($nodes, 'hasChildren'));
	}

	public function testStartansichtOeffnetBisZurPlanungsebene(): void {
		$this->mitgliedsbeitraege();
		$t = $this->tree(['544 01' => 5000000]);

		$this->assertTrue($t['5']['expanded']);
		$this->assertTrue($t['544']['expanded']);
		$this->assertFalse($t['544 01']['expanded']);

		$visible = array_column(BudgetOverviewService::visibleAtDepth(array_values($t), 'plan'), 'number');
		$this->assertSame(['5', '544', '544 01'], $visible);
		$this->assertCount(2, BudgetOverviewService::visibleAtDepth(array_values($t), '1'));
		$this->assertCount(5, BudgetOverviewService::visibleAtDepth(array_values($t), 'all'));
	}

	public function testDirektUndAufUnterkontenGebucht(): void {
		$this->konto('511 01');
		$this->konto('511 01 01', 'expense', '511 01');
		$this->konto('511 01 02', 'expense', '511 01');
		$t = $this->tree(['511 01' => 450000], ['511 01' => 280000, '511 01 01' => 30000, '511 01 02' => 150000]);

		$this->assertSame(460000, $t['511 01']['sum']['actual']);
		$this->assertSame(280000, $t['511 01']['own']['actual']);
		$this->assertContains(BudgetTree::FLAG_OVERRUN, $t['511 01']['flags']);
		$this->assertNotContains(BudgetTree::FLAG_UNPLANNED, $t['511 01 01']['flags']);
	}

	public function testNullZaehltNichtAlsPlan(): void {
		$this->konto('111', 'income');
		$this->konto('111 51', 'income', '111');
		$this->konto('111 51 2021', 'income', '111 51');
		$t = $this->tree(['111 51' => 2000000, '111 51 2021' => 0]);

		$this->assertFalse($t['111 51 2021']['isPlanItem']);
		$this->assertSame([], $t['111 51']['flags']);
		$this->assertNull(BudgetTree::blockingPlan($this->accounts, $this->cents(['111 51' => 2000000]), $this->ids['111 51']));
	}

	public function testPlanSperrtVorUndNachfahren(): void {
		$this->konto('428');
		$this->konto('428 01', 'expense', '428');
		$this->konto('428 01 03', 'expense', '428 01');
		$plan = $this->cents(['428 01' => 10000000]);

		$this->assertSame($this->ids['428 01'], BudgetTree::blockingPlan($this->accounts, $plan, $this->ids['428 01 03']));
		$this->assertSame($this->ids['428 01'], BudgetTree::blockingPlan($this->accounts, $plan, $this->ids['428']));
		$this->assertNull(BudgetTree::blockingPlan($this->accounts, $plan, $this->ids['428 01']));

		$t = $this->tree(['428 01' => 10000000]);
		$this->assertSame($this->ids['428 01'], $t['428 01 03']['planLockedBy']);
		$this->assertSame($this->ids['428 01'], $t['428']['planLockedBy']);
		$this->assertNull($t['428 01']['planLockedBy']);

		// Verglichen wird am Planposten; das Unterkonto hat keine eigene Differenz.
		$this->assertTrue($t['428 01']['comparable']);
		$this->assertFalse($t['428 01 03']['comparable']);
		$this->assertTrue($t['428']['comparable']);
	}

	public function testAltkonfliktWirdMarkiertUndAdditivGezaehlt(): void {
		$this->konto('428 01');
		$this->konto('428 01 03', 'expense', '428 01');
		$t = $this->tree(['428 01' => 100000, '428 01 03' => 30000]);

		$this->assertContains(BudgetTree::FLAG_CONFLICT, $t['428 01']['flags']);
		$this->assertContains(BudgetTree::FLAG_CONFLICT, $t['428 01 03']['flags']);
		$this->assertSame(130000, $t['428 01']['sum']['plan']);
		// Beide bleiben bearbeitbar, damit sich der Konflikt bereinigen lässt.
		$this->assertNull($t['428 01']['planLockedBy']);
		$this->assertNull($t['428 01 03']['planLockedBy']);
	}

	public function testIstOhneJedenPlan(): void {
		$this->konto('111', 'income');
		$this->konto('111 01', 'income', '111');
		$this->konto('111 54', 'income', '111');
		$t = $this->tree(['111 01' => 9000000], ['111 01' => 9100000, '111 54' => 250000]);

		$this->assertContains(BudgetTree::FLAG_UNPLANNED, $t['111 54']['flags']);
		$this->assertNotContains(BudgetTree::FLAG_UNPLANNED, $t['111 01']['flags']);
		$this->assertContains(BudgetTree::FLAG_UNPLANNED_BELOW, $t['111']['flags']);
	}

	public function testMindereinnahmenSindKeineUeberschreitung(): void {
		$this->konto('233 52', 'income');
		$t = $this->tree(['233 52' => 3000000], ['233 52' => 0]);

		$this->assertSame([], $t['233 52']['flags']);
	}

	public function testFremderTypWirdEigeneWurzel(): void {
		$this->konto('119', 'income');
		$this->konto('119 99', 'expense', '119');
		$t = $this->tree([], ['119 99' => 500]);

		$this->assertNull($t['119 99']['treeParentId']);
		$this->assertSame(0, $t['119']['sum']['actual']);
	}

	public function testNichtBudgetierbaresZwischenkontoWirdUebersprungen(): void {
		$this->konto('5');
		$this->konto('5 X', 'asset', '5');
		$this->konto('5 X 1', 'expense', '5 X');
		$t = $this->tree([], ['5 X 1' => 700]);

		$this->assertArrayNotHasKey('5 X', $t);
		$this->assertSame($this->ids['5'], $t['5 X 1']['treeParentId']);
		$this->assertSame(700, $t['5']['sum']['actual']);
	}

	public function testZyklusInAltdatenVerliertKeinKonto(): void {
		$this->accounts = [
			['id' => 1, 'parentId' => 2, 'type' => 'expense', 'number' => 'A'],
			['id' => 2, 'parentId' => 1, 'type' => 'expense', 'number' => 'B'],
		];
		$nodes = BudgetTree::build($this->accounts, ['plan' => [1 => 100, 2 => 200]]);

		$this->assertCount(2, $nodes);
		$this->assertSame(300, BudgetTree::totals($nodes, 'plan')['expense']);
	}

	public function testGesamtsummenEntsprechenDenEigenenWerten(): void {
		$this->mitgliedsbeitraege();
		$this->konto('111 01', 'income');
		$nodes = BudgetTree::build($this->accounts, ['actual' => $this->cents(['544 01' => 10, '544 01 01' => 20, '544 01 02' => 30, '111 01' => 99])]);

		$this->assertSame(['income' => 99, 'expense' => 60], BudgetTree::totals($nodes, 'actual'));
	}

	public function testPruneEntferntLeereZweige(): void {
		$this->mitgliedsbeitraege();
		$nodes = BudgetTree::prune(BudgetTree::build($this->accounts, ['plan' => $this->cents(['544 01 02' => 5])]), ['plan']);

		$this->assertSame(['5', '544', '544 01', '544 01 02'], array_column($nodes, 'number'));
		$this->assertFalse($nodes[3]['hasChildren']);
	}

	public function testVergleichsspalteWirdWiePlanUndIstSummiert(): void {
		// Issue #114: der Vorjahreswert ist eine weitere Spalte des Baums
		$this->mitgliedsbeitraege();
		$this->konto('411 01', 'income');
		$nodes = BudgetTree::build($this->accounts, [
			'plan' => $this->cents(['544 01' => 120000]),
			'compare' => $this->cents(['544 01 01' => 80000, '544 01 02' => 30000, '411 01' => 7000]),
		]);
		$t = [];
		foreach ($nodes as $n) {
			$t[$n['number']] = $n;
		}

		$this->assertSame(110000, $t['544 01']['sum']['compare']);
		$this->assertSame(110000, $t['5']['sum']['compare']);
		$this->assertSame(['income' => 7000, 'expense' => 110000], BudgetTree::totals($nodes, 'compare'));
	}
}
