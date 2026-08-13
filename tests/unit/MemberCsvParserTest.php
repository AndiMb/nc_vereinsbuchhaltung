<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Sepa\MemberCsvParser;
use PHPUnit\Framework\TestCase;

/**
 * Der Import ist der Weg, auf dem ein Chor mit 200 Mitgliedern überhaupt erst
 * in die App kommt. Jede Vereinstabelle sieht anders aus – deshalb hier
 * bewusst viele Formatvarianten statt eines einzigen Musterfalls.
 */
class MemberCsvParserTest extends TestCase {

	private MemberCsvParser $parser;

	protected function setUp(): void {
		$this->parser = new MemberCsvParser();
	}

	public function testTypischeDeutscheTabelle(): void {
		$csv = "Name;E-Mail;IBAN;Mandat am;Betrag;Frequenz;Start\n"
			. "Katrin Brunner;k.brunner@example.org;DE02 1203 0000 0000 2020 51;15.01.2026;42,50;monatlich;01.02.2026\n";

		$ergebnis = $this->parser->parse($csv);
		$this->assertNull($ergebnis['error']);
		$this->assertCount(1, $ergebnis['rows']);

		$zeile = $ergebnis['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertSame('Katrin Brunner', $zeile['memberLabel']);
		$this->assertNull($zeile['memberUid']);
		$this->assertSame('k.brunner@example.org', $zeile['email']);
		$this->assertSame('DE02120300000000202051', $zeile['iban']);
		$this->assertSame('2026-01-15', $zeile['signedDate']);
		$this->assertSame(4250, $zeile['amountCents']);
		$this->assertSame('monthly', $zeile['frequency']);
		$this->assertSame('2026-02-01', $zeile['startDate']);
		$this->assertSame(2, $zeile['line']);
	}

	/** Komma-getrennt, englische Schlüssel, ISO-Datum – auch das kommt vor. */
	public function testKommaGetrenntMitIsoDatum(): void {
		$csv = "name,iban,unterschrieben,betrag,frequenz,start\n"
			. "Hans Mertens,DE02120300000000202051,2026-01-15,120.00,yearly,2026-01-01\n";

		$zeile = $this->parser->parse($csv)['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertSame(12000, $zeile['amountCents']);
		$this->assertSame('yearly', $zeile['frequency']);
	}

	/**
	 * Die häufigste Vereinstabelle überhaupt: Name, IBAN, Jahresbeitrag – ohne
	 * dass jemand eine Frequenz hinschreibt.
	 */
	public function testBetragOhneFrequenzGiltAlsJahresbeitrag(): void {
		$csv = "Name;IBAN;Mandat am;Betrag;Start\nAnke Weiß;DE02120300000000202051;01.03.2025;96;01.04.2025\n";
		$zeile = $this->parser->parse($csv)['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertSame('yearly', $zeile['frequency']);
		$this->assertSame(9600, $zeile['amountCents']);
	}

	public function testTausenderpunktUndDezimalkomma(): void {
		$csv = "Name;IBAN;Mandat am;Betrag;Start\nGroßverein;DE02120300000000202051;01.03.2025;1.234,56;01.04.2025\n";
		$this->assertSame(123456, $this->parser->parse($csv)['rows'][0]['amountCents']);
	}

	/** Spaltenreihenfolge, Groß-/Kleinschreibung und Umlaute sind egal. */
	public function testSpaltenerkennungIstNachsichtig(): void {
		$csv = "BETRAG;zahlungs-frequenz;Zahler;Startdatum\n50,00;Vierteljährlich;Nordchor;01.01.2026\n";
		$zeile = $this->parser->parse($csv)['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertSame('Nordchor', $zeile['memberLabel']);
		$this->assertSame('quarterly', $zeile['frequency']);
		$this->assertSame(5000, $zeile['amountCents']);
	}

	/** Nicht erkannte Spalten stören nicht – Vereinstabellen haben immer mehr. */
	public function testUnbekannteSpaltenWerdenUebergangen(): void {
		$csv = "Mitgliedsnummer;Name;Eintritt;IBAN;Mandat am;Betrag;Start\n"
			. "0815;Katrin Brunner;2019-05-01;DE02120300000000202051;15.01.2026;42,50;01.02.2026\n";
		$zeile = $this->parser->parse($csv)['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertSame('Katrin Brunner', $zeile['memberLabel']);
	}

	/** Steht ein Nextcloud-Konto in der Zeile, gewinnt es gegen den Freitext. */
	public function testKontoSchlaegtFreitextnamen(): void {
		$csv = "Name;Konto;IBAN;Mandat am\nKatrin Brunner;k.brunner;DE02120300000000202051;15.01.2026\n";
		$zeile = $this->parser->parse($csv)['rows'][0];
		$this->assertSame('k.brunner', $zeile['memberUid']);
		$this->assertNull($zeile['memberLabel']);
		$this->assertSame([], $zeile['errors']);
	}

	/** Nur Mandat, kein Beitrag – zulässig (etwa für einmalige Einzüge). */
	public function testNurMandatOhneBeitrag(): void {
		$csv = "Name;IBAN;Mandat am\nKatrin Brunner;DE02120300000000202051;15.01.2026\n";
		$zeile = $this->parser->parse($csv)['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertNull($zeile['amountCents']);
	}

	/** Nur Beitrag, keine IBAN – zulässig (Barzahler, Überweiser). */
	public function testNurBeitragOhneMandat(): void {
		$csv = "Name;Betrag;Frequenz;Start\nBarzahler;10,00;monatlich;01.01.2026\n";
		$zeile = $this->parser->parse($csv)['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertNull($zeile['iban']);
		$this->assertSame(1000, $zeile['amountCents']);
	}

	/**
	 * @return array<string, array{0:string, 1:string}> CSV-Zeile → erwarteter Fehlertext (Anfang)
	 */
	public static function fehlerhafteZeilen(): array {
		return [
			'IBAN ohne Mandatsdatum' => [
				"Name;IBAN\nKatrin Brunner;DE02120300000000202051\n",
				'Zu einer IBAN gehört das Datum',
			],
			'Betrag ohne Startdatum' => [
				"Name;Betrag;Frequenz\nKatrin Brunner;42,50;monatlich\n",
				'Zu einem Betrag gehört ein Startdatum',
			],
			'unbekannte Frequenz' => [
				"Name;Betrag;Frequenz;Start\nKatrin Brunner;42,50;alle zwei Wochen;01.01.2026\n",
				'Unbekannte Zahlungsfrequenz',
			],
			'unmögliches Datum' => [
				"Name;IBAN;Mandat am\nKatrin Brunner;DE02120300000000202051;31.02.2026\n",
				'Unlesbares Mandatsdatum',
			],
			'kaputte E-Mail' => [
				"Name;E-Mail;Betrag;Frequenz;Start\nKatrin Brunner;keine-adresse;42,50;monatlich;01.01.2026\n",
				'Keine gültige E-Mail-Adresse',
			],
			'negativer Betrag' => [
				"Name;Betrag;Frequenz;Start\nKatrin Brunner;-42,50;monatlich;01.01.2026\n",
				'Unlesbarer oder nicht positiver Betrag',
			],
			'ohne Zahler' => [
				"Name;IBAN;Mandat am\n;DE02120300000000202051;15.01.2026\n",
				'Weder Name noch Nextcloud-Konto',
			],
			'weder Mandat noch Beitrag' => [
				"Name;E-Mail\nKatrin Brunner;k.brunner@example.org\n",
				'weder eine IBAN noch einen Beitrag',
			],
		];
	}

	/**
	 * @dataProvider fehlerhafteZeilen
	 */
	public function testFehlerWerdenBenanntStattVerschluckt(string $csv, string $erwartet): void {
		$zeile = $this->parser->parse($csv)['rows'][0];
		$this->assertNotEmpty($zeile['errors'], 'Die Zeile hätte beanstandet werden müssen.');
		$this->assertStringContainsString($erwartet, implode(' | ', $zeile['errors']));
	}

	/** Eine kaputte Zeile darf die übrigen nicht mitreißen. */
	public function testGuteUndSchlechteZeilenGemischt(): void {
		$csv = "Name;IBAN;Mandat am;Betrag;Frequenz;Start\n"
			. "Katrin Brunner;DE02120300000000202051;15.01.2026;42,50;monatlich;01.02.2026\n"
			. "Kaputt;DE02120300000000202051;;;;\n"
			. "Hans Mertens;DE02120300000000202051;15.01.2026;10,00;jährlich;01.02.2026\n";

		$rows = $this->parser->parse($csv)['rows'];
		$this->assertCount(3, $rows);
		$this->assertSame([], $rows[0]['errors']);
		$this->assertNotEmpty($rows[1]['errors']);
		$this->assertSame(3, $rows[1]['line']);
		$this->assertSame([], $rows[2]['errors']);
	}

	public function testLeereDateiWirdBenannt(): void {
		$this->assertSame('Die Datei ist leer.', $this->parser->parse("\n \n")['error']);
	}

	public function testKopfzeileOhneBekannteSpalte(): void {
		$ergebnis = $this->parser->parse("Spalte A;Spalte B\nx;y\n");
		$this->assertNotNull($ergebnis['error']);
		$this->assertSame([], $ergebnis['rows']);
	}

	/** Excel schreibt gern ein BOM an den Dateianfang – das darf nichts kaputt machen. */
	public function testByteOrderMarkStoertNicht(): void {
		$csv = "\xEF\xBB\xBFName;Betrag;Frequenz;Start\nKatrin Brunner;42,50;monatlich;01.01.2026\n";
		$zeile = $this->parser->parse($csv)['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertSame('Katrin Brunner', $zeile['memberLabel']);
	}

	/** Werte mit Semikolon im Namen müssen in Anführungszeichen überleben. */
	public function testAnfuehrungszeichenUndTrennzeichenImWert(): void {
		$csv = "Name;Betrag;Frequenz;Start\n\"Brunner; Katrin\";42,50;monatlich;01.01.2026\n";
		$this->assertSame('Brunner; Katrin', $this->parser->parse($csv)['rows'][0]['memberLabel']);
	}
}
