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

	public function testLeererZeitraum(): void {
		$this->assertSame([], EntryNumberService::renumberPlan([]));
	}

	/**
	 * Nach dem Umstellen der Geschäftsjahr-Regel können zwei bisher getrennte
	 * Zeiträume zu einem verschmelzen. Dann treffen zwei Nummernkreise
	 * aufeinander, die beide bei 1 begannen; sortiert wird deshalb nach Datum,
	 * und die neue Nummer kann größer sein als die alte.
	 *
	 * Genau deshalb setzt renumberPeriodByDate() die betroffenen Zeilen erst
	 * auf negative Zwischennummern: sonst liefe die Vergabe mitten im
	 * Durchlauf in den Unique-Index.
	 */
	public function testZusammengefuehrteZeitraeumeKoennenNummernVergroessern(): void {
		// Halbjahr A: 1, 2 (IDs 10, 11) – Halbjahr B: 1, 2 (IDs 20, 21),
		// vom Mapper bereits nach Datum sortiert übergeben.
		$rows = $this->rows([[10, 1], [11, 2], [20, 1], [21, 2]]);
		$plan = EntryNumberService::renumberPlan($rows);

		$this->assertSame([20 => 3, 21 => 4], $plan);
		$this->assertGreaterThan(1, $plan[20], 'Beim Verschmelzen darf eine Nummer wachsen');
	}

	/**
	 * Die entscheidende Eigenschaft für die Kollisionsfreiheit: beim Abarbeiten
	 * in aufsteigender Reihenfolge ist die neue Nummer nie größer als die alte.
	 * Nur deshalb ist die Zielnummer beim Schreiben garantiert schon frei und
	 * der Unique-Index (user_id, period_id, entry_no) wird auch zwischendurch
	 * nie verletzt.
	 *
	 * Sie gilt nur, solange die Reihenfolge die bisherige Nummer ist. Beim
	 * Zusammenführen zweier Zeiträume zählt das Datum, und dann kann eine
	 * Nummer auch wachsen – siehe
	 * {@see testZusammengefuehrteZeitraeumeKoennenNummernVergroessern()}.
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
