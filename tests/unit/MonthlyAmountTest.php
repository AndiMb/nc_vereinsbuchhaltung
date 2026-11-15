<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\MonthlyAmount;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Grenzen für Monatsbeiträge: der Wert wird später mit bis zu zwölf Monaten
 * multipliziert und in eine 32-Bit-Spalte geschrieben.
 */
class MonthlyAmountTest extends TestCase {

	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		return $l10n;
	}

	public function testEuroWerdenZuCentGerundet(): void {
		$this->assertSame(1234, MonthlyAmount::centsFromEuro(12.34));
		$this->assertSame(0, MonthlyAmount::centsFromEuro(0.0));
		$this->assertSame(1999, MonthlyAmount::centsFromEuro(19.99));
	}

	/** Die Umwandlung läuft nie über; was nicht passt, landet knapp außerhalb der Grenze. */
	public function testUnsinnigeWerteLaufenNichtUeber(): void {
		$this->assertGreaterThan(MonthlyAmount::MAX_CENTS, MonthlyAmount::centsFromEuro(4.0e16));
		$this->assertGreaterThan(MonthlyAmount::MAX_CENTS, MonthlyAmount::centsFromEuro(INF));
		$this->assertLessThan(0, MonthlyAmount::centsFromEuro(-5.0e30));
		$this->assertLessThan(0, MonthlyAmount::centsFromEuro(NAN));
	}

	public function testGrenzwerteSindErlaubt(): void {
		MonthlyAmount::assertValid(0, $this->l10n());
		MonthlyAmount::assertValid(MonthlyAmount::MAX_CENTS, $this->l10n());
		// Zwölf Monate zum Höchstbetrag passen in eine 32-Bit-Spalte.
		$this->assertLessThan(2 ** 31, MonthlyAmount::MAX_CENTS * 12);
	}

	public function testZuHoherBetragWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('höchstens 1.000.000,00 €');
		MonthlyAmount::assertValid(MonthlyAmount::MAX_CENTS + 1, $this->l10n());
	}

	public function testNegativerBetragWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		MonthlyAmount::assertValid(-1, $this->l10n());
	}
}
