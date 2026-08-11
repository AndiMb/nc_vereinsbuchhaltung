<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\BillingPeriod;
use PHPUnit\Framework\TestCase;

/**
 * Die Fälligkeitsfortschreibung entscheidet, wann ein Beitrag eingezogen wird.
 * Ein Fehler zeigt sich nicht als Fehlermeldung, sondern als Einzug, der einen
 * Monat zu spät kommt – oder gar nicht.
 */
class BillingPeriodTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function monatsenden(): array {
		return [
			'kurzer Folgemonat' => ['2026-01-31', 'monthly', '2026-02-28'],
			'Schaltjahr' => ['2028-01-31', 'monthly', '2028-02-29'],
			'30-Tage-Monat' => ['2026-03-31', 'monthly', '2026-04-30'],
			'Monatsmitte' => ['2026-01-15', 'monthly', '2026-02-15'],
			'vierteljährlich' => ['2026-01-31', 'quarterly', '2026-04-30'],
			'halbjährlich' => ['2026-08-31', 'semiannual', '2027-02-28'],
			'jährlich' => ['2026-03-15', 'yearly', '2027-03-15'],
			'Jahreswechsel' => ['2026-12-31', 'monthly', '2027-01-31'],
			'Schalttag jährlich' => ['2028-02-29', 'yearly', '2029-02-28'],
		];
	}

	/**
	 * @dataProvider monatsenden
	 */
	public function testMonatsenden(string $von, string $frequenz, string $erwartet): void {
		$this->assertSame($erwartet, BillingPeriod::next($von, $frequenz));
	}

	/**
	 * Mit Stichtag darf ein kurzer Monat den Termin nicht dauerhaft nach vorn
	 * ziehen: wer zum Monatsletzten bucht, bucht im März wieder am 31. Ohne
	 * Anker bliebe es beim 28. – deshalb reicht der Beitragsdienst immer das
	 * Startdatum mit.
	 */
	public function testStichtagUeberlebtDenFebruar(): void {
		$anker = '2026-01-31';
		$this->assertSame('2026-02-28', BillingPeriod::next($anker, 'monthly', $anker));
		$this->assertSame('2026-03-31', BillingPeriod::next('2026-02-28', 'monthly', $anker));
		$this->assertSame('2026-04-30', BillingPeriod::next('2026-03-31', 'monthly', $anker));
		$this->assertSame('2026-05-31', BillingPeriod::next('2026-04-30', 'monthly', $anker));
	}

	/**
	 * Ohne Anker rechnet die Methode vom übergebenen Tag weiter – das hält der
	 * Test fest, damit die Bedeutung des Parameters nicht versehentlich kippt.
	 */
	public function testOhneAnkerWandertDerTagMit(): void {
		$this->assertSame('2026-03-28', BillingPeriod::next('2026-02-28', 'monthly'));
	}

	/**
	 * Ein Jahr in zwölf Monatsschritten muss wieder auf demselben Tag landen.
	 */
	public function testZwoelfMonatsschritteErgebenEinJahr(): void {
		$datum = '2026-05-17';
		for ($i = 0; $i < 12; $i++) {
			$datum = BillingPeriod::next($datum, 'monthly');
		}
		$this->assertSame('2027-05-17', $datum);
	}

	/**
	 * Ein unmögliches Datum lief vorher still über (aus dem 29. Februar eines
	 * Nicht-Schaltjahres wurde der 1. März, und die Fälligkeit verschob sich
	 * dauerhaft). Jetzt bricht es sichtbar ab – der Aufrufer prüft ohnehin.
	 */
	public function testUnmoeglichesDatumWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		BillingPeriod::next('2026-02-30', 'monthly');
	}

	public function testUnbekannteFrequenzWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		BillingPeriod::next('2026-01-01', 'weekly');
	}

	public function testFalschesFormatWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		BillingPeriod::next('15.01.2026', 'monthly');
	}
}
