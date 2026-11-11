<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Export\TrendSvg;
use PHPUnit\Framework\TestCase;

/**
 * Der Verlauf der visuellen Gliederungsansicht als Inline-SVG für die
 * Druckseite: kumuliertes Ist, Plan-Linien und Hochrechnung, ohne Skript.
 */
class TrendSvgTest extends TestCase {

	private static function money(int $cents): string {
		return number_format($cents / 100, 2, ',', '.') . ' X';
	}

	/** @param array{months: list<string>, income: list<int>, expense: list<int>} $series */
	private function svg(array $series, int $planIncome, int $planExpense, ?int $fi, ?int $fe, array $labels): string {
		return TrendSvg::render($series, $planIncome, $planExpense, $fi, $fe, $labels, self::money(...), ['title' => 'Verlauf', 'desc' => 'Beschreibung']);
	}

	/** @return array<string, string> Klasse der Linie => Punkte */
	private function polylines(string $svg): array {
		preg_match_all('/<polyline class="([^"]+)" points="([^"]*)"/', $svg, $m, PREG_SET_ORDER);
		$out = [];
		foreach ($m as $match) {
			$out[$match[1]] = $match[2];
		}
		return $out;
	}

	public function testZeichnetIstPlanUndHochrechnungJeArt(): void {
		$svg = $this->svg(
			['months' => ['2026-01', '2026-02'], 'income' => [100, 300], 'expense' => [50, 200]],
			1000, 800, 600, 400, ['Jan', 'Feb', 'Mär', 'Apr'],
		);

		$this->assertSame(6, substr_count($svg, '<polyline'));
		$this->assertSame(
			// Plan und Hochrechnung unten, das Ist obenauf
			['income plan', 'expense plan', 'income forecast', 'expense forecast', 'income actual', 'expense actual'],
			array_keys($this->polylines($svg)),
		);
		$this->assertStringStartsWith('<svg', $svg);
		$this->assertStringContainsString('viewBox="0 0 720 240"', $svg);
		$this->assertStringContainsString('<title>Verlauf</title>', $svg);
		$this->assertStringContainsString('<desc>Beschreibung</desc>', $svg);
		$this->assertStringNotContainsString('<script', $svg);
		$this->assertStringContainsString('stroke-dasharray', $svg);
	}

	public function testLinienartenUndFarbenWieAmBildschirm(): void {
		$svg = $this->svg(
			['months' => ['2026-01', '2026-02'], 'income' => [100, 300], 'expense' => [50, 200]],
			1000, 800, 600, 400, ['Jan', 'Feb', 'Mär', 'Apr'],
		);
		$attrs = [];
		preg_match_all('/<polyline class="([^"]+)"([^>]*)\/>/', $svg, $m, PREG_SET_ORDER);
		foreach ($m as $match) {
			preg_match('/ stroke="([^"]+)"/', $match[2], $stroke);
			preg_match('/ stroke-dasharray="([^"]+)"/', $match[2], $dash);
			$attrs[$match[1]] = [$stroke[1] ?? '', $dash[1] ?? ''];
		}

		// Ist durchgezogen, Hochrechnung gestrichelt, Plan gepunktet
		$this->assertSame('', $attrs['income actual'][1]);
		$this->assertSame('6 4', $attrs['income forecast'][1]);
		$this->assertSame('1.5 3', $attrs['income plan'][1]);
		// Einnahmen in der Akzent-, Ausgaben in der Textfarbe
		$this->assertSame(TrendSvg::INCOME_COLOR, $attrs['income actual'][0]);
		$this->assertSame(TrendSvg::EXPENSE_COLOR, $attrs['expense plan'][0]);
		$this->assertSame('#5b7a99', TrendSvg::INCOME_COLOR);
		$this->assertSame('#222222', TrendSvg::EXPENSE_COLOR);
	}

