<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\InvoiceBatch\BatchSheetParser;
use PHPUnit\Framework\TestCase;

/**
 * Sammelrechnungen (#243), Ansatz A: eine Zeile pro Position, Zeilen werden
 * zu Rechnungen je Empfänger zusammengefasst.
 */
class BatchSheetParserTest extends TestCase {

	private function parser(): BatchSheetParser {
		return new BatchSheetParser(
			['teilnahme' => ['name' => 'Teilnahme', 'description' => 'Probenwochenende', 'unitCode' => 'C62', 'unitPriceCents' => 12000, 'taxCategory' => 'S', 'taxRateBp' => 700, 'accountId' => 41]],
			['8200' => 40, '8300' => 41],
			['proj' => 7, 'projekt 2026' => 7],
		);
	}

	/** @param list<list<string>> $rows erste Zeile = Kopf, Zeilennummern ab 1 */
	private function parse(array $rows): array {
		$keyed = [];
		foreach ($rows as $i => $row) {
			$keyed[$i + 1] = $row;
		}
		return $this->parser()->parse($keyed);
	}

	public function testZeilenDesselbenEmpfaengersWerdenEineRechnung(): void {
		$result = $this->parse([
			['Name', 'Straße', 'PLZ', 'Ort', 'E-Mail', 'Position', 'Menge', 'Einzelpreis'],
			['Anna Beispiel', 'Hauptstr. 1', '12345', 'Musterstadt', 'anna@example.org', 'Teilnahme', '1', '120,00'],
			['', '', '', '', '', 'Übernachtung', '2', '35'],
			['Bernd Muster', 'Weg 2', '54321', 'Andersdorf', '', 'Teilnahme ermäßigt', '', '80,50'],
			['Anna Beispiel', '', '12345', 'Musterstadt', '', 'Noten', '1', '1.234,56 €'],
		]);

		$this->assertSame([], $result['errors']);
		$this->assertCount(2, $result['groups']);
		[$anna, $bernd] = $result['groups'];
		$this->assertSame([2, 3, 5], $anna['lines']);
		$this->assertSame('Anna Beispiel', $anna['recipient']['name']);
		$this->assertSame('Hauptstr. 1', $anna['recipient']['street']);
		$this->assertSame('anna@example.org', $anna['recipient']['email']);
		$this->assertSame('person', $anna['recipient']['kind']);
		$this->assertSame('DE', $anna['recipient']['country']);
		$this->assertSame(['Teilnahme', 'Übernachtung', 'Noten'], array_column($anna['items'], 'name'));
		$this->assertSame([12000, 3500, 123456], array_column($anna['items'], 'unitPriceCents'));
		$this->assertSame([10000, 20000, 10000], array_column($anna['items'], 'quantityE4'));
		$this->assertSame(12000 + 7000 + 123456, $anna['totalCents']);
		$this->assertSame([], $anna['errors']);
		$this->assertSame(8050, $bernd['items'][0]['unitPriceCents']);
		$this->assertSame(10000, $bernd['items'][0]['quantityE4']);
	}

	public function testSpalteRechnungGruppiertAusdruecklich(): void {
		$result = $this->parse([
			['Rechnung', 'Name', 'PLZ', 'Position', 'Preis'],
			['1', 'Max Meier', '10115', 'A', '10'],
			['2', 'Max Meier', '10115', 'B', '20'],
			['1', '', '', 'C', '30'],
		]);

		$this->assertCount(2, $result['groups']);
		$this->assertSame(['A', 'C'], array_column($result['groups'][0]['items'], 'name'));
		$this->assertSame(['B'], array_column($result['groups'][1]['items'], 'name'));
	}

	public function testAndererNameInDerselbenRechnungIstEinFehler(): void {
		$result = $this->parse([
			['Rechnung', 'Name', 'Position', 'Preis'],
			['1', 'Max Meier', 'A', '10'],
			['1', 'Moritz Meier', 'B', '10'],
		]);

		$this->assertCount(1, $result['groups']);
		$this->assertStringContainsString('Zeile 3', $result['groups'][0]['errors'][0]);
	}

	public function testFortsetzungszeileOhneVorgaengerIstEinFehler(): void {
		$result = $this->parse([
			['Name', 'Position', 'Preis'],
			['', 'A', '10'],
		]);

		$this->assertSame([], $result['groups']);
		$this->assertStringContainsString('Zeile 2', $result['errors'][0]);
	}

	public function testVorlageFuelltVorUndZeileGehtVor(): void {
		$result = $this->parse([
			['Name', 'Vorlage', 'Menge', 'Einzelpreis', 'Erlöskonto'],
			['Anna', 'teilnahme', '2', '', ''],
			['Bernd', 'Teilnahme', '1', '99', '8200'],
		]);

		$anna = $result['groups'][0]['items'][0];
		$this->assertSame('Teilnahme', $anna['name']);
		$this->assertSame('Probenwochenende', $anna['description']);
		$this->assertSame(12000, $anna['unitPriceCents']);
		$this->assertSame(700, $anna['taxRateBp']);
		$this->assertSame(41, $anna['accountId']);
		$this->assertSame(20000, $anna['quantityE4']);
		$bernd = $result['groups'][1]['items'][0];
		$this->assertSame(9900, $bernd['unitPriceCents']);
		$this->assertSame(40, $bernd['accountId']);
	}

