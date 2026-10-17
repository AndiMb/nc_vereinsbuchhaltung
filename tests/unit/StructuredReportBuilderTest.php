<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\BudgetTree;
use OCA\Vereinsbuchhaltung\Service\Report\BuiltinReportTemplates;
use OCA\Vereinsbuchhaltung\Service\Report\ReportDefinition;
use OCA\Vereinsbuchhaltung\Service\Report\StructuredReportBuilder;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Der Gliederungsbericht mit einem echten Kontenrahmen nach Titeln und
 * Titelgruppen (tests/fixtures/kontenrahmen-titelgruppen.csv: „Titel
 * Titelgruppe [Unterkonto]“, Titelgruppen 01/11/49 ideell, ab 51 Projekte).
 *
 * Sphären und Hierarchie sind so gesetzt, wie ein Verein sie für diesen
 * Kontenrahmen pflegen würde; die Beträge sind ausgedacht.
 */
class StructuredReportBuilderTest extends TestCase {

	/** @var array<string, array{id:int, parentId:?int, type:string, number:string, name:string, sphere:string, group:string, active:bool}> */
	private array $accounts = [];

	protected function setUp(): void {
		$this->accounts = [];
		$lines = file(__DIR__ . '/../fixtures/kontenrahmen-titelgruppen.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
		foreach ($lines as $line) {
			if (str_starts_with($line, '#')) {
				continue;
			}
			[$number, $type] = explode(';', $line);
			$this->add($number, $type);
		}
	}

	private function add(string $number, string $type, ?string $sphere = null): void {
		$parts = explode(' ', $number);
		if (count($parts) > 1) {
			$parent = implode(' ', array_slice($parts, 0, -1));
		} elseif (strlen($number) > 1) {
			$parent = $number[0];
		} else {
			$parent = null;
		}
		$code = $parts[1] ?? null;
		$this->accounts[$number] = [
			'id' => count($this->accounts) + 1,
			'parentId' => $parent !== null ? $this->accounts[$parent]['id'] : null,
			'type' => $type,
			'number' => $number,
			'name' => 'Konto ' . $number,
			'sphere' => $sphere ?? ($code === null ? 'none' : (in_array($code, ['01', '11', '49'], true) ? 'ideell' : 'zweckbetrieb')),
			'group' => 'cc:' . ($code ?? ''),
			'active' => true,
		];
	}

	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		return $l10n;
	}

	/** @return array<string, mixed> */
	private function builtin(string $id): array {
		$template = (new BuiltinReportTemplates($this->l10n()))->find($id);
		$this->assertNotNull($template);
		return $template['definition'];
	}

	/**
	 * @param list<array<string, int>|null> $columns Cent je Kontonummer, null = Spalte ohne Periode
	 * @param array<string, string> $notes
	 * @return array{rows: list<array<string, mixed>>, warnings: list<array{code:string, accounts:list<string>}>}
	 */
	private function report(array $def, array $columns, ?int $planColumn = null, array $notes = []): array {
		$groups = [];
		$codes = array_unique(array_map(static fn (array $a): string => $a['group'], $this->accounts));
		sort($codes);
		foreach ($codes as $key) {
			if ($key !== 'cc:') {
				$groups[$key] = substr($key, 3);
			}
		}
		$groups['cc:'] = '(ohne)';
		$values = [];
		foreach ($columns as $col) {
			$values[] = $col === null ? null : $this->byId($col);
		}
		return StructuredReportBuilder::rows(ReportDefinition::normalize($def), [
			'accounts' => array_values($this->accounts),
			'columns' => $values,
			'planColumn' => $planColumn,
			'notes' => $this->byId($notes),
			'groups' => $groups,
			'labels' => [
				'types' => ['income' => 'Einnahmen', 'expense' => 'Ausgaben'],
				'spheres' => ['ideell' => 'Ideell', 'vermoegensverwaltung' => 'VV', 'zweckbetrieb' => 'ZB', 'wirtschaftlich' => 'WG', 'none' => 'Ohne'],
				'uncovered' => 'Nicht erfasste Konten',
				'uncoveredFooter' => 'Ergebnis nicht erfasster Konten',
			],
		]);
	}

	/**
	 * @template T
	 * @param array<string, T> $byNumber
	 * @return array<int, T>
	 */
	private function byId(array $byNumber): array {
		$out = [];
		foreach ($byNumber as $number => $v) {
			$out[$this->accounts[(string)$number]['id']] = $v;
		}
		return $out;
	}

