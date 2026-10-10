<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\PeriodLabel;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Perioden in Monatsnamen für die Mails an Mitglieder ({@see PeriodLabel}).
 */
class PeriodLabelTest extends TestCase {

	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		return $l10n;
	}

	public function testEinMonat(): void {
		$this->assertSame('November 2026', PeriodLabel::months('2026-11-01', '2026-11-30', $this->l10n()));
	}

	public function testSchaltjahrFebruarEndetAmNeunundzwanzigsten(): void {
		$this->assertSame('Februar 2028', PeriodLabel::months('2028-02-01', '2028-02-29', $this->l10n()));
	}

	public function testQuartalImSelbenJahr(): void {
		$this->assertSame('Oktober bis Dezember 2026', PeriodLabel::months('2026-10-01', '2026-12-31', $this->l10n()));
	}

	public function testPeriodeUeberDenJahreswechsel(): void {
		$this->assertSame('November 2026 bis Januar 2027', PeriodLabel::months('2026-11-01', '2027-01-31', $this->l10n()));
	}

	public function testMitUhrzeitImDatum(): void {
		$this->assertSame('Mai 2026', PeriodLabel::months('2026-05-01 00:00:00', '2026-05-31 00:00:00', $this->l10n()));
	}

	/** Keine ganzen Monate: der Aufrufer bleibt beim Datumsbereich, statt einen falschen Monat zu behaupten. */
	public function testHalberMonatGibtNullZurueck(): void {
		$this->assertNull(PeriodLabel::months('2026-11-15', '2026-11-30', $this->l10n()));
		$this->assertNull(PeriodLabel::months('2026-11-01', '2026-11-20', $this->l10n()));
	}

	public function testFehlendeOderUngueltigeDatenGebenNullZurueck(): void {
		$this->assertNull(PeriodLabel::months(null, '2026-11-30', $this->l10n()));
		$this->assertNull(PeriodLabel::months('2026-11-01', null, $this->l10n()));
		$this->assertNull(PeriodLabel::months('kein Datum', '2026-11-30', $this->l10n()));
		$this->assertNull(PeriodLabel::months('2026-12-01', '2026-11-30', $this->l10n()), 'Ende vor Beginn');
	}
}
