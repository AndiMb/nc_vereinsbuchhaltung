<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\CamtCsvParser;
use PHPUnit\Framework\TestCase;

class CamtCsvParserTest extends TestCase {
	private CamtCsvParser $parser;

	protected function setUp(): void {
		$this->parser = new CamtCsvParser();
	}

	public function testParsesSampleFile(): void {
		$content = file_get_contents(__DIR__ . '/../fixtures/beispiel-camt.csv');
		$rows = $this->parser->parse($content);

		$this->assertCount(5, $rows);
		$this->assertSame('2026-01-02', $rows[0]['bookingDate']);
		$this->assertSame(6000, $rows[0]['amountCents']);
		$this->assertSame('Max Mustermann', $rows[0]['counterparty']);
		$this->assertSame(-25000, $rows[1]['amountCents']);
		$this->assertSame(125050, $rows[2]['amountCents']); // 1.250,50
	}

	public function testHashIsStableAndUnique(): void {
		$content = file_get_contents(__DIR__ . '/../fixtures/beispiel-camt.csv');
		$first = array_column($this->parser->parse($content), 'hash');
		$second = array_column($this->parser->parse($content), 'hash');

		$this->assertSame($first, $second, 'Hash muss bei erneutem Parsen identisch sein');
		$this->assertCount(count($first), array_unique($first), 'Beispielzeilen müssen eindeutige Hashes haben');
	}

	public function testRejectsFileWithoutRows(): void {
		$this->expectException(\RuntimeException::class);
		$this->parser->parse("nur eine zeile");
	}

	/**
	 * Das Pendant zum PDNG-Ausschluss in Camt053ParserTest: ein vorgemerkter
	 * Umsatz ändert beim endgültigen Buchen oft Datum, Betrag oder Text – wird
	 * er trotzdem übernommen, kommt derselbe Umsatz bei einem sich
	 * überschneidenden Folge-Import mit abweichendem Hash ein zweites Mal
	 * herein, statt als Dublette erkannt zu werden.
	 */
	public function testVorgemerkterUmsatzWirdUebersprungen(): void {
		$csv = "Buchungstag;Betrag;Beguenstigter/Zahlungspflichtiger;Info\n"
			. "28.01.2026;60,00;Max Mustermann;Umsatz vorgemerkt\n"
			. "20.01.2026;60,00;Max Mustermann;Umsatz gebucht\n";
		$rows = $this->parser->parse($csv);

		$this->assertCount(1, $rows, 'Die vorgemerkte Zeile muss übersprungen werden');
		$this->assertSame('2026-01-20', $rows[0]['bookingDate']);
	}

	/**
	 * Ein nicht existierendes Datum darf nicht als Buchungsdatum durchgehen –
	 * es käme sonst als "2026-02-31" in die Datenbank.
	 */
	public function testUngueltigesDatumWirdVerworfen(): void {
		$csv = "Buchungstag;Betrag;Beguenstigter/Zahlungspflichtiger\n"
			. "31.02.2026;10,00;Max Mustermann\n"
			. "15.03.2026;20,00;Erika Musterfrau\n";
		$rows = $this->parser->parse($csv);

		$this->assertCount(1, $rows, 'Die Zeile mit dem 31. Februar muss übersprungen werden');
		$this->assertSame('2026-03-15', $rows[0]['bookingDate']);
	}

	/**
	 * Ein Verwendungszweck darf laut CSV Zeilenumbrüche enthalten, solange er
	 * in Anführungszeichen steht – manche Banken exportieren mehrzeilige
	 * Verwendungszwecke genau so. Beim früheren zeilenweisen Einlesen zerriss
	 * so ein Datensatz.
	 */
	public function testMehrzeiligerVerwendungszweck(): void {
		$csv = "Buchungstag;Betrag;Verwendungszweck;Beguenstigter/Zahlungspflichtiger\n"
			. "02.01.2026;60,00;\"Mitgliedsbeitrag 2026\nRechnung 4711\";Max Mustermann\n"
			. "03.01.2026;25,00;Spende;Erika Musterfrau\n";
		$rows = $this->parser->parse($csv);

		$this->assertCount(2, $rows, 'Der mehrzeilige Datensatz darf nicht zerrissen werden');
		$this->assertSame('2026-01-02', $rows[0]['bookingDate']);
		$this->assertSame(6000, $rows[0]['amountCents']);
		$this->assertStringContainsString('Rechnung 4711', (string)$rows[0]['purpose']);
		$this->assertSame('Max Mustermann', $rows[0]['counterparty']);
		$this->assertSame('Erika Musterfrau', $rows[1]['counterparty']);
	}

	public function testZweistelligeJahreszahlWirdErgaenzt(): void {
		$csv = "Buchungstag;Betrag;Beguenstigter/Zahlungspflichtiger\n"
			. "05.01.26;10,00;Max Mustermann\n";
		$rows = $this->parser->parse($csv);

		$this->assertCount(1, $rows);
		$this->assertSame('2026-01-05', $rows[0]['bookingDate']);
	}
}