	public function testUnbekannteVorlageKontoKostenstelleUndEinheitSindFehler(): void {
		$result = $this->parse([
			['Name', 'Position', 'Preis', 'Vorlage', 'Erlöskonto', 'Kostenstelle', 'Einheit'],
			['A', 'x', '1', 'gibtsnicht', '', '', ''],
			['B', 'x', '1', '', '9999', '', ''],
			['C', 'x', '1', '', '', 'unbekannt', ''],
			['D', 'x', '1', '', '', '', 'Fass'],
		]);

		foreach ($result['groups'] as $group) {
			$this->assertCount(1, $group['errors'], $group['recipient']['name']);
		}
	}

	public function testKostenstelleNachCodeOderNameUndEinheitenNachWort(): void {
		$result = $this->parse([
			['Name', 'Position', 'Preis', 'Kostenstelle', 'Einheit', 'Leistungsdatum', 'Rabatt', 'Steuersatz'],
			['A', 'x', '1', 'PROJ', 'Stunden', '05.03.2026', '10 %', '19'],
			['A', 'y', '1', 'Projekt 2026', 'pauschal', '46087', '0.1', '0'],
			['A', 'z', '1', '', 'HUR', '2026-03-07', '', '7,0'],
		]);

		$items = $result['groups'][0]['items'];
		$this->assertSame([], $result['groups'][0]['errors']);
		$this->assertSame([7, 7, null], array_column($items, 'costCenterId'));
		$this->assertSame(['HUR', 'LS', 'HUR'], array_column($items, 'unitCode'));
		// 46087 ist die Excel-Seriennummer des 06.03.2026.
		$this->assertSame(['2026-03-05', '2026-03-06', '2026-03-07'], array_column($items, 'deliveryDate'));
		$this->assertSame([1000, 1000, 0], array_column($items, 'discountBp'));
		$this->assertSame(['S', 'E', 'S'], array_column($items, 'taxCategory'));
		$this->assertSame([1900, 0, 700], array_column($items, 'taxRateBp'));
	}

	public function testUngueltigeWerteSindFehler(): void {
		$result = $this->parse([
			['Name', 'Position', 'Preis', 'Menge', 'Leistungsdatum', 'E-Mail'],
			['A', 'x', 'zwölf', '1', '', ''],
			['B', 'x', '1', 'viel', '', ''],
			['C', 'x', '1', '1', '31.02.2026', ''],
			['D', 'x', '1', '1', '', 'keine-mail'],
			['E', '', '1', '1', '', ''],
			['F', 'x', '', '1', '', ''],
		]);

		foreach ($result['groups'] as $group) {
			$this->assertNotEmpty($group['errors'], $group['recipient']['name']);
		}
	}

	public function testAliaseUndArtUndLand(): void {
		$result = $this->parse([
			['empfänger', 'Zusatz', 'Typ', 'Land', 'Kundennr', 'Leistung', 'Betrag (€)'],
			['Musik GmbH', 'z. Hd. Frau X', 'Firma', 'Österreich', 'K-17', 'Auftritt', '500'],
		]);

		$r = $result['groups'][0]['recipient'];
		$this->assertSame('organisation', $r['kind']);
		$this->assertSame('z. Hd. Frau X', $r['name2']);
		$this->assertSame('AT', $r['country']);
		$this->assertSame('K-17', $r['customerNo']);
		$this->assertSame(50000, $result['groups'][0]['items'][0]['unitPriceCents']);
	}

	/** Die Vorlage aus src/lib/invoiceBatchTemplate.js (gleicher Inhalt) muss ohne Fehler durchgehen. */
	public function testVorlageZumHerunterladenWirdVerstanden(): void {
		$csv = "\xEF\xBB\xBFRechnung;Name;Straße;PLZ;Ort;E-Mail;Position;Beschreibung;Menge;Einheit;Einzelpreis;Erlöskonto;Leistungsdatum\r\n"
			. "1;Anna Beispiel;Musterweg 12;12345;Musterstadt;anna@example.org;Teilnahme Probenwochenende;;1;pauschal;120,00;;14.03.2026\r\n"
			. "1;;;;;;Übernachtung;Doppelzimmer;2;Tag;35,00;;15.03.2026\r\n"
			. '2;Ben Muster;Lindenallee 5;12345;Musterstadt;;Teilnahme Probenwochenende (ermäßigt);;1;pauschal;80,00;;14.03.2026';
		$result = $this->parser()->parse(\OCA\Vereinsbuchhaltung\Service\InvoiceBatch\SpreadsheetReader::read($csv));

		$this->assertSame([], $result['errors']);
		$this->assertCount(2, $result['groups']);
		$this->assertSame([], array_merge(...array_column($result['groups'], 'errors')));
		$this->assertSame(12000 + 7000, $result['groups'][0]['totalCents']);
		$this->assertSame(['LS', 'DAY'], array_column($result['groups'][0]['items'], 'unitCode'));
	}

	public function testOhnePflichtspaltenWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->parse([['Vorname', 'Betrag'], ['A', '1']]);
	}

	public function testOhneDatenzeilenWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->parse([['Name', 'Position']]);
	}
}
