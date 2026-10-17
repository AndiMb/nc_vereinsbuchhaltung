<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\BudgetTree;
use OCA\Vereinsbuchhaltung\Service\Export\KassenberichtRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Die zustandslosen Zeilenbauer des Kassenberichts. Der Rest des Renderers
 * braucht Mapper und Nextcloud-Dienste und gehört in die E2E-Tests.
 */
class KassenberichtRendererTest extends TestCase {

	/**
	 * Eine Sphäre ohne Einnahmen und ohne Ausgaben verlängert den Bericht nur –
	 * wie ein Konto ohne Bewegung in der Erfolgsrechnung.
	 */
	public function testSphaerenOhneBewegungBleibenWeg(): void {
		$html = KassenberichtRenderer::sphereRows([
			['name' => 'Ideeller Bereich', 'income' => 1200.0, 'expense' => 300.5, 'result' => 899.5],
			['name' => 'Vermögensverwaltung', 'income' => 0.0, 'expense' => 0.0, 'result' => 0.0],
			['name' => 'Zweckbetrieb', 'income' => 0, 'expense' => 0, 'result' => 0],
			['name' => '(nicht zugeordnet)', 'income' => 0.0, 'expense' => 0.0, 'result' => 0.0],
		]);

		$this->assertStringContainsString('Ideeller Bereich', $html);
		$this->assertStringContainsString('1.200,00 €', $html);
		$this->assertStringContainsString('899,50 €', $html);
		$this->assertStringNotContainsString('Vermögensverwaltung', $html);
		$this->assertStringNotContainsString('Zweckbetrieb', $html);
		$this->assertStringNotContainsString('nicht zugeordnet', $html);
		$this->assertSame(1, substr_count($html, '<tr>'));
	}

	/**
	 * Nur eine Seite mit Bewegung reicht: eine Sphäre mit Ausgaben, aber ohne
	 * Einnahmen gehört in den Bericht – sonst fehlte ein Teil der Ausgaben.
	 */
	public function testEinseitigeBewegungBleibtSichtbar(): void {
		$html = KassenberichtRenderer::sphereRows([
			['name' => 'Wirtschaftlicher Geschäftsbetrieb', 'income' => 0.0, 'expense' => 80.0, 'result' => -80.0],
			['name' => 'Zweckbetrieb', 'income' => 50.0, 'expense' => 0.0, 'result' => 50.0],
		]);

		$this->assertSame(2, substr_count($html, '<tr>'));
		$this->assertStringContainsString('-80,00 €', $html);
		$this->assertStringContainsString('50,00 €', $html);
	}

	public function testAlleSphaerenOhneBewegungErgebenKeineZeile(): void {
		$this->assertSame('', KassenberichtRenderer::sphereRows([
			['name' => 'Ideeller Bereich', 'income' => 0.0, 'expense' => 0.0, 'result' => 0.0],
		]));
	}

	/**
	 * Plan auf dem Sammelkonto, gebucht auf den Unterkonten: eine Zeile mit
	 * Plan und Ist samt Unterkonten, die Unterkonten selbst erscheinen nicht.
	 */
	public function testSammelkontoZeigtIstDerUnterkontenGegenSeinenPlan(): void {
		$html = $this->planHtml(
			[['5300', 'expense', null], ['5300 01', 'expense', '5300'], ['5300 02', 'expense', '5300']],
			['5300' => 500000],
			['5300 01' => 320000, '5300 02' => 210000],
		);

		$row = $this->row($html, '5300');
		$this->assertStringContainsString('5.000,00 €', $row);
		$this->assertStringContainsString('5.300,00 €', $row);
		$this->assertStringContainsString('300,00 €', $row);
		$this->assertStringContainsString('▲', $row);
		$this->assertStringNotContainsString('>5300 01<', $html);
		$this->assertStringNotContainsString('>5300 02<', $html);
	}

