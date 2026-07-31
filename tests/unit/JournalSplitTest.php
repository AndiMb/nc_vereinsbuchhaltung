<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\BookingService;
use OCA\Vereinsbuchhaltung\Service\JournalService;
use PHPUnit\Framework\TestCase;

/**
 * Testet die Prüfungen rund um Splittbuchungen – die Stellen, an denen eine
 * unausgeglichene oder in sich leere Buchung entstehen könnte.
 *
 * Alle geprüften Funktionen sind bewusst statisch und ohne Datenbank, damit
 * genau diese Fallunterscheidungen prüfbar bleiben (dasselbe Muster wie bei
 * {@see JournalService::reassignPlan()}).
 */
class JournalSplitTest extends TestCase {

	/** @return array{accountId:int, debitCents:int, creditCents:int} */
	private function debit(int $accountId, int $cents): array {
		return ['accountId' => $accountId, 'debitCents' => $cents, 'creditCents' => 0];
	}

	/** @return array{accountId:int, debitCents:int, creditCents:int} */
	private function credit(int $accountId, int $cents): array {
		return ['accountId' => $accountId, 'debitCents' => 0, 'creditCents' => $cents];
	}

	// --- validateLines ---------------------------------------------------

	public function testZweizeiligeBuchungIstGueltig(): void {
		$lines = [$this->debit(5300, 12000), $this->credit(1200, 12000)];
		$this->assertNull(JournalService::validateLines($lines));
	}

	public function testAusgeglichenerSplittIstGueltig(): void {
		// 250,00 € Bankeingang, aufgeteilt auf Beiträge und Spenden
		$lines = [
			$this->debit(1200, 25000),
			$this->credit(8100, 18000),
			$this->credit(8200, 7000),
		];
		$this->assertNull(JournalService::validateLines($lines));
	}

	public function testUnausgeglicheneSummenWerdenAbgelehnt(): void {
		$lines = [
			$this->debit(1200, 25000),
			$this->credit(8100, 18000),
			$this->credit(8200, 6000),
		];
		$this->assertNotNull(JournalService::validateLines($lines));
	}

	public function testZeileMitSollUndHabenWirdAbgelehnt(): void {
		$lines = [
			['accountId' => 1200, 'debitCents' => 10000, 'creditCents' => 5000],
			$this->credit(8100, 5000),
		];
		$this->assertNotNull(JournalService::validateLines($lines));
	}

	public function testZeileOhneBetragWirdAbgelehnt(): void {
		$lines = [
			$this->debit(1200, 25000),
			$this->credit(8100, 25000),
			$this->credit(8200, 0),
		];
		$this->assertNotNull(JournalService::validateLines($lines));
	}

	public function testNegativerBetragWirdAbgelehnt(): void {
		$lines = [$this->debit(1200, -100), $this->credit(8100, -100)];
		$this->assertNotNull(JournalService::validateLines($lines));
	}

	public function testDoppeltesKontoAufDerselbenSeiteWirdAbgelehnt(): void {
		$lines = [
			$this->debit(1200, 25000),
			$this->credit(8100, 18000),
			$this->credit(8100, 7000),
		];
		$this->assertNotNull(JournalService::validateLines($lines));
	}

	public function testGleichesKontoInSollUndHabenWirdAbgelehnt(): void {
		// Waere inhaltlich leer und bräche zudem die Annahmen von reassignPlan()
		$lines = [$this->debit(1200, 5000), $this->credit(1200, 5000)];
		$this->assertNotNull(JournalService::validateLines($lines));
	}

	public function testEinzelneZeileWirdAbgelehnt(): void {
		$this->assertNotNull(JournalService::validateLines([$this->debit(1200, 5000)]));
	}

	public function testZuVieleZeilenWerdenAbgelehnt(): void {
		$lines = [$this->debit(1, JournalService::MAX_LINES * 100)];
		for ($i = 0; $i < JournalService::MAX_LINES; $i++) {
			$lines[] = $this->credit(100 + $i, 100);
		}
		$this->assertNotNull(JournalService::validateLines($lines));
	}

	public function testSimpleLinesErgibtEineGueltigeBuchung(): void {
		$lines = JournalService::simpleLines(5300, 1200, 4250);
		$this->assertNull(JournalService::validateLines($lines));
		$this->assertSame(4250, $lines[0]['debitCents']);
		$this->assertSame(4250, $lines[1]['creditCents']);
	}

	public function testSimpleLinesNimmtDenBetragOhneVorzeichen(): void {
		$lines = JournalService::simpleLines(5300, 1200, -4250);
		$this->assertNull(JournalService::validateLines($lines));
		$this->assertSame(4250, $lines[0]['debitCents']);
	}

