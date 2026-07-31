<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Statement\RowNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Testet den Dublettenschlüssel ganzer Buchungssätze (xbuc-Merge-Import).
 *
 * Der Schlüssel entscheidet, ob eine eingehende Buchung übersprungen wird –
 * ein Fehler hier verschluckt beim Import stillschweigend eine Buchung. Solange
 * es nur zweizeilige Buchungen gab, konnte das nicht auffallen; mit der
 * Splittbuchung schon.
 */
class JournalFingerprintTest extends TestCase {

	public function testZweizeiligeBuchungBehaeltDasBisherigeFormat(): void {
		$this->assertSame(
			'2026-03-01|12345|7|12|R-2026-004',
			RowNormalizer::journalFingerprint('2026-03-01', 12345, [7], [12], 'R-2026-004'),
			'Der Schlüssel der zweizeiligen Buchung darf sich nicht ändern – sonst gälten alle bestehenden Buchungen wieder als neu',
		);
	}

	public function testBetragWirdOhneVorzeichenGenommen(): void {
		$this->assertSame(
			RowNormalizer::journalFingerprint('2026-03-01', 12345, [7], [12], ''),
			RowNormalizer::journalFingerprint('2026-03-01', -12345, [7], [12], ''),
		);
	}

	public function testSplittbuchungFuehrtAlleKontenUndDieSumme(): void {
		// 100,00 € Ausgabe, aufgeteilt auf zwei Aufwandskonten
		$fp = RowNormalizer::journalFingerprint('2026-03-01', 10000, [20, 21], [7], '');
		$this->assertSame('2026-03-01|10000|20,21|7|', $fp);
	}

	public function testSplittbuchungKollidiertNichtMitEinemTeilbetrag(): void {
		// Genau der Fall, der vorher zum stillen Überspringen führte: die
		// eingehende zweizeilige Buchung über den Teilbetrag 60,00 € auf Konto 21
		// darf nicht als Dublette der Splittbuchung gelten.
		$splitt = RowNormalizer::journalFingerprint('2026-03-01', 10000, [20, 21], [7], '');
		$teil = RowNormalizer::journalFingerprint('2026-03-01', 6000, [21], [7], '');
		$this->assertNotSame($splitt, $teil);
	}

	public function testReihenfolgeDerZeilenIstEgal(): void {
		$this->assertSame(
			RowNormalizer::journalFingerprint('2026-03-01', 10000, [20, 21], [7], ''),
			RowNormalizer::journalFingerprint('2026-03-01', 10000, [21, 20], [7], ''),
			'Die Zeilenreihenfolge aus der Datenbank ist nicht garantiert',
		);
	}

	public function testUnterschiedlicheKontenErgebenUnterschiedlicheSchluessel(): void {
		$this->assertNotSame(
			RowNormalizer::journalFingerprint('2026-03-01', 10000, [20], [7], ''),
			RowNormalizer::journalFingerprint('2026-03-01', 10000, [21], [7], ''),
		);
	}

	public function testBuchungOhneGegenseiteHatKeinenSchluessel(): void {
		$this->assertNull(RowNormalizer::journalFingerprint('2026-03-01', 10000, [20], [], ''));
		$this->assertNull(RowNormalizer::journalFingerprint('2026-03-01', 10000, [], [7], ''));
	}

	public function testBuchungOhneDatumHatKeinenSchluessel(): void {
		$this->assertNull(RowNormalizer::journalFingerprint('', 10000, [20], [7], ''));
	}
}
