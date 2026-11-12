<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Reconciliation\StatementMatcher;
use OCA\Vereinsbuchhaltung\Service\Statement\RowNormalizer;
use PHPUnit\Framework\TestCase;

/** Abgleich von Auszugszeilen und App-Bewegungen für die Kontoabstimmung (Issue #159). */
class StatementMatcherTest extends TestCase {
	private const FROM = '2026-01-01';
	private const TO = '2026-01-31';

	private StatementMatcher $matcher;

	protected function setUp(): void {
		$this->matcher = new StatementMatcher(new RowNormalizer());
	}

	/** @param string|list<string> $text */
	private function s(string $date, int $cents, string|array $text = 'Max Mustermann Beitrag'): array {
		return ['date' => $date, 'amountCents' => $cents, 'texts' => (array)$text, 'row' => []];
	}

	/** @param string|list<string> $text */
	private function a(string $kind, int $id, string $date, int $cents, string|array $text = 'Max Mustermann Beitrag'): array {
		return ['kind' => $kind, 'id' => $id, 'date' => $date, 'amountCents' => $cents, 'texts' => (array)$text, 'ref' => []];
	}

	private function match(array $statement, array $app, int $tolerance = 3): array {
		return $this->matcher->match($statement, $app, self::FROM, self::TO, $tolerance);
	}

	public function testExakterTreffer(): void {
		$r = $this->match([$this->s('2026-01-10', 6000)], [$this->a('booking', 1, '2026-01-10', 6000)]);

		$this->assertSame([['statement' => 0, 'app' => 0, 'stage' => 1]], $r['pairs']);
		$this->assertSame([], $r['surplus']);
		$this->assertSame([], $r['missing']);
		$this->assertSame([], $r['shifted']);
	}

	public function testDubletteInDerApp(): void {
		$r = $this->match(
			[$this->s('2026-01-10', 6000), $this->s('2026-01-10', 6000)],
			[$this->a('booking', 1, '2026-01-10', 6000), $this->a('booking', 2, '2026-01-10', 6000), $this->a('booking', 3, '2026-01-10', 6000)],
		);

		$this->assertCount(2, $r['pairs']);
		$this->assertSame([2], $r['surplus']);
		$this->assertSame([], $r['missing']);
	}

	public function testFehlendeZeile(): void {
		$r = $this->match([$this->s('2026-01-10', -25000)], []);

		$this->assertSame([0], $r['missing']);
		$this->assertSame([], $r['pairs']);
	}

	public function testVorzeichenTrennt(): void {
		$r = $this->match([$this->s('2026-01-10', 5000)], [$this->a('booking', 1, '2026-01-10', -5000)]);

		$this->assertSame([0], $r['missing']);
		$this->assertSame([0], $r['surplus']);
	}

	public function testTextaehnlichkeitAmSelbenTag(): void {
		$r = $this->match(
			[$this->s('2026-01-10', 6000, 'Mitgliedsbeitrag 2026 Max Mustermann')],
			[$this->a('booking', 1, '2026-01-10', 6000, 'Beitrag Mustermann 2026')],
		);

		$this->assertSame([['statement' => 0, 'app' => 0, 'stage' => 2]], $r['pairs']);
	}

	/** Altimporte tragen oft keinen Buchungstext – die Zeile darf dann nicht doppelt als „zu viel“ und „fehlt“ erscheinen. */
	public function testBuchungOhneTextPaartAmSelbenTag(): void {
		$r = $this->match([$this->s('2026-01-10', 6000)], [$this->a('booking', 1, '2026-01-10', 6000, '')]);

		$this->assertSame([['statement' => 0, 'app' => 0, 'stage' => 2]], $r['pairs']);
	}

	public function testUnaehnlicherTextAmSelbenTagPaartErstInStufeDrei(): void {
		$r = $this->match([$this->s('2026-01-10', 6000, 'Miete')], [$this->a('booking', 1, '2026-01-10', 6000, 'Spende Müller')]);

		$this->assertSame([['statement' => 0, 'app' => 0, 'stage' => 3]], $r['pairs']);
	}

	public function testDatumstoleranz(): void {
		$statement = [$this->s('2026-01-10', 6000)];
		$app = [$this->a('booking', 1, '2026-01-13', 6000)];

		$within = $this->match($statement, $app, 3);
		$this->assertSame([['statement' => 0, 'app' => 0, 'stage' => 3]], $within['pairs']);

		$outside = $this->match($statement, $app, 2);
		$this->assertSame([], $outside['pairs']);
		$this->assertSame([0], $outside['missing']);
		$this->assertSame([0], $outside['surplus']);
	}

	public function testToleranzNullPaartNurAmSelbenTag(): void {
		$r = $this->match([$this->s('2026-01-10', 6000, 'Miete')], [$this->a('booking', 1, '2026-01-11', 6000, 'Miete')], 0);

		$this->assertSame([], $r['pairs']);
	}

	public function testNaechstesDatumGewinnt(): void {
		$r = $this->match(
			[$this->s('2026-01-10', 6000)],
			[$this->a('booking', 1, '2026-01-12', 6000), $this->a('booking', 2, '2026-01-09', 6000)],
		);

		$this->assertSame([['statement' => 0, 'app' => 1, 'stage' => 3]], $r['pairs']);
		$this->assertSame([0], $r['surplus']);
	}