	public function testKoordinatenErsterUndLetzterPunkt(): void {
		// Zwei Monate, Plan 1000: Planlinie 500 → 1000; Skala 0 … 1000 in Schritten von 500.
		$svg = $this->svg(
			['months' => ['2026-01', '2026-02'], 'income' => [500, 1000], 'expense' => [0, 0]],
			1000, 0, null, null, ['Jan', 'Feb'],
		);
		$lines = $this->polylines($svg);

		$left = TrendSvg::LEFT;
		$right = TrendSvg::RIGHT;
		$top = TrendSvg::TOP;
		$bottom = TrendSvg::BOTTOM;
		$mid = ($top + $bottom) / 2;
		$this->assertSame("$left,$mid $right,$top", $lines['income actual']);
		$this->assertSame("$left,$mid $right,$top", $lines['income plan']);
		$this->assertSame("$left,$bottom $right,$bottom", $lines['expense actual']);
		$this->assertArrayNotHasKey('income forecast', $lines, 'Das Ist deckt schon alle Monate');

		// Achse: drei Teilstriche mit Beträgen über den Formatierer
		$this->assertStringContainsString('>0,00 X</text>', $svg);
		$this->assertStringContainsString('>5,00 X</text>', $svg);
		$this->assertStringContainsString('>10,00 X</text>', $svg);
	}

	public function testHochrechnungVomLetztenIstPunktZumPeriodenende(): void {
		$svg = $this->svg(
			['months' => ['2026-01'], 'income' => [500], 'expense' => [0]],
			0, 0, 1000, null, ['Jan', 'Feb', 'Mär'],
		);
		$lines = $this->polylines($svg);

		// Ein einzelner Ist-Punkt ist ein Kreis; die Hochrechnung beginnt dort
		// (500 = Mitte der Skala 0 … 1000) und endet im letzten Monat bei 1000.
		$mid = (TrendSvg::TOP + TrendSvg::BOTTOM) / 2;
		$this->assertStringContainsString('<circle class="income actual" cx="' . TrendSvg::LEFT . '" cy="' . $mid . '"', $svg);
		$this->assertSame(TrendSvg::LEFT . ',' . $mid . ' ' . TrendSvg::RIGHT . ',' . TrendSvg::TOP, $lines['income forecast']);
		$this->assertArrayNotHasKey('expense forecast', $lines, 'ohne Hochrechnung keine Linie');
	}

	public function testLeereReiheErgibtKeinSvg(): void {
		$this->assertSame('', $this->svg(['months' => [], 'income' => [], 'expense' => []], 1000, 1000, null, null, ['Jan', 'Feb']));
	}

	public function testEinMonatOhneDivisionDurchNull(): void {
		$svg = $this->svg(['months' => ['2026-01'], 'income' => [0], 'expense' => [0]], 0, 0, null, null, ['Jan']);

		$this->assertStringContainsString('<svg', $svg);
		$this->assertStringNotContainsString('NAN', $svg);
		$this->assertStringNotContainsString('INF', $svg);
		$x = (TrendSvg::LEFT + TrendSvg::RIGHT) / 2;
		$this->assertStringContainsString('<circle class="income actual" cx="' . $x . '"', $svg);
	}

	public function testBeschriftungenWerdenMaskiert(): void {
		$svg = TrendSvg::render(
			['months' => ['2026-01'], 'income' => [100], 'expense' => [0]],
			0, 0, null, null, ['<b>Jan</b>'],
			static fn (int $c): string => '<i>' . $c . '</i>',
			['title' => 'A & <B>', 'desc' => '"x"'],
		);

		$this->assertStringContainsString('&lt;b&gt;Jan&lt;/b&gt;', $svg);
		$this->assertStringContainsString('&lt;i&gt;', $svg);
		$this->assertStringContainsString('<title>A &amp; &lt;B&gt;</title>', $svg);
		$this->assertStringNotContainsString('<b>', $svg);
		$this->assertStringNotContainsString('<i>', $svg);
	}
}
