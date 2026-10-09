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

	/**
	 * Umlaute im lokalen Teil der E-Mail-Adresse sind bei gmx.de/web.de/
	 * t-online.de echte, zustellbare Adressen (häufigste Nachnamen wie
	 * „Müller" oder „Krüger" betreffen das regelmäßig) - der Import darf sie
	 * nicht als ungültig verwerfen.
	 */
	public function testUmlautInEmailWirdAkzeptiert(): void {
		$csv = "Name;E-Mail;Betrag;Frequenz;Start\nAnna Müller;a.müller@gmx.de;42,50;monatlich;01.01.2026\n";
		$zeile = $this->parser->parse($csv)['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertSame('a.müller@gmx.de', $zeile['email']);
	}

	/**
	 * Standardbeitrag (SettingsSepaBasics.vue): eine Zeile mit Start-Datum,
	 * aber ohne eigenen Betrag, uebernimmt den hinterlegten Satz - sonst
	 * muesste er bei 90 gleich zahlenden Chormitgliedern 90 Mal wiederholt
	 * werden.
	 */
	public function testStandardbeitragWirdBeiFehlendemBetragUebernommen(): void {
		$csv = "Name;Start\nKatrin Brunner;01.01.2026\n";
		$zeile = $this->parser->parse($csv, 800, 'monthly')['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertSame(800, $zeile['amountCents']);
		$this->assertSame('monthly', $zeile['frequency']);
	}

	/** Ein eigener Betrag in der Zeile geht immer vor den Standardbeitrag. */
	public function testEigenerBetragUeberschreibtStandardbeitrag(): void {
		$csv = "Name;Betrag;Frequenz;Start\nSonderfall;4,00;monatlich;01.01.2026\n";
		$zeile = $this->parser->parse($csv, 800, 'monthly')['rows'][0];
		$this->assertSame(400, $zeile['amountCents']);
	}

	/**
	 * Ohne Start-Datum bleibt eine reine Mandatszeile ("nur IBAN") auch bei
	 * hinterlegtem Standardbeitrag ein reines Mandat - sonst bekäme jeder
	 * Überweiser ungefragt einen Beitrag.
	 */
	public function testStandardbeitragOhneStartdatumBleibtAus(): void {
		$csv = "Name;IBAN;Mandat am\nÜberweiser;DE02120300000000202051;15.01.2026\n";
		$zeile = $this->parser->parse($csv, 800, 'monthly')['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertNull($zeile['amountCents']);
	}

	/**
	 * Ein Betrag ohne Frequenz bleibt weiterhin ein Jahresbeitrag (bestehende,
	 * getestete Konvention) - der Standard-Frequenz-Fallback gilt nur, wenn
	 * auch der Betrag selbst aus dem Standardbeitrag stammt.
	 */
	public function testBetragOhneFrequenzIgnoriertStandardfrequenz(): void {
		$csv = "Name;Betrag;Start\nKatrin Brunner;96,00;01.01.2026\n";
		$zeile = $this->parser->parse($csv, 800, 'monthly')['rows'][0];
		$this->assertSame('yearly', $zeile['frequency']);
	}

	/**
	 * Wer die App auf Englisch benutzt, exportiert seine Mitgliederliste auch
	 * mit englischen Spaltenüberschriften. Ohne erkanntes Mandatsdatum lehnt
	 * parseRow() jede Zeile mit IBAN ab - "Mandate" muss also treffen.
	 */
	public function testEnglischeSpaltenueberschriften(): void {
		$csv = "Name;Email;IBAN;BIC;Mandate;Amount;Frequency;Start date\n"
			. "Alice Turner;alice.turner@example.org;DE02120300000000202051;BYLADEM1001;2026-01-15;42.50;monthly;2026-02-01\n";

		$ergebnis = $this->parser->parse($csv);
		$this->assertNull($ergebnis['error']);
		$zeile = $ergebnis['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertSame('Alice Turner', $zeile['memberLabel']);
		$this->assertSame('alice.turner@example.org', $zeile['email']);
		$this->assertSame('DE02120300000000202051', $zeile['iban']);
		$this->assertSame('2026-01-15', $zeile['signedDate']);
		$this->assertSame(4250, $zeile['amountCents']);
		$this->assertSame('monthly', $zeile['frequency']);
		$this->assertSame('2026-02-01', $zeile['startDate']);
	}

	/**
	 * Genau die Spaltennamen, die HANDBUCH.en.md (13.3) als erwartet
	 * beschreibt - die Doku versprach sie, bevor der Parser sie kannte.
	 */
	public function testSpaltennamenAusDemEnglischenHandbuch(): void {
		$csv = "Name;Email;IBAN;BIC;Mandate on;Amount;Frequency;Start\n"
			. "Katrin Brunner;k.brunner@example.org;DE02 1203 0000 0000 2020 51;;15.01.2026;42.50;monthly;01.02.2026\n";

		$zeile = $this->parser->parse($csv)['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertSame('2026-01-15', $zeile['signedDate']);
		$this->assertSame(4250, $zeile['amountCents']);
		$this->assertSame('monthly', $zeile['frequency']);
		$this->assertSame('2026-02-01', $zeile['startDate']);
	}

	/** "Account" ist die englische Entsprechung zu "Konto": das Nextcloud-Konto. */
	public function testEnglischeKontospalte(): void {
		$csv = "Account;Amount;Start\nk.brunner;60.00;2026-01-15\n";
		$zeile = $this->parser->parse($csv)['rows'][0];
		$this->assertSame('k.brunner', $zeile['memberUid']);
		$this->assertNull($zeile['memberLabel']);
	}

	/** Englische Frequenzwörter jenseits der Schlüssel selbst. */
	public function testEnglischeFrequenzbeschriftungen(): void {
		foreach (['annually' => 'yearly', 'quarterly' => 'quarterly', 'half-yearly' => 'semiannual', 'Month' => 'monthly'] as $wort => $erwartet) {
			$csv = "Name;Fee;Interval;First due\nAlice Turner;60.00;{$wort};2026-01-15\n";
			$zeile = $this->parser->parse($csv)['rows'][0];
			$this->assertSame([], $zeile['errors'], "Frequenz \"{$wort}\" wurde nicht erkannt");
			$this->assertSame($erwartet, $zeile['frequency'], "Frequenz \"{$wort}\"");
		}
	}

	/**
	 * Eine englische Zeile ohne eigenen Betrag zahlt den Standardbeitrag -
	 * derselbe Weg wie bei der deutschen Tabelle, nur über "Start date".
	 */
	public function testEnglischeZeileNutztStandardbeitrag(): void {
		$csv = "Name;IBAN;Mandate;Start date\nBen Fisher;DE02120300000000202051;2026-01-15;2026-02-01\n";
		$zeile = $this->parser->parse($csv, 6000, 'yearly')['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertSame(6000, $zeile['amountCents']);
		$this->assertSame('yearly', $zeile['frequency']);
	}

	/**
	 * Seit Issue #69 (voller CSV-Import) ist „nur Stammdaten, kein Mandat,
	 * kein Beitrag" eine gültige Zeile – die Klasse diente vorher ausschließlich
	 * der Mandat+Beitrag-Kombi-Erfassung.
	 */
	public function testReineStammdatenzeileOhneMandatUndBeitragIstGueltig(): void {
		$csv = "Name;E-Mail\nKatrin Brunner;k.brunner@example.org\n";
		$zeile = $this->parser->parse($csv)['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertNull($zeile['iban']);
		$this->assertNull($zeile['amountCents']);
	}

	/** Die vier mit Issue #69 neu hinzugekommenen Spalten. */
	public function testNeueSpaltenSeitVollemCsvImport(): void {
		$csv = "Name;Mitgliedsnummer;IBAN;Kontoinhaber;Mandat am;Mandatsreferenz;Beitragsgruppe;Betrag;Frequenz;Start\n"
			. "Katrin Brunner;0815;DE02120300000000202051;Peter Brunner;15.01.2026;ALT-REF-42;Chormitglieder;42,50;monatlich;01.02.2026\n";
		$zeile = $this->parser->parse($csv)['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertSame('0815', $zeile['memberNumber']);
		$this->assertSame('Peter Brunner', $zeile['accountHolder']);
		$this->assertSame('ALT-REF-42', $zeile['mandateReference']);
		$this->assertSame('Chormitglieder', $zeile['groupName']);
	}

	/**
	 * Ob eine Beitragsgruppe zum Betrag passt, weiß erst MemberImportService
	 * (Datenbankzugriff) – der Parser liefert groupName nur unverändert durch,
	 * auch wenn er leer bleibt.
	 */
	public function testBetragOhneBeitragsgruppenspalteWirdKlaglosDurchgereicht(): void {
		$csv = "Name;Betrag;Frequenz;Start\nKatrin Brunner;42,50;monatlich;01.01.2026\n";
		$zeile = $this->parser->parse($csv)['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertNull($zeile['groupName']);
	}

	// --- Namensspalten: Vorname/Nachname/Organisation ---

	public function testVornameUndNachnameErgebenEinePersonMitGenauDiesenFeldern(): void {
		$csv = "Vorname;Nachname;E-Mail\nAnna Maria;Beispiel Müller;anna@example.org\n";
		$zeile = $this->parser->parse($csv)['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertSame('person', $zeile['memberType']);
		$this->assertSame('Anna Maria', $zeile['firstName']);
		$this->assertSame('Beispiel Müller', $zeile['lastName']);
		$this->assertNull($zeile['organizationName']);
		$this->assertNull($zeile['memberLabel']);
	}

	public function testNurNachnameInEinerDateiMitVornameSpalteIstEinePerson(): void {
		$zeile = $this->parser->parse("Vorname;Nachname\n;Beispiel\n")['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertSame('person', $zeile['memberType']);
		$this->assertNull($zeile['firstName']);
		$this->assertSame('Beispiel', $zeile['lastName']);
	}

	public function testVornameOhneNachnameIstEinZeilenfehler(): void {
		$zeile = $this->parser->parse("Vorname;Nachname\nAnna;\n")['rows'][0];
		$this->assertSame(['Bei einer Person ist der Nachname Pflicht.'], $zeile['errors']);
	}

	/** „Name;Vorname" meint in deutschen Listen oft den Nachnamen – geraten wird nicht, der Fehler nennt die Lösung. */
	public function testNameNebenVornameWirdNichtAlsNachnameGeraten(): void {
		$zeile = $this->parser->parse("Name;Vorname\nBeispiel;Anna\n")['rows'][0];
		$this->assertCount(1, $zeile['errors']);
		$this->assertStringContainsString('Spalte „Nachname"', $zeile['errors'][0]);
	}

	/** Sind Vorname und Nachname der Zeile leer, gilt „Name" wie bisher. */
	public function testLeereNamensspaltenFallenAufDenFreitextnamenZurueck(): void {
		$zeile = $this->parser->parse("Name;Vorname;Nachname\nKatrin Brunner;;\n")['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertNull($zeile['memberType']);
		$this->assertSame('Katrin Brunner', $zeile['memberLabel']);
	}

	/** Strukturierte Namen gehen dem Freitextnamen vor; „Name" ist dann redundant. */
	public function testStrukturierterNameSchlaegtFreitextname(): void {
		$zeile = $this->parser->parse("Name;Vorname;Nachname\nKatrin Brunner;Katrin;Brunner\n")['rows'][0];
		$this->assertSame('person', $zeile['memberType']);
		$this->assertNull($zeile['memberLabel']);
	}

	public function testNextcloudKontoBleibtNebenStrukturiertemNamenErhalten(): void {
		$zeile = $this->parser->parse("Konto;Vorname;Nachname\nk.brunner;Katrin;Brunner\n")['rows'][0];
		$this->assertSame('k.brunner', $zeile['memberUid']);
		$this->assertSame('Brunner', $zeile['lastName']);
	}

	public function testOrganisationGefuelltErgibtEineOrganisation(): void {
		$zeile = $this->parser->parse("Organisation;E-Mail\nMusikhaus Beispiel GmbH;info@example.org\n")['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertSame('organisation', $zeile['memberType']);
		$this->assertSame('Musikhaus Beispiel GmbH', $zeile['organizationName']);
		$this->assertNull($zeile['firstName']);
		$this->assertNull($zeile['memberLabel']);
	}

	/** Die Organisation geht vor; Vor-/Nachname daneben werden nicht still verschluckt, sondern gemeldet. */
	public function testOrganisationSchlaegtVornameNachnameMitHinweis(): void {
		$zeile = $this->parser->parse("Organisation;Vorname;Nachname\nMusikhaus;Anna;Beispiel\n")['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertSame('organisation', $zeile['memberType']);
		$this->assertNull($zeile['firstName']);
		$this->assertNull($zeile['lastName']);
		$this->assertCount(1, $zeile['warnings']);
	}

	public function testZeileMitLeererOrganisationFaelltAufPersonZurueck(): void {
		$csv = "Organisation;Vorname;Nachname\n;Anna;Beispiel\nMusikhaus;;\n";
		$rows = $this->parser->parse($csv)['rows'];
		$this->assertSame('person', $rows[0]['memberType']);
		$this->assertSame('organisation', $rows[1]['memberType']);
		$this->assertSame([], $rows[1]['warnings']);
	}

	public function testWederNameNochVornameNochOrganisationIstEinFehler(): void {
		$zeile = $this->parser->parse("Vorname;Nachname;Organisation;E-Mail\n;;;a@example.org\n")['rows'][0];
		$this->assertStringContainsString('Weder Name noch Nextcloud-Konto', implode(' | ', $zeile['errors']));
	}

	/**
	 * Abwärtskompatibilität: ohne Vorname-Spalte bleibt „Nachname" (und seine
	 * englischen Verwandten) eine Schreibweise von „Name" – altes Verhalten.
	 *
	 * @return array<string, array{0:string}>
	 */
	public static function nachnameAlsAlias(): array {
		return [
			'Nachname' => ['Nachname'],
			'Familienname' => ['Familienname'],
			'surname' => ['surname'],
			'lastname' => ['Last name'],
		];
	}

	/**
	 * @dataProvider nachnameAlsAlias
	 */
	public function testNachnameOhneVornameSpalteBleibtDerFreitextname(string $ueberschrift): void {
		$zeile = $this->parser->parse("{$ueberschrift};Betrag;Start\nBeispiel;5,00;01.01.2027\n")['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertSame('Beispiel', $zeile['memberLabel']);
		$this->assertNull($zeile['memberType']);
		$this->assertNull($zeile['lastName']);
	}

	/** Auch das alte Zusammenspiel „Name" + „Nachname" (die spätere Spalte gewinnt) ändert sich nicht. */
	public function testNameUndNachnameOhneVornameSpalteVerhaltenSichWieVorher(): void {
		$zeile = $this->parser->parse("Name;Nachname\nAnna Beispiel;Beispiel\n")['rows'][0];
		$this->assertSame('Beispiel', $zeile['memberLabel']);
		$this->assertNull($zeile['memberType']);
	}

	// --- Spaltenüberschriften der neuen Felder ---

	/**
	 * @return array<string, array{0:string, 1:string}> Überschrift, Feld im Ergebnis
	 */
	public static function neueUeberschriften(): array {
		$cases = [];
		$spec = [
			'firstName' => ['Vorname', 'firstname', 'First Name', 'VORNAME'],
			'organizationName' => ['Organisation', 'Firma', 'Verein', 'organization', 'company', 'ORGANISATION'],
			'street' => ['Straße', 'Strasse', 'STRASSE', 'street', 'address'],
			'postalCode' => ['PLZ', 'Postleitzahl', 'zip', 'Postal Code', 'plz'],
			'city' => ['Ort', 'Stadt', 'city', 'ORT'],
			'phone' => ['Telefon', 'Tel', 'Tel.', 'phone'],
			'joinedAt' => ['Eintritt', 'Eintrittsdatum', 'Mitglied seit', 'Beigetreten am', 'joined', 'EINTRITT'],
		];
		foreach ($spec as $field => $ueberschriften) {
			foreach ($ueberschriften as $ueberschrift) {
				$cases["{$field}: {$ueberschrift}"] = [$ueberschrift, $field];
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider neueUeberschriften
	 */
	public function testNeueUeberschriftenWerdenErkannt(string $ueberschrift, string $feld): void {
		$wert = $feld === 'joinedAt' ? '01.05.2019' : 'Wert 1';
		$erwartet = $feld === 'joinedAt' ? '2019-05-01' : 'Wert 1';
		// „Nachname" daneben, damit auch Vorname/Organisation eine gültige Zeile ergeben.
		$zeile = $this->parser->parse("Nachname;{$ueberschrift}\nBeispiel;{$wert}\n")['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertSame($erwartet, $zeile[$feld]);
	}

	public function testStammdatenspaltenWerdenGetrimmtUndLeerIstNull(): void {
		$csv = "Name;Straße;PLZ;Ort;Telefon\nAnna Beispiel;  Musterweg 12 ; 12345 ;Musterstadt; 0123 456789 \nBen Muster;;;;\n";
		$rows = $this->parser->parse($csv)['rows'];
		$this->assertSame([], $rows[0]['errors']);
		$this->assertSame('Musterweg 12', $rows[0]['street']);
		$this->assertSame('12345', $rows[0]['postalCode']);
		$this->assertSame('Musterstadt', $rows[0]['city']);
		$this->assertSame('0123 456789', $rows[0]['phone']);
		$this->assertNull($rows[1]['street']);
		$this->assertNull($rows[1]['postalCode']);
		$this->assertNull($rows[1]['city']);
		$this->assertNull($rows[1]['phone']);
	}

	/** Ohne diese Spalten bleiben die Felder null – wie bei jeder bisherigen Datei. */
	public function testAlteDateiOhneStammdatenspaltenLiefertNullFelder(): void {
		$zeile = $this->parser->parse("Name;E-Mail\nKatrin Brunner;k@example.org\n")['rows'][0];
		foreach (['street', 'postalCode', 'city', 'phone', 'joinedAt', 'firstName', 'lastName', 'organizationName', 'memberType'] as $feld) {
			$this->assertNull($zeile[$feld], $feld);
		}
		$this->assertSame([], $zeile['warnings']);
	}

	/**
	 * @return array<string, array{0:string, 1:string, 2:string, 3:int}> Kopfzeile, Wertezeile mit Platz „%s", Bezeichnung in der Meldung, Höchstlänge
	 */
	public static function zuLangeWerte(): array {
		return [
			'Vorname' => ['Vorname;Nachname', '%s;Beispiel', 'Vorname', 128],
			'Nachname' => ['Vorname;Nachname', 'Anna;%s', 'Nachname', 128],
			'Straße' => ['Name;Straße', 'Anna Beispiel;%s', 'Straße', 255],
			'PLZ' => ['Name;PLZ', 'Anna Beispiel;%s', 'PLZ', 16],
			'Ort' => ['Name;Ort', 'Anna Beispiel;%s', 'Ort', 128],
			'Telefon' => ['Name;Telefon', 'Anna Beispiel;%s', 'Telefon', 64],
		];
	}

	/**
	 * Die Spalten der Mitgliedertabelle sind begrenzt (Migration Version000137):
	 * ein längerer Wert wird als Zeilenfehler gemeldet, nicht still gekürzt.
	 *
	 * @dataProvider zuLangeWerte
	 */
	public function testZuLangeWerteSindEinZeilenfehler(string $kopf, string $zeile, string $bezeichnung, int $grenze): void {
		$zu = $this->parser->parse($kopf . "\n" . sprintf($zeile, str_repeat('x', $grenze + 1)) . "\n")['rows'][0];
		$this->assertSame(["{$bezeichnung} ist zu lang (höchstens {$grenze} Zeichen)."], $zu['errors']);

		$genau = $this->parser->parse($kopf . "\n" . sprintf($zeile, str_repeat('x', $grenze)) . "\n")['rows'][0];
		$this->assertSame([], $genau['errors'], 'Genau die Höchstlänge ist erlaubt.');
	}

	public function testZuLangerOrganisationsnameIstEinZeilenfehler(): void {
		$zeile = $this->parser->parse("Organisation\n" . str_repeat('x', 256) . "\n")['rows'][0];
		$this->assertStringContainsString('Organisation ist zu lang (höchstens 255 Zeichen).', implode(' | ', $zeile['errors']));
	}

	public function testUmlauteZaehlenAlsEinZeichenBeiDerHoechstlaenge(): void {
		$zeile = $this->parser->parse("Name;PLZ\nAnna Beispiel;" . str_repeat('ä', 16) . "\n")['rows'][0];
		$this->assertSame([], $zeile['errors']);
	}

	// --- Eintrittsdatum ---

	/**
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function eintrittsdaten(): array {
		return [
			'deutsch' => ['01.05.2019', '2019-05-01'],
			'ISO' => ['2019-05-01', '2019-05-01'],
			'einstellig' => ['1.5.2019', '2019-05-01'],
			'weit in der Vergangenheit' => ['15.03.1987', '1987-03-15'],
			'in der Zukunft' => ['01.01.2099', '2099-01-01'],
		];
	}

	/**
	 * @dataProvider eintrittsdaten
	 */
	public function testEintrittDarfInDerVergangenheitLiegen(string $eingabe, string $erwartet): void {
		$zeile = $this->parser->parse("Name;Eintritt\nAnna Beispiel;{$eingabe}\n")['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertSame($erwartet, $zeile['joinedAt']);
	}

	public function testLeererEintrittBleibtNullDerServiceSetztDenImporttag(): void {
		$this->assertNull($this->parser->parse("Name;Eintritt\nAnna Beispiel;\n")['rows'][0]['joinedAt']);
	}

	public function testUngueltigerEintrittIstEinZeilenfehler(): void {
		foreach (['31.02.2019', 'Mai 2019', '2019', '05/01/2019'] as $eingabe) {
			$zeile = $this->parser->parse("Name;Eintritt\nAnna Beispiel;{$eingabe}\n")['rows'][0];
			$this->assertStringContainsString('Unlesbares Eintrittsdatum: ' . $eingabe, implode(' | ', $zeile['errors']), $eingabe);
			$this->assertNull($zeile['joinedAt']);
		}
	}

	/** Die Spalte „Eintritt" war bisher unbekannt und wurde übergangen – ein gültiger Wert ändert sonst nichts an der Zeile. */
	public function testEintrittsspalteAendertNichtsAnDenUebrigenFeldern(): void {
		$mit = $this->parser->parse("Name;Eintritt;IBAN;Mandat am\nKatrin Brunner;01.05.2019;DE02120300000000202051;15.01.2026\n")['rows'][0];
		$ohne = $this->parser->parse("Name;IBAN;Mandat am\nKatrin Brunner;DE02120300000000202051;15.01.2026\n")['rows'][0];
		unset($mit['joinedAt']);
		unset($ohne['joinedAt']);
		$this->assertSame($ohne, $mit);
	}

	// --- Vorlagen ---

	/**
	 * Die Vorlage in docs/mitglieder-import/ ist die Datei, die Vereine tatsächlich
	 * ausfüllen – sie muss der Parser ohne Beanstandung lesen (der Beitragsbeginn
	 * in der Vergangenheit prüft erst MemberImportService, nicht der Parser).
	 */
	public function testDieVorlageAusDerDokumentationIstFehlerfrei(): void {
		$csv = (string)file_get_contents(__DIR__ . '/../../docs/mitglieder-import/vorlage-mitglieder-import.csv');
		$this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'UTF-8 mit BOM, damit Excel die Umlaute richtig liest');

		$ergebnis = $this->parser->parse($csv);
		$this->assertNull($ergebnis['error']);
		$this->assertCount(4, $ergebnis['rows']);
		foreach ($ergebnis['rows'] as $zeile) {
			$this->assertSame([], $zeile['errors'], 'Zeile ' . $zeile['line']);
			$this->assertSame([], $zeile['warnings'], 'Zeile ' . $zeile['line']);
		}
		$this->assertSame('person', $ergebnis['rows'][0]['memberType']);
		$this->assertSame('organisation', $ergebnis['rows'][3]['memberType']);
		$this->assertSame('2019-03-01', $ergebnis['rows'][0]['joinedAt']);
		$this->assertSame('Musterweg 12', $ergebnis['rows'][0]['street']);
		$this->assertSame('Petra Muster', $ergebnis['rows'][1]['accountHolder']);
	}

	/**
	 * Die Vorlage zum Download im Dialog (src/lib/memberImportTemplate.js) darf
	 * keine Überschrift führen, die der Parser nicht kennt. Die Spaltenliste wird
	 * aus der JS-Datei gelesen; ein neuer Eintrag dort braucht hier einen Beispielwert.
	 */
	public function testJedeUeberschriftDerDialogVorlageWirdVomParserErkannt(): void {
		$js = (string)file_get_contents(__DIR__ . '/../../src/lib/memberImportTemplate.js');
		$this->assertSame(1, preg_match('/TEMPLATE_HEADERS = \[(.*?)\]/s', $js, $block));
		preg_match_all("/'([^']+)'/", $block[1], $treffer);
		$ueberschriften = $treffer[1];
		$this->assertCount(19, $ueberschriften);

		// Überschrift → [Feld im Ergebnis, Beispielwert, erwarteter Wert]
		$beispiele = [
			'Vorname' => ['firstName', 'Anna', 'Anna'],
			'Nachname' => ['lastName', 'Beispiel', 'Beispiel'],
			'Organisation' => ['organizationName', 'Musikhaus', 'Musikhaus'],
			'Mitgliedsnummer' => ['memberNumber', '1001', '1001'],
			'Eintritt' => ['joinedAt', '01.03.2019', '2019-03-01'],
			'Straße' => ['street', 'Musterweg 12', 'Musterweg 12'],
			'PLZ' => ['postalCode', '12345', '12345'],
			'Ort' => ['city', 'Musterstadt', 'Musterstadt'],
			'Telefon' => ['phone', '0123 456789', '0123 456789'],
			'E-Mail' => ['email', 'anna@example.org', 'anna@example.org'],
			'IBAN' => ['iban', 'DE02 1203 0000 0000 2020 51', 'DE02120300000000202051'],
			'BIC' => ['bic', 'BYLADEM1001', 'BYLADEM1001'],
			'Kontoinhaber' => ['accountHolder', 'Petra Muster', 'Petra Muster'],
			'Mandat am' => ['signedDate', '15.01.2025', '2025-01-15'],
			'Mandatsreferenz' => ['mandateReference', 'ALT-1', 'ALT-1'],
			'Beitragsgruppe' => ['groupName', 'Vollmitglied', 'Vollmitglied'],
			'Betrag' => ['amountCents', '15,00', 1500],
			'Frequenz' => ['frequency', 'monatlich', 'monthly'],
			'Start' => ['startDate', '01.01.2027', '2027-01-01'],
		];
		$person = [];
		$organisation = [];
		foreach ($ueberschriften as $ueberschrift) {
			$this->assertArrayHasKey($ueberschrift, $beispiele, "Für „{$ueberschrift}\" fehlt hier ein Beispielwert.");
			// Eine Zeile ist Person ODER Organisation: die Organisation geht den Namensfeldern vor.
			$person[] = $ueberschrift === 'Organisation' ? '' : $beispiele[$ueberschrift][1];
			$organisation[] = $ueberschrift === 'Organisation' ? $beispiele[$ueberschrift][1] : '';
		}
		$kopf = implode(';', $ueberschriften);

		$zeile = $this->parser->parse($kopf . "\n" . implode(';', $person) . "\n")['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertSame([], $zeile['warnings']);
		foreach ($ueberschriften as $ueberschrift) {
			if ($ueberschrift !== 'Organisation') {
				$this->assertSame($beispiele[$ueberschrift][2], $zeile[$beispiele[$ueberschrift][0]], $ueberschrift);
			}
		}

		$zeile = $this->parser->parse($kopf . "\n" . implode(';', $organisation) . "\n")['rows'][0];
		$this->assertSame([], $zeile['errors']);
		$this->assertSame('Musikhaus', $zeile['organizationName']);
		$this->assertSame('organisation', $zeile['memberType']);
	}
}
