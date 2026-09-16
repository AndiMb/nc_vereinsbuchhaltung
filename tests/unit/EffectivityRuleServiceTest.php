<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\EffectivityRuleService;
use PHPUnit\Framework\TestCase;

/**
 * „Eine Wirksamkeitsregel für alles" (Spec §3.3): eine Änderung wirkt ab der
 * ersten Periode ohne versandte Vorabinfo; bereits vorabinformierte Perioden
 * werden nie neu berechnet. Issue #68 setzt `prenotified_at` selbst nirgends
 * (das kommt erst in Ticket #70) – die ersten Tests hier bilden genau diesen
 * "keine Sperre"-Stand ab, die übrigen bereiten #70 vor.
 */
class EffectivityRuleServiceTest extends TestCase {

	public function testIsLockedOhnePrenotifiedAtIstFalse(): void {
		$this->assertFalse(EffectivityRuleService::isLocked(null));
	}

	public function testIsLockedMitPrenotifiedAtIstTrue(): void {
		$this->assertTrue(EffectivityRuleService::isLocked('2026-09-01T00:00:00+00:00'));
	}

	/**
	 * Issue #68: `prenotified_at` wird nirgends gesetzt – ohne bestehende
	 * Perioden oder mit durchgehend ungesperrten ist der angefragte Tag immer
	 * frei wählbar.
	 */
	public function testOhneBestehendePeriodenIstDerAngefragteTagFrei(): void {
		$this->assertSame('2026-10-01', EffectivityRuleService::firstEffectiveDate([], '2026-10-01'));
	}

	public function testMitAusschliesslichUngesperrtenPeriodenBleibtDerAngefragteTagFrei(): void {
		$periods = [
			['periodStart' => '2026-01-01', 'periodEnd' => '2026-01-31', 'prenotifiedAt' => null],
			['periodStart' => '2026-02-01', 'periodEnd' => '2026-02-28', 'prenotifiedAt' => null],
		];
		$this->assertSame('2026-03-01', EffectivityRuleService::firstEffectiveDate($periods, '2026-03-01'));
	}

	/**
	 * Vorbereitung für Ticket #70: eine gesperrte erste Periode schiebt den
	 * frühesten wirksamen Tag auf den Tag nach ihrem Ende, auch wenn der
	 * angefragte Tag früher läge.
	 */
	public function testGesperrtePeriodeSchiebtDenWirksamenTagNachHinten(): void {
		$periods = [
			['periodStart' => '2026-01-01', 'periodEnd' => '2026-01-31', 'prenotifiedAt' => '2025-12-15T00:00:00+00:00'],
			['periodStart' => '2026-02-01', 'periodEnd' => '2026-02-28', 'prenotifiedAt' => null],
		];
		$this->assertSame('2026-02-01', EffectivityRuleService::firstEffectiveDate($periods, '2026-01-10'));
	}

	public function testMehrereGesperrtePeriodenInFolge(): void {
		$periods = [
			['periodStart' => '2026-01-01', 'periodEnd' => '2026-01-31', 'prenotifiedAt' => '2025-12-15T00:00:00+00:00'],
			['periodStart' => '2026-02-01', 'periodEnd' => '2026-02-28', 'prenotifiedAt' => '2026-01-15T00:00:00+00:00'],
			['periodStart' => '2026-03-01', 'periodEnd' => '2026-03-31', 'prenotifiedAt' => null],
		];
		$this->assertSame('2026-03-01', EffectivityRuleService::firstEffectiveDate($periods, '2026-01-01'));
	}

	/** Liegt der angefragte Tag ohnehin schon nach der Sperre, gewinnt er. */
	public function testAngefragterTagNachDerSperreGewinnt(): void {
		$periods = [
			['periodStart' => '2026-01-01', 'periodEnd' => '2026-01-31', 'prenotifiedAt' => '2025-12-15T00:00:00+00:00'],
		];
		$this->assertSame('2026-06-01', EffectivityRuleService::firstEffectiveDate($periods, '2026-06-01'));
	}

	public function testUngueltigesDatumWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		EffectivityRuleService::firstEffectiveDate([], '2026-02-30');
	}
}
