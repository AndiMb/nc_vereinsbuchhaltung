<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\EntryNumberService;
use PHPUnit\Framework\TestCase;

/**
 * Testet die Nachnummerierung der Buchungsnummern – die Antwort darauf, dass
 * eine gelöschte Buchung sonst eine dauerhafte Lücke hinterlässt, die der
 * Kassenbericht anschließend bemängelt.
 */
class EntryNumberServiceTest extends TestCase {

	/**
	 * @param array<int, array{0:int, 1:int}> $pairs [id, entryNo]
	 * @return array<int, array{id:int, entryNo:int}>
	 */
	private function rows(array $pairs): array {
		return array_map(static fn (array $p): array => ['id' => $p[0], 'entryNo' => $p[1]], $pairs);
	}

	public function testLueckenloseNummerierungBleibtUnveraendert(): void {
		$rows = $this->rows([[10, 1], [11, 2], [12, 3]]);
		$this->assertSame([], EntryNumberService::renumberPlan($rows), 'Ohne Lücke darf nichts geschrieben werden');
	}

	public function testLueckeInDerMitteWirdGeschlossen(): void {
		// Buchung Nr. 2 wurde gelöscht: 1, 3, 4 -> 1, 2, 3
		$rows = $this->rows([[10, 1], [12, 3], [13, 4]]);
		$this->assertSame([12 => 2, 13 => 3], EntryNumberService::renumberPlan($rows));
	}

	public function testLueckeAmAnfangWirdGeschlossen(): void {
		$rows = $this->rows([[11, 2], [12, 3]]);
		$this->assertSame([11 => 1, 12 => 2], EntryNumberService::renumberPlan($rows));
	}

	public function testLetzteBuchungGeloeschtErzeugtKeineAenderung(): void {
		// 1, 2, 3 und die 3 wird gelöscht -> 1, 2 sind schon korrekt
		$rows = $this->rows([[10, 1], [11, 2]]);
		$this->assertSame([], EntryNumberService::renumberPlan($rows));
	}

	public function testDoppelteNummernWerdenAufgeloest(): void {
		// Wettlauf zweier gleichzeitiger Buchungen (Altbestand vor dem Unique-Index)
		$rows = $this->rows([[10, 1], [11, 2], [12, 2], [13, 3]]);
		$this->assertSame([12 => 3, 13 => 4], EntryNumberService::renumberPlan($rows));
	}

	public function testFehlendeNummernAusAltbestand(): void {
		// entry_no war früher NULL -> vom Mapper als 0 geliefert
		$rows = $this->rows([[10, 0], [11, 0], [12, 5]]);
		$this->assertSame([10 => 1, 11 => 2, 12 => 3], EntryNumberService::renumberPlan($rows));
	}

	public function testLeeresJahr(): void {
		$this->assertSame([], EntryNumberService::renumberPlan([]));
	}

	/**
	 * Die entscheidende Eigenschaft für die Kollisionsfreiheit: beim Abarbeiten
	 * in aufsteigender Reihenfolge ist die neue Nummer nie größer als die alte.
	 * Nur deshalb ist die Zielnummer beim Schreiben garantiert schon frei und
	 * der Unique-Index (user_id, year, entry_no) wird auch zwischendurch nie
	 * verletzt.
	 */
	public function testNeueNummerIstNieGroesserAlsDieAlte(): void {
		$rows = $this->rows([[10, 3], [11, 7], [12, 8], [13, 20]]);
		$plan = EntryNumberService::renumberPlan($rows);
		foreach ($rows as $row) {
			if (isset($plan[$row['id']])) {
				$this->assertLessThanOrEqual(
					$row['entryNo'],
					$plan[$row['id']],
					'Nachnummerierung darf Nummern nur verkleinern',
				);
			}
		}
	}
}