	/** Ist ohne Plan auf dem Konto oder einem Vorfahren ist markiert. */
	public function testUngeplantesKontoIstMarkiert(): void {
		$html = $this->planHtml(
			[['5300', 'expense', null], ['5400', 'expense', null]],
			['5300' => 500000],
			['5400' => 12000],
		);

		$row = $this->row($html, '5400');
		$this->assertStringContainsString('○', $row);
		$this->assertStringContainsString('120,00 €', $row);
	}

	/** Ein Zweig ohne Plan und ohne Ist fällt samt Unterkonten weg. */
	public function testLeererZweigEntfaellt(): void {
		$html = $this->planHtml(
			[['5300', 'expense', null], ['5500', 'expense', null], ['5500 01', 'expense', '5500']],
			['5300' => 500000],
			[],
		);

		$this->assertStringContainsString('>5300<', $html);
		$this->assertStringNotContainsString('>5500<', $html);
		$this->assertStringNotContainsString('>5500 01<', $html);
	}

	/**
	 * Bei einem Plankonflikt ist der Zweig aufgeklappt; ein ungeplantes
	 * Unterkonto erscheint dann, aber ohne eigene Differenz.
	 */
	public function testUnterkontoImKonfliktOhneDifferenz(): void {
		$html = $this->planHtml(
			[['6000', 'expense', null], ['6000 01', 'expense', '6000'], ['6000 02', 'expense', '6000']],
			['6000' => 100000, '6000 01' => 40000],
			['6000 02' => 25000],
		);

		$this->assertStringContainsString('⚠', $this->row($html, '6000'));
		$row = $this->row($html, '6000 02');
		$this->assertSame(1, substr_count($row, '250,00 €'), 'nur das Ist, keine Differenz');
		$this->assertStringEndsWith('<td class="num"></td></tr>', $row);
	}

	/** Einnahmen und Ausgaben stehen je in ihrer Gruppe. */
	public function testZeilenNachTypGetrennt(): void {
		$nodes = $this->planNodes(
			[['4000', 'income', null], ['5300', 'expense', null]],
			['4000' => 100000, '5300' => 50000],
			[],
		);

		$income = KassenberichtRenderer::planRows($nodes, [], 'income');
		$this->assertStringContainsString('>4000<', $income);
		$this->assertStringNotContainsString('>5300<', $income);
	}

	/**
	 * @param list<array{0:string, 1:string, 2:?string}> $konten Nr., Typ, Eltern-Nr.
	 * @param array<array-key, int> $plan Cent je Nr.
	 * @param array<array-key, int> $ist Cent je Nr.
	 * @return list<array<string, mixed>>
	 */
	private function planNodes(array $konten, array $plan, array $ist): array {
		$ids = [];
		$accounts = [];
		foreach ($konten as [$number, $type, $parent]) {
			$ids[$number] = count($accounts) + 1;
			$accounts[] = [
				'id' => $ids[$number],
				'parentId' => $parent !== null ? $ids[$parent] : null,
				'type' => $type,
				'number' => $number,
			];
		}
		$byId = static function (array $byNumber) use ($ids): array {
			$out = [];
			foreach ($byNumber as $number => $cents) {
				$out[$ids[(string)$number]] = $cents;
			}
			return $out;
		};
		$nodes = BudgetTree::annotate(
			BudgetTree::build($accounts, ['plan' => $byId($plan), 'actual' => $byId($ist)]),
			'plan',
			'actual',
		);
		return KassenberichtRenderer::planNodes($nodes);
	}

	/**
	 * @param list<array{0:string, 1:string, 2:?string}> $konten
	 * @param array<array-key, int> $plan
	 * @param array<array-key, int> $ist
	 */
	private function planHtml(array $konten, array $plan, array $ist): string {
		$nodes = $this->planNodes($konten, $plan, $ist);
		return KassenberichtRenderer::planRows($nodes, [], 'income')
			. KassenberichtRenderer::planRows($nodes, [], 'expense');
	}

	/** Die Tabellenzeile des Kontos mit dieser Nummer. */
	private function row(string $html, string $number): string {
		foreach (explode('<tr>', $html) as $row) {
			if (str_contains($row, '>' . $number . '</td>')) {
				return '<tr>' . $row;
			}
		}
		$this->fail('Keine Zeile für ' . $number);
	}
}