	public function testVorrangregel(): void {
		$statement = [$this->s('2026-01-10', 6000)];

		$r = $this->match($statement, [
			$this->a('unassigned', 1, '2026-01-10', 6000),
			$this->a('booking', 2, '2026-01-10', 6000),
			$this->a('assigned', 3, '2026-01-10', 6000),
		]);
		$this->assertSame(2, $r['pairs'][0]['app']);
		$this->assertSame([0, 1], $r['surplus']);

		$r = $this->match($statement, [
			$this->a('unassigned', 1, '2026-01-10', 6000),
			$this->a('booking', 2, '2026-01-10', 6000),
		]);
		$this->assertSame(1, $r['pairs'][0]['app']);
		$this->assertSame([0], $r['surplus']);
	}

	public function testVorrangGiltAuchBeiTextaehnlichkeitUndToleranz(): void {
		$r = $this->match(
			[$this->s('2026-01-10', 6000, 'Mitgliedsbeitrag 2026 Max Mustermann')],
			[$this->a('unassigned', 1, '2026-01-10', 6000, 'Beitrag Mustermann 2026'), $this->a('assigned', 2, '2026-01-10', 6000, 'Beitrag Mustermann 2026')],
		);
		$this->assertSame(1, $r['pairs'][0]['app']);

		$r = $this->match(
			[$this->s('2026-01-10', 6000)],
			[$this->a('unassigned', 1, '2026-01-11', 6000), $this->a('booking', 2, '2026-01-11', 6000)],
		);
		$this->assertSame(1, $r['pairs'][0]['app']);
	}

	public function testNiedrigereIdZuerstInnerhalbEinerArt(): void {
		$r = $this->match(
			[$this->s('2026-01-10', 6000)],
			[$this->a('booking', 9, '2026-01-10', 6000), $this->a('booking', 4, '2026-01-10', 6000)],
		);

		$this->assertSame(1, $r['pairs'][0]['app']);
		$this->assertSame([0], $r['surplus']);
	}

	public function testVerschobenesPaarAmFensterrand(): void {
		$r = $this->match([$this->s('2026-01-31', 6000)], [$this->a('booking', 1, '2026-02-02', 6000)]);

		$this->assertSame([['statement' => 0, 'app' => 0, 'stage' => 3]], $r['pairs']);
		$this->assertSame([['statement' => 0, 'app' => 0]], $r['shifted']);
		$this->assertSame([], $r['surplus']);
		$this->assertSame([], $r['missing']);
	}

	public function testUngepaarteBewegungAusserhalbIstKeinBefund(): void {
		$r = $this->match([], [$this->a('booking', 1, '2026-02-02', 6000), $this->a('booking', 2, '2025-12-31', 100)]);

		$this->assertSame([], $r['surplus']);
	}

	public function testEinTrefferGenuegtBeiMehrerenVergleichstexten(): void {
		$r = $this->match(
			[$this->s('2026-01-10', 6000, ['ABSCHLUSS Abschluss per 31.01.', 'Abschluss per 31.01.'])],
			[$this->a('booking', 1, '2026-01-10', 6000, 'Abschluss per 31.01.')],
		);

		$this->assertSame(1, $r['pairs'][0]['stage']);
	}

	/** Ein exakter Treffer darf nicht an eine nur ähnliche Zeile verloren gehen, die zufällig früher steht. */
	/**
	 * Jahresauszug mit vielen gleich hohen Beiträgen gegen Buchungen mit anderem
	 * Text: der Abgleich darf nicht jedes Paar teuer vergleichen.
	 */
	public function testVieleGleicheBetraegeBleibenSchnell(): void {
		$statement = [];
		$app = [];
		$day = new \DateTimeImmutable('2025-01-01');
		for ($i = 0; $i < 1500; $i++) {
			$date = $day->modify('+' . ($i % 300) . ' days')->format('Y-m-d');
			$statement[] = $this->s($date, 1000, 'Dauerauftrag Mitglied Nummer ' . $i . ' Beitrag laufendes Jahr');
			$app[] = $this->a(StatementMatcher::KIND_BOOKING, $i + 1, $date, 1000, 'Import Zeile ' . ($i * 7) . ' Sammelbuchung Verein xyz');
		}

		$start = microtime(true);
		$r = $this->matcher->match($statement, $app, '2025-01-01', '2025-12-31', 3);
		$elapsed = microtime(true) - $start;

		$this->assertCount(1500, $r['pairs']);
		$this->assertSame([], $r['missing']);
		$this->assertSame([], $r['surplus']);
		$this->assertLessThan(5.0, $elapsed, 'Abgleich von 1500 gleichen Beträgen dauert zu lange');
	}

	public function testExakterTrefferGehtVorAehnlichem(): void {
		$r = $this->match(
			[$this->s('2026-01-10', 6000, 'Beitrag Anna Beispiel'), $this->s('2026-01-10', 6000, 'Beitrag Max Mustermann')],
			[$this->a('booking', 1, '2026-01-10', 6000, 'Beitrag Max Mustermann'), $this->a('booking', 2, '2026-01-10', 6000, 'Beitrag Anna Beispiel')],
		);

		$this->assertEqualsCanonicalizing(
			[['statement' => 0, 'app' => 1, 'stage' => 1], ['statement' => 1, 'app' => 0, 'stage' => 1]],
			$r['pairs'],
		);
	}
}