	// --- pairLines (Journal-Export) --------------------------------------

	public function testPaarbildungDerZweizeiligenBuchungBleibtEinePaarung(): void {
		$pairs = JournalService::pairLines([$this->debit(5300, 12000), $this->credit(1200, 12000)]);
		$this->assertSame([
			['debitAccountId' => 5300, 'creditAccountId' => 1200, 'amountCents' => 12000],
		], $pairs);
	}

	public function testPaarbildungVerteiltDenSplittAufMehrereZeilen(): void {
		$pairs = JournalService::pairLines([
			$this->debit(1200, 25000),
			$this->credit(8100, 18000),
			$this->credit(8200, 7000),
		]);
		$this->assertSame([
			['debitAccountId' => 1200, 'creditAccountId' => 8100, 'amountCents' => 18000],
			['debitAccountId' => 1200, 'creditAccountId' => 8200, 'amountCents' => 7000],
		], $pairs);
	}

	public function testPaarbildungSummeBleibtDerBuchungsbetrag(): void {
		// N:M – kommt aus dieser App nicht, darf den Export aber nicht verfaelschen
		$pairs = JournalService::pairLines([
			$this->debit(5300, 6000),
			$this->debit(5400, 4000),
			$this->credit(1200, 3000),
			$this->credit(1600, 7000),
		]);
		$sum = array_sum(array_column($pairs, 'amountCents'));
		$this->assertSame(10000, $sum);
	}

	// --- validateParts (Umsatz aufteilen) --------------------------------

	public function testAufteilungDieAufgehtIstGueltig(): void {
		$parts = [
			['accountId' => 8100, 'amountCents' => 18000],
			['accountId' => 8200, 'amountCents' => 7000],
		];
		$this->assertNull(BookingService::validateParts($parts, 25000));
	}

	public function testEinzelneZuordnungIstEineAufteilungDerLaengeEins(): void {
		$this->assertNull(BookingService::validateParts([['accountId' => 8100, 'amountCents' => 25000]], 25000));
	}

	public function testAufteilungMitFehlbetragWirdAbgelehnt(): void {
		$parts = [
			['accountId' => 8100, 'amountCents' => 18000],
			['accountId' => 8200, 'amountCents' => 6000],
		];
		$this->assertNotNull(BookingService::validateParts($parts, 25000));
	}

	public function testAufteilungUeberDenUmsatzHinausWirdAbgelehnt(): void {
		$parts = [
			['accountId' => 8100, 'amountCents' => 18000],
			['accountId' => 8200, 'amountCents' => 8000],
		];
		$this->assertNotNull(BookingService::validateParts($parts, 25000));
	}

	public function testAufteilungMitDoppeltemKontoWirdAbgelehnt(): void {
		$parts = [
			['accountId' => 8100, 'amountCents' => 18000],
			['accountId' => 8100, 'amountCents' => 7000],
		];
		$this->assertNotNull(BookingService::validateParts($parts, 25000));
	}

	public function testTeilbetragOhneKontoWirdAbgelehnt(): void {
		$parts = [
			['accountId' => 0, 'amountCents' => 18000],
			['accountId' => 8200, 'amountCents' => 7000],
		];
		$this->assertNotNull(BookingService::validateParts($parts, 25000));
	}

	public function testTeilbetragVonNullWirdAbgelehnt(): void {
		$parts = [
			['accountId' => 8100, 'amountCents' => 25000],
			['accountId' => 8200, 'amountCents' => 0],
		];
		$this->assertNotNull(BookingService::validateParts($parts, 25000));
	}

	public function testUmsatzUeberNullLaesstSichNichtZuordnen(): void {
		$this->assertNotNull(BookingService::validateParts([['accountId' => 8100, 'amountCents' => 0]], 0));
	}

	/**
	 * Die Aufteilung muss sich in gueltige Buchungszeilen uebersetzen lassen –
	 * beide Pruefungen duerfen nicht auseinanderlaufen.
	 */
	public function testGeprueteAufteilungErgibtGueltigeBuchungszeilen(): void {
		$parts = [
			['accountId' => 8100, 'amountCents' => 18000],
			['accountId' => 8200, 'amountCents' => 7000],
		];
		$this->assertNull(BookingService::validateParts($parts, 25000));

		// So baut BookingService::doAssign() die Zeilen fuer einen Geldeingang
		$lines = [$this->debit(1200, 25000)];
		foreach ($parts as $part) {
			$lines[] = $this->credit($part['accountId'], $part['amountCents']);
		}
		$this->assertNull(JournalService::validateLines($lines));
	}
}
