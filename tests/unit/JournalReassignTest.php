<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\JournalService;
use PHPUnit\Framework\TestCase;

/**
 * Testet die Fallunterscheidung beim Umbuchen einer Buchungsseite aus dem
 * Kontoauszug ({@see JournalService::reassignPlan()}).
 *
 * Der springende Punkt ist, was NICHT passieren darf: Eine Buchung, die
 * anschließend dasselbe Konto im Soll und im Haben stehen hätte, wäre
 * inhaltlich leer – und eine Buchung ohne Gegenseite verletzt die
 * Grundgleichung Soll = Haben.
 */
class JournalReassignTest extends TestCase {

	/**
	 * @param array<int, array{0:int, 1:int}> $pairs [Zeilen-ID, Konto-ID]
	 * @return array<int, array{id:int, accountId:int}>
	 */
	private function lines(array $pairs): array {
		return array_map(static fn (array $p): array => ['id' => $p[0], 'accountId' => $p[1]], $pairs);
	}

	public function testNormaleBuchungWechseltDieSollSeite(): void {
		// #1 Soll 5300 / Haben 1200 -> Soll 5400
		$lines = $this->lines([[1, 5300], [2, 1200]]);
		$this->assertSame([1], JournalService::reassignPlan($lines, 5300, 5400));
	}

	public function testNormaleBuchungWechseltDieHabenSeite(): void {
		$lines = $this->lines([[1, 5300], [2, 1200]]);
		$this->assertSame([2], JournalService::reassignPlan($lines, 1200, 1000));
	}

	public function testSplittbuchungVerschiebtNurDieBetroffenenZeilen(): void {
		// Eine Ausgabe, aufgeteilt auf zwei Aufwandskonten
		$lines = $this->lines([[1, 5300], [2, 5900], [3, 1200]]);
		$this->assertSame([2], JournalService::reassignPlan($lines, 5900, 5400));
	}

	public function testSplittbuchungWechseltDieFesteSeite(): void {
		// Das Geldkonto einer aufgeteilten Ausgabe auf ein anderes umbuchen
		$lines = $this->lines([[1, 5300], [2, 5900], [3, 1200]]);
		$this->assertSame([3], JournalService::reassignPlan($lines, 1200, 1600));
	}

	public function testSplittbuchungAufEinBereitsBelegtesKontoWirdAbgelehnt(): void {
		// 5900 auf 5300 umbuchen: 5300 steht schon auf derselben Buchung
		$lines = $this->lines([[1, 5300], [2, 5900], [3, 1200]]);
		$this->expectException(\InvalidArgumentException::class);
		JournalService::reassignPlan($lines, 5900, 5300);
	}

	public function testMehrereZeilenAufDemselbenKontoWandernGemeinsam(): void {
		$lines = $this->lines([[1, 5300], [2, 5300], [3, 1200]]);
		$this->assertSame([1, 2], JournalService::reassignPlan($lines, 5300, 5400));
	}

	public function testZielIstDieGegenseiteWirdAbgelehnt(): void {
		// Soll 5300 / Haben 1200 – 5300 auf 1200 umbuchen hieße 1200 an 1200
		$lines = $this->lines([[1, 5300], [2, 1200]]);
		$this->expectException(\InvalidArgumentException::class);
		JournalService::reassignPlan($lines, 5300, 1200);
	}

	public function testGleichesQuellUndZielkontoWirdAbgelehnt(): void {
		$lines = $this->lines([[1, 5300], [2, 1200]]);
		$this->expectException(\InvalidArgumentException::class);
		JournalService::reassignPlan($lines, 5300, 5300);
	}

	public function testKontoOhneZeileInDieserBuchungWirdAbgelehnt(): void {
		$lines = $this->lines([[1, 5300], [2, 1200]]);
		$this->expectException(\InvalidArgumentException::class);
		JournalService::reassignPlan($lines, 4000, 5400);
	}

	public function testBuchungOhneGegenseiteWirdAbgelehnt(): void {
		// Kaputte Altdaten: alle Zeilen auf demselben Konto
		$lines = $this->lines([[1, 5300], [2, 5300]]);
		$this->expectException(\InvalidArgumentException::class);
		JournalService::reassignPlan($lines, 5300, 5400);
	}
}