	/**
	 * Erste Zeile mit dieser Bezeichnung nach der Zeile $after.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @return array<string, mixed>
	 */
	private function row(array $rows, string $label, ?string $after = null, ?string $kind = null): array {
		$started = $after === null;
		foreach ($rows as $r) {
			if (!$started) {
				$started = $r['label'] === $after;
				continue;
			}
			if ($r['label'] === $label && ($kind === null || $r['kind'] === $kind)) {
				return $r;
			}
		}
		$this->fail("Zeile '$label'" . ($after !== null ? " nach '$after'" : '') . ' fehlt.');
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<string>
	 */
	private function labels(array $rows, string $kind): array {
		return array_values(array_map(static fn (array $r): string => $r['label'], array_filter($rows, static fn (array $r): bool => $r['kind'] === $kind)));
	}

	/** Plan und Ist eines kleinen Haushalts: ideell, zwei Projekte. */
	private function haushalt(): array {
		$plan = [
			'111 01' => 1_200_000, '232 01' => 5_000_000,         // ideell Einnahmen 62.000
			'428 01' => 4_000_000, '511 01' => 300_000,           // ideell Ausgaben 43.000
			'111 51' => 800_000, '119 51' => 200_000,             // Projekt 51 Einnahmen 10.000
			'427 51' => 900_000, '518 51' => 400_000,             // Projekt 51 Ausgaben 13.000
			'233 52' => 150_000, '427 52' => 600_000,             // Projekt 52: 1.500 / 6.000
		];
		$actual = [
			'111 01' => 1_150_000, '232 01' => 5_000_000,
			'428 01 01' => 2_500_000, '428 01 02' => 1_400_000, '511 01 02' => 120_000,
			'111 51' => 850_000, '427 51' => 950_000, '518 51' => 380_000,
			'427 52' => 610_000,
		];
		return [$plan, $actual];
	}

	// --- Zuwendungsgeber --------------------------------------------------

	public function testZuwendungsgeberGliedertEinnahmenUndAusgabenNachBereichUndTitelgruppe(): void {
		[$plan, $actual] = $this->haushalt();
		$r = $this->report($this->builtin(BuiltinReportTemplates::GRANTOR), [[], $plan], 1);
		$rows = $r['rows'];

		$this->assertSame([
			'Einnahmen', 'Ideeller Bereich', 'Titelgruppen / Projekte', '51', '52',
			'Ausgaben', 'Ideeller Bereich', 'Titelgruppen / Projekte', '51', '52',
			'Zusammenfassung nach Hauptgruppen', 'Einnahmen', 'Ausgaben',
		], $this->labels($rows, 'heading'));

		// Ideell mit Hauptgruppe und Titel als Zwischenüberschrift
		$this->assertSame([StructuredReportBuilder::FLAG_STRUCTURAL], $this->row($rows, 'Konto 1')['flags']);
		$this->assertSame([0, 1_200_000], $this->row($rows, 'Konto 111', 'Konto 1')['values'], '111 enthält nur das ideelle 111 01');
		$this->assertSame(1_200_000, $this->row($rows, 'Konto 111 01')['values'][1]);

		// Projekte: Summe der Titelgruppe, Konten ohne Oberkonten
		$this->assertSame([0, 1_000_000], $this->row($rows, 'Summe der Titelgruppe', '51')['values']);
		$this->assertSame([0, 150_000], $this->row($rows, 'Summe der Titelgruppe', '52')['values']);
		$this->assertSame('income', $this->row($rows, 'Summe der Titelgruppe', '51')['nature']);
		$this->assertSame([], $this->row($rows, 'Konto 111 51')['flags'], 'keine fremden Oberkonten im Projekt');

		$this->assertSame([0, 7_350_000], $this->row($rows, 'Gesamteinnahmen')['values']);
		$this->assertSame([0, 6_200_000], $this->row($rows, 'Gesamtausgaben')['values']);
		$this->assertSame(['kind' => 'total', 'depth' => 0, 'label' => 'Differenz', 'nature' => 'result', 'values' => [0, 1_150_000]], end($rows));
		$this->assertSame([], $r['warnings']);
	}

	public function testZuwendungsgeberZeigtBisZurPlanungsebene(): void {
		[$plan] = $this->haushalt();
		$rows = $this->report($this->builtin(BuiltinReportTemplates::GRANTOR), [[], $plan], 1)['rows'];
		$numbers = array_column(array_filter($rows, static fn (array $r): bool => $r['kind'] === 'account'), 'number');

		$this->assertContains('428 01', $numbers);
		$this->assertNotContains('428 01 01', $numbers, 'Unterkonten unter einem Planposten bleiben zu');
	}

	public function testZusammenfassungNachHauptgruppenZaehltNichtDoppelt(): void {
		[$plan] = $this->haushalt();
		$r = $this->report($this->builtin(BuiltinReportTemplates::GRANTOR), [[], $plan], 1);

		$this->assertSame([0, 5_150_000], $this->row($r['rows'], 'Konto 2', 'Zusammenfassung nach Hauptgruppen')['values']);
		$this->assertSame([0, 5_500_000], $this->row($r['rows'], 'Konto 4', 'Zusammenfassung nach Hauptgruppen')['values']);
		$this->assertSame([], $r['warnings']);
	}

	public function testPlannotizenAlsErlaeuterung(): void {
		[$plan] = $this->haushalt();
		$rows = $this->report($this->builtin(BuiltinReportTemplates::GRANTOR), [[], $plan], 1, ['232 01' => 'Allgemeine Zuwendung'])['rows'];

		$i = array_search('Allgemeine Zuwendung', array_column($rows, 'label'), true);
		$this->assertNotFalse($i);
		$this->assertSame('note', $rows[$i]['kind']);
		$this->assertSame('232 01', $rows[$i - 1]['number']);
		$this->assertSame($rows[$i - 1]['depth'] + 1, $rows[$i]['depth']);
	}

	// --- Bereiche und Projekte --------------------------------------------

	public function testBereicheZeigenDifferenzJeProjektUndSaldo(): void {
		[$plan, $actual] = $this->haushalt();
		$def = $this->builtin(BuiltinReportTemplates::AREAS);
		$r = $this->report($def, [[], [], $plan, $actual], 2);
		$rows = $r['rows'];

		$ideell = $this->row($rows, 'Ergebnis ideeller Bereich')['values'];
		$projekte = $this->row($rows, 'Ergebnis Zweckbetrieb')['values'];
		$saldo = $this->row($rows, 'Saldo')['values'];
		$this->assertSame([0, 0, 1_900_000, 2_130_000], $ideell);
		$this->assertSame([0, 0, -750_000, -1_090_000], $projekte);
		for ($c = 0; $c < 4; $c++) {
			$this->assertSame($ideell[$c] + $projekte[$c], $saldo[$c], "Spalte $c");
		}

		// Projekt 51: Einnahmen, Ausgaben, Differenz
		$this->assertSame(['kind' => 'line', 'depth' => 2, 'label' => 'Einnahmen', 'nature' => 'income', 'values' => [0, 0, 1_000_000, 850_000]], $this->row($rows, 'Einnahmen', '51'));
		$this->assertSame([0, 0, 1_300_000, 1_330_000], $this->row($rows, 'Ausgaben', '51')['values']);
		$diff = $this->row($rows, 'Differenz', '51');
		$this->assertSame('result', $diff['nature']);
		$this->assertSame([0, 0, -300_000, -480_000], $diff['values']);
		// Projekt 52 hat keine Einnahmen gebucht, die Zeile bleibt trotzdem stehen
		$this->assertSame([0, 0, 150_000, 0], $this->row($rows, 'Einnahmen', '52')['values']);
		// Leere Sphären fallen weg
		$this->assertNotContains('Vermögensverwaltung', $this->labels($rows, 'heading'));
	}

	public function testSaldoStimmtMitDemFinanzplanUeberein(): void {
		[$plan, $actual] = $this->haushalt();
		$r = $this->report($this->builtin(BuiltinReportTemplates::AREAS), [[], [], $plan, $actual], 2);

		$described = array_values($this->accounts);
		$tree = BudgetTree::build($described, ['plan' => $this->byId($plan), 'actual' => $this->byId($actual)]);
		$p = BudgetTree::totals($tree, 'plan');
		$a = BudgetTree::totals($tree, 'actual');
		$this->assertSame([0, 0, $p['income'] - $p['expense'], $a['income'] - $a['expense']], end($r['rows'])['values']);
	}

	// --- Regeln -----------------------------------------------------------

	public function testNichtErfassteKontenLandenImAutomatischenAbschnitt(): void {
		[$plan] = $this->haushalt();
		$def = [
			'columns' => [['source' => 'plan']],
			'sections' => [['title' => 'Nur ideell', 'filter' => ['spheres' => ['ideell']], 'levels' => [['by' => 'account']]]],
			'grandTotal' => ['label' => 'Saldo'],
		];
		$r = $this->report($def, [$plan], 0);

		$this->assertSame([['code' => 'uncovered', 'accounts' => ['111 51', '119 51', '233 52', '427 51', '427 52', '518 51']]], $r['warnings']);
		$this->assertContains('Nicht erfasste Konten', $this->labels($r['rows'], 'heading'));
		$this->assertSame([-750_000], $this->row($r['rows'], 'Ergebnis nicht erfasster Konten')['values']);
		$this->assertSame([1_150_000], end($r['rows'])['values'], 'Gesamtsumme umfasst alles');
	}

	public function testDoppeltErfassteKontenWerdenMarkiert(): void {
		[$plan] = $this->haushalt();
		$def = [
			'columns' => [['source' => 'plan']],
			'sections' => [
				['title' => 'Alle Einnahmen', 'filter' => ['types' => ['income']], 'levels' => [['by' => 'account']]],
				['title' => 'Projekt 51', 'filter' => ['groups' => ['cc:51']], 'levels' => [['by' => 'account']]],
				['title' => 'Rest', 'filter' => ['types' => ['expense']], 'levels' => [['by' => 'account']]],
			],
		];
		$r = $this->report($def, [$plan], 0);

		$this->assertSame([['code' => 'duplicate', 'accounts' => ['111 51', '119 51', '427 51', '518 51']]], $r['warnings']);
		$this->assertContains(StructuredReportBuilder::FLAG_DUPLICATE, $this->row($r['rows'], 'Konto 111 51')['flags']);
	}

	public function testFusszeileIstSummeOderErgebnisNachErlaubtenKontoarten(): void {
		[$plan] = $this->haushalt();
		$def = [
			'columns' => [['source' => 'plan']],
			'sections' => [
				['title' => 'E', 'filter' => ['types' => ['income']], 'levels' => [['by' => 'account', 'depth' => '0']], 'footer' => ['label' => 'Summe E']],
				['title' => 'A', 'filter' => ['types' => ['expense']], 'levels' => [['by' => 'account', 'depth' => '0']], 'footer' => ['label' => 'Summe A']],
				['title' => 'Beide', 'summary' => true, 'levels' => [['by' => 'sphere']], 'footer' => ['label' => 'Ergebnis']],
			],
		];
		$rows = $this->report($def, [$plan], 0)['rows'];

		$this->assertSame(['income', [7_350_000]], [$this->row($rows, 'Summe E')['nature'], $this->row($rows, 'Summe E')['values']]);
		$this->assertSame(['expense', [6_200_000]], [$this->row($rows, 'Summe A')['nature'], $this->row($rows, 'Summe A')['values']]);
		$this->assertSame(['result', [1_150_000]], [$this->row($rows, 'Ergebnis')['nature'], $this->row($rows, 'Ergebnis')['values']]);
		// Sphäre als letzte Ebene: eine Ergebniszeile je Sphäre
		$this->assertSame([1_900_000], $this->row($rows, 'Ideell', 'Beide')['values']);
		$this->assertSame([-750_000], $this->row($rows, 'ZB', 'Beide')['values']);
	}

	public function testSpalteOhnePeriodeBleibtLeer(): void {
		[$plan] = $this->haushalt();
		$def = [
			'columns' => [['source' => 'plan', 'periodOffset' => -1], ['source' => 'plan']],
			'sections' => [['levels' => [['by' => 'type']]]],
			'grandTotal' => ['label' => 'Saldo'],
		];
		$rows = $this->report($def, [null, $plan], 1)['rows'];

		$this->assertSame([null, 7_350_000], $this->row($rows, 'Einnahmen')['values']);
		$this->assertSame([null, 1_150_000], end($rows)['values']);
	}

	public function testLeereGruppenAusblendenOderZeigen(): void {
		$plan = ['111 51' => 100];
		$level = static fn (bool $hide): array => [
			'columns' => [['source' => 'plan']],
			'sections' => [['filter' => ['spheres' => ['zweckbetrieb']], 'levels' => [['by' => 'group', 'hideEmpty' => $hide, 'footer' => ['label' => 'S']], ['by' => 'account', 'hideEmpty' => $hide, 'depth' => 'all']]]],
		];

		$hidden = $this->labels($this->report($level(true), [$plan], 0)['rows'], 'heading');
		$shown = $this->labels($this->report($level(false), [$plan], 0)['rows'], 'heading');
		$this->assertSame(['51'], $hidden);
		$this->assertContains('73', $shown);
	}

	public function testKontotiefeOhnePlanspalteZeigtAlles(): void {
		$def = [
			'columns' => [['source' => 'actual']],
			'sections' => [['filter' => ['types' => ['expense'], 'spheres' => ['ideell']], 'levels' => [['by' => 'account', 'depth' => 'plan']]]],
		];
		[, $actual] = $this->haushalt();
		$rows = $this->report($def, [$actual], null)['rows'];

		$this->assertSame(3_900_000, $this->row($rows, 'Konto 428 01')['values'][0]);
		$this->assertSame(2_500_000, $this->row($rows, 'Konto 428 01 01')['values'][0]);
	}

	public function testInaktiveKontenWerdenMarkiert(): void {
		$this->accounts['427 51']['active'] = false;
		[$plan] = $this->haushalt();
		$rows = $this->report($this->builtin(BuiltinReportTemplates::GRANTOR), [[], $plan], 1)['rows'];

		$this->assertSame([StructuredReportBuilder::FLAG_INACTIVE], $this->row($rows, 'Konto 427 51')['flags']);
	}
}
