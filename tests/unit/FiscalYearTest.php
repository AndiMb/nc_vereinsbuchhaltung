<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\FiscalYear;
use PHPUnit\Framework\TestCase;

/**
 * Die Geschäftsjahres-Grenzen entscheiden darüber, welche Buchungen in eine
 * Auswertung eingehen. Ein Fehler um einen Tag fiele erst im Kassenbericht auf,
 * und dann als falsche Summe – nicht als Fehlermeldung.
 */
class FiscalYearTest extends TestCase {

	public function testGrenzenEinesJahres(): void {
		$this->assertSame('2026-01-01', FiscalYear::start(2026));
		$this->assertSame('2026-12-31', FiscalYear::end(2026));
	}

	/**
	 * Vierstellig aufgefüllt – sonst käme bei einem dreistelligen Jahr ein
	 * String heraus, der sich lexikografisch falsch einsortiert (die Mapper
	 * vergleichen Datumsangaben als Text).
	 */
	public function testJahreswerteWerdenVierstelligAufgefuellt(): void {
		$this->assertSame('0999-01-01', FiscalYear::start(999));
		$this->assertSame('0999-12-31', FiscalYear::end(999));
	}

	/**
	 * @return array<string, array{0: ?int}>
	 */
	public static function keinJahrGewaehlt(): array {
		return [
			'null' => [null],
			'null als Jahr' => [0],
			'negatives Jahr' => [-1],
		];
	}

	/**
	 * @dataProvider keinJahrGewaehlt
	 */
	public function testOhneJahrKeineEingrenzung(?int $year): void {
		$this->assertSame([null, null], FiscalYear::range($year));
		$this->assertFalse(FiscalYear::isSelected($year));
	}

	public function testRangeMitJahr(): void {
		$this->assertSame(['2025-01-01', '2025-12-31'], FiscalYear::range(2025));
		$this->assertTrue(FiscalYear::isSelected(2025));
	}

	public function testOrCurrentFaelltAufDasLaufendeJahrZurueck(): void {
		$laufend = (int)date('Y');
		$this->assertSame($laufend, FiscalYear::orCurrent(null));
		$this->assertSame($laufend, FiscalYear::orCurrent(0));
		$this->assertSame(2019, FiscalYear::orCurrent(2019));
	}

	/**
	 * Der Bereich muss beidseitig einschließend sein: Buchungen vom 1. Januar
	 * und vom 31. Dezember gehören ins Jahr. Die Mapper filtern mit >= / <=,
	 * dieser Test hält die Zusage fest, auf der das beruht.
	 */
	public function testGrenztagePassenZueinander(): void {
		[$von, $bis] = FiscalYear::range(2024);
		$this->assertSame($von, FiscalYear::start(2024));
		$this->assertSame($bis, FiscalYear::end(2024));
		$this->assertLessThan(FiscalYear::start(2025), $bis);
		$this->assertGreaterThan(FiscalYear::end(2023), $von);
	}
}
