<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Statement\Camt053Parser;
use PHPUnit\Framework\TestCase;

class Camt053ParserTest extends TestCase {
	private Camt053Parser $parser;

	protected function setUp(): void {
		$this->parser = new Camt053Parser();
	}

	private function fixture(): string {
		return (string)file_get_contents(__DIR__ . '/../fixtures/beispiel-camt053.xml');
	}

	public function testLiestDieBeispieldatei(): void {
		$rows = $this->parser->parse($this->fixture());

		// Sechs <Ntry>, aber eine davon ist nur vorgemerkt.
		$this->assertCount(5, $rows);
		$this->assertSame('2026-01-02', $rows[0]['bookingDate']);
		$this->assertSame(6000, $rows[0]['amountCents']);
		$this->assertSame('Max Mustermann', $rows[0]['counterparty']);
		$this->assertSame('DE02120300000000202051', $rows[0]['counterpartyIban']);
		$this->assertSame('BYLADEM1001', $rows[0]['counterpartyBic']);
		$this->assertSame('EUR', $rows[0]['currency']);
	}

	/**
	 * Der Betrag steht in CAMT immer positiv da; die Richtung kommt aus
	 * <CdtDbtInd>. Ohne dessen Auswertung wären alle Ausgaben Einnahmen.
	 */
	public function testRichtungAusCdtDbtInd(): void {
		$rows = $this->parser->parse($this->fixture());

		$this->assertSame(-25000, $rows[1]['amountCents'], 'DBIT muss negativ werden');
		$this->assertSame(125050, $rows[2]['amountCents'], 'CRDT muss positiv bleiben');
	}

	/**
	 * Vorgemerkte Umsätze ändern beim endgültigen Buchen oft Betrag oder Text.
	 * Würden sie importiert, käme derselbe Umsatz später mit abweichendem Hash
	 * ein zweites Mal herein.
	 */
	public function testVorgemerkteBuchungWirdUebersprungen(): void {
		$rows = $this->parser->parse($this->fixture());

		foreach ($rows as $row) {
			$this->assertStringNotContainsString('Noch nicht gebucht', (string)$row['purpose']);
		}
	}

	/** Bei Geldausgang ist der Zahlungsbeteiligte der Empfänger (Cdtr). */
	public function testZahlungsbeteiligterBeiAusgang(): void {
		$rows = $this->parser->parse($this->fixture());

		$this->assertSame('Stadtwerke Musterstadt', $rows[1]['counterparty']);
		$this->assertSame('DE89370400440532013000', $rows[1]['counterpartyIban']);
	}

	/**
	 * CAMT-Dateien tragen einen Standard-Namensraum. Fehlt er ausnahmsweise,
	 * muss der Parser trotzdem greifen – sonst meldet er "keine Buchungen",
	 * obwohl welche in der Datei stehen.
	 */
	public function testFunktioniertAuchOhneNamensraum(): void {
		$ohneNs = str_replace(
			' xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"',
			'',
			$this->fixture()
		);
		$rows = $this->parser->parse($ohneNs);

		$this->assertCount(5, $rows);
		$this->assertSame('Max Mustermann', $rows[0]['counterparty']);
	}

	/**
	 * Eine Sammelbuchung bleibt eine Zeile – die Bank hat auch nur einen Betrag
	 * gebucht. Die Zahl der Einzelposten wird im Text vermerkt.
	 */
	public function testSammelbuchungBleibtEineZeileMitHinweis(): void {
		$xml = <<<'XML'
			<?xml version="1.0" encoding="UTF-8"?>
			<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
			<BkToCstmrStmt><Stmt>
			<Acct><Id><IBAN>DE12500105170648489890</IBAN></Id></Acct>
			<Ntry>
				<Amt Ccy="EUR">180.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Sts>BOOK</Sts>
				<BookgDt><Dt>2026-03-01</Dt></BookgDt>
				<NtryDtls>
					<TxDtls><RmtInf><Ustrd>Beitrag A</Ustrd></RmtInf></TxDtls>
					<TxDtls><RmtInf><Ustrd>Beitrag B</Ustrd></RmtInf></TxDtls>
					<TxDtls><RmtInf><Ustrd>Beitrag C</Ustrd></RmtInf></TxDtls>
				</NtryDtls>
			</Ntry>
			</Stmt></BkToCstmrStmt></Document>
			XML;

		$rows = $this->parser->parse($xml);

		$this->assertCount(1, $rows);
		$this->assertSame(18000, $rows[0]['amountCents']);
		$this->assertStringContainsString('Sammelbuchung (3 Posten)', (string)$rows[0]['purpose']);
	}

	public function testErkenntDasFormat(): void {
		$this->assertTrue($this->parser->supports($this->fixture()));
		$this->assertFalse($this->parser->supports("Buchungstag;Betrag\n02.01.2026;60,00\n"));
	}

	public function testWirftBeiUngueltigemXml(): void {
		$this->expectException(\RuntimeException::class);
		$this->parser->parse('<Document><BkToCstmrStmt>kaputt');
	}
}
