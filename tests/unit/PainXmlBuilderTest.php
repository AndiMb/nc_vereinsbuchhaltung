<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Sepa\PainXmlBuilder;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaCreditor;
use PHPUnit\Framework\TestCase;

/**
 * Prüft die erzeugte Einreichungsdatei gegen das Schema, gegen das auch die
 * Bank prüft: `tests/schema/pain.008.001.02.xsd`, das Kunde-Bank-Schema der
 * Deutschen Kreditwirtschaft (DFÜ-Abkommen Anlage 3, Version 3.0).
 *
 * Bis hierher war ausgerechnet der XML-Erzeuger ungetestet – ein Formatfehler
 * wäre erst aufgefallen, wenn die Bank die fertige Datei zurückweist, also
 * frühestens am Einreichungstag. Das Schema prüft mehr, als sich von Hand
 * nachbilden ließe: Reihenfolge der Elemente, Feldlängen, erlaubte Zeichen,
 * Betragsformate.
 */
class PainXmlBuilderTest extends TestCase {

	private const SCHEMA = __DIR__ . '/../schema/pain.008.001.02.xsd';

	private function creditor(): SepaCreditor {
		return new SepaCreditor(
			'MSG-20260812-101500-A1B2C3D4',
			'2026-09-01',
			'DE98ZZZ09999999999',
			'TSV Waldbach e. V.',
			'DE12500105170648489890',
		);
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function row(array $overrides = []): array {
		return array_merge([
			'endToEndId' => 'E2E-20260812-101500-AABBCCDD',
			'amountCents' => 4250,
			'sequenceType' => 'RCUR',
			'mandateReference' => 'M20260812-A1B2C3',
			'signedDate' => '2026-01-15',
			'debtorIban' => 'DE02120300000000202051',
			'debtorBic' => null,
			'debtorName' => 'Katrin Brunner',
			'remittanceInfo' => 'Mitgliedsbeitrag (monatlich)',
		], $overrides);
	}

	/** Validiert gegen das amtliche Schema und gibt die Fehler lesbar aus. */
	private function assertSchemaValid(string $xml): void {
		$doc = new \DOMDocument();
		$this->assertTrue($doc->loadXML($xml), 'Die Ausgabe ist kein wohlgeformtes XML.');

		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$valid = $doc->schemaValidate(self::SCHEMA);
		$fehler = array_map(static fn (\LibXMLError $e): string => trim($e->message), libxml_get_errors());
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		$this->assertTrue($valid, "Verstoß gegen pain.008.001.02:\n" . implode("\n", $fehler));
	}

	private function xpath(string $xml): \DOMXPath {
		$doc = new \DOMDocument();
		$doc->loadXML($xml);
		$xpath = new \DOMXPath($doc);
		$xpath->registerNamespace('p', 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.02');
		return $xpath;
	}

	public function testEinfacherEinzugIstSchemakonform(): void {
		$xml = (new PainXmlBuilder())->build($this->creditor(), [$this->row()]);
		$this->assertSchemaValid($xml);
	}

	/**
	 * Der Grund für die Gruppierung: SeqTp ist laut Schema nur auf PmtInf-Ebene
	 * erlaubt. Eine Datei mit gemischten Sequenztypen muss deshalb mehrere
	 * PmtInf-Blöcke haben – täte sie es nicht, fiele es genau hier auf.
	 */
	public function testGemischteSequenztypenErgebenMehrereBloecke(): void {
		$xml = (new PainXmlBuilder())->build($this->creditor(), [
			$this->row(['sequenceType' => 'RCUR']),
			$this->row(['sequenceType' => 'FRST', 'endToEndId' => 'E2E-20260812-101500-11223344']),
			$this->row(['sequenceType' => 'OOFF', 'endToEndId' => 'E2E-20260812-101500-55667788']),
		]);
		$this->assertSchemaValid($xml);

		$xpath = $this->xpath($xml);
		$sequenzen = [];
		foreach ($xpath->query('//p:PmtInf/p:PmtTpInf/p:SeqTp') as $node) {
			$sequenzen[] = $node->textContent;
		}
		$this->assertSame(['FRST', 'RCUR', 'OOFF'], $sequenzen);
	}

	/** Kopfsumme und Blocksumme müssen zur Summe der Zeilen passen. */
	public function testSummenUndAnzahlenStimmen(): void {
		$xml = (new PainXmlBuilder())->build($this->creditor(), [
			$this->row(['amountCents' => 4250]),
			$this->row(['amountCents' => 1075, 'endToEndId' => 'E2E-20260812-101500-99AABBCC']),
		]);
		$this->assertSchemaValid($xml);

		$xpath = $this->xpath($xml);
		$this->assertSame('2', $xpath->query('//p:GrpHdr/p:NbOfTxs')->item(0)->textContent);
		$this->assertSame('53.25', $xpath->query('//p:GrpHdr/p:CtrlSum')->item(0)->textContent);
		$this->assertSame('53.25', $xpath->query('//p:PmtInf/p:CtrlSum')->item(0)->textContent);
	}

	/**
	 * Umlaute im Vereins- und im Zahlernamen sind der Regelfall, nicht die
	 * Ausnahme – und der EPC-Zeichensatz kennt sie nicht.
	 */
	public function testUmlauteUndSonderzeichenBleibenSchemakonform(): void {
		$creditor = new SepaCreditor(
			'MSG-20260812-101500-A1B2C3D4',
			'2026-09-01',
			'DE98ZZZ09999999999',
			'Grün-Weiß Förderverein & Co. e. V.',
			'DE12500105170648489890',
		);
		$xml = (new PainXmlBuilder())->build($creditor, [
			$this->row([
				'debtorName' => 'Jürgen Groß-Öllers',
				'remittanceInfo' => 'Beitrag „1/2026" – Übungsleiterpauschale € 42,50',
			]),
		]);
		$this->assertSchemaValid($xml);

		$xpath = $this->xpath($xml);
		$this->assertSame('Gruen-Weiss Foerderverein + Co. e. V.', $xpath->query('//p:GrpHdr/p:InitgPty/p:Nm')->item(0)->textContent);
		$this->assertSame('Juergen Gross-Oellers', $xpath->query('//p:DrctDbtTxInf/p:Dbtr/p:Nm')->item(0)->textContent);
		$this->assertSame(
			'Beitrag 1/2026 - Uebungsleiterpauschale EUR 42,50',
			$xpath->query('//p:DrctDbtTxInf/p:RmtInf/p:Ustrd')->item(0)->textContent,
		);
	}

	/** Ohne BIC muss „NOTPROVIDED" stehen, sonst ist der Block unvollständig. */
	public function testOhneBicStehtNotprovided(): void {
		$xml = (new PainXmlBuilder())->build($this->creditor(), [$this->row(['debtorBic' => null])]);
		$this->assertSchemaValid($xml);

		$xpath = $this->xpath($xml);
		$this->assertSame('NOTPROVIDED', $xpath->query('//p:DrctDbtTxInf/p:DbtrAgt/p:FinInstnId/p:Othr/p:Id')->item(0)->textContent);
		$this->assertSame('NOTPROVIDED', $xpath->query('//p:PmtInf/p:CdtrAgt/p:FinInstnId/p:Othr/p:Id')->item(0)->textContent);
	}

	public function testMitBicStehtDieBic(): void {
		$xml = (new PainXmlBuilder())->build($this->creditor(), [$this->row(['debtorBic' => 'BYLADEM1001'])]);
		$this->assertSchemaValid($xml);
		$this->assertSame('BYLADEM1001', $this->xpath($xml)->query('//p:DrctDbtTxInf/p:DbtrAgt/p:FinInstnId/p:BIC')->item(0)->textContent);
	}

	/**
	 * Ein „&" im Namen ist der klassische Weg, sich eine kaputte XML-Datei zu
	 * bauen. SepaText macht daraus ein „+"; entscheidend ist, dass die Datei
	 * in jedem Fall wohlgeformt bleibt und keine fremden Elemente enthält.
	 */
	public function testMarkupImNamenWirdNichtInterpretiert(): void {
		$xml = (new PainXmlBuilder())->build($this->creditor(), [
			$this->row(['debtorName' => 'Müller <script>alert(1)</script> & Sohn']),
		]);
		$this->assertSchemaValid($xml);

		$xpath = $this->xpath($xml);
		$this->assertSame(0, $xpath->query('//script')->length);
		$this->assertStringNotContainsString('<script', $xml);
		// Spitze Klammern sind im EPC-Zeichensatz nicht erlaubt und werden zu
		// Leerzeichen – der Rest bleibt als harmloser Text stehen.
		$this->assertSame('Mueller script alert(1) /script + Sohn', $xpath->query('//p:DrctDbtTxInf/p:Dbtr/p:Nm')->item(0)->textContent);
	}

	/**
	 * Der Verwendungszweck darf 140 Zeichen haben, der Name 70. Längere Werte
	 * kommen aus echten Eingaben und müssen gekürzt werden, statt die Datei
	 * unbrauchbar zu machen.
	 */
	public function testUeberlangeFelderWerdenGekuerzt(): void {
		$xml = (new PainXmlBuilder())->build($this->creditor(), [
			$this->row([
				'debtorName' => str_repeat('Mustermann ', 20),
				'remittanceInfo' => str_repeat('Beitrag 2026 ', 30),
			]),
		]);
		$this->assertSchemaValid($xml);

		$xpath = $this->xpath($xml);
		$this->assertLessThanOrEqual(70, mb_strlen($xpath->query('//p:DrctDbtTxInf/p:Dbtr/p:Nm')->item(0)->textContent));
		$this->assertLessThanOrEqual(140, mb_strlen($xpath->query('//p:DrctDbtTxInf/p:RmtInf/p:Ustrd')->item(0)->textContent));
	}

	/**
	 * Die Mandatsreferenz darf höchstens 35 Zeichen lang sein – und die
	 * PmtInfId, die sich aus der MsgId plus Sequenztyp zusammensetzt, ebenso.
	 * Das ist die Stelle, an der eine längere MsgId unbemerkt überliefe.
	 */
	public function testPmtInfIdBleibtInnerhalbDerLaengengrenze(): void {
		$creditor = new SepaCreditor(
			str_repeat('X', 35),
			'2026-09-01',
			'DE98ZZZ09999999999',
			'TSV Waldbach e. V.',
			'DE12500105170648489890',
		);
		$xml = (new PainXmlBuilder())->build($creditor, [$this->row()]);
		$this->assertSchemaValid($xml);
		$this->assertLessThanOrEqual(35, mb_strlen($this->xpath($xml)->query('//p:PmtInf/p:PmtInfId')->item(0)->textContent));
	}

	/**
	 * Gebührenregelung und Gläubigerkennung stehen an fest vorgeschriebener
	 * Stelle; das Schema erzwingt die Reihenfolge, dieser Test hält fest,
	 * dass die Felder überhaupt vorkommen.
	 */
	public function testGebuehrenregelungUndGlaeubigerIdStehenDrin(): void {
		$xml = (new PainXmlBuilder())->build($this->creditor(), [$this->row()]);
		$xpath = $this->xpath($xml);
		$this->assertSame('SLEV', $xpath->query('//p:PmtInf/p:ChrgBr')->item(0)->textContent);
		$this->assertSame('DE98ZZZ09999999999', $xpath->query('//p:PmtInf/p:CdtrSchmeId/p:Id/p:PrvtId/p:Othr/p:Id')->item(0)->textContent);
		$this->assertSame('CORE', $xpath->query('//p:PmtInf/p:PmtTpInf/p:LclInstrm/p:Cd')->item(0)->textContent);
		$this->assertSame('2026-09-01', $xpath->query('//p:PmtInf/p:ReqdColltnDt')->item(0)->textContent);
	}
}
