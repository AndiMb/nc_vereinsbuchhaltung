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

	/**
	 * Sammelgutschrift eines eigenen SEPA-Einzugs (GVC 171, Spec §5 "Trägt
	 * auch die Gutschrift-Seite des eigenen Sammeleinzugs") - je TxDtls eine
	 * SEPA-Detail-Zeile mit eigener EndToEndId und eigenem Teilbetrag, obwohl
	 * der Bankumsatz selbst eine einzige Zeile bleibt (Issue #72).
	 */
	public function testSepaDetailsJeTxDtlsBeiSammelgutschrift(): void {
		$xml = <<<'XML'
			<?xml version="1.0" encoding="UTF-8"?>
			<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
			<BkToCstmrStmt><Stmt>
			<Acct><Id><IBAN>DE12500105170648489890</IBAN></Id></Acct>
			<Ntry>
				<Amt Ccy="EUR">75.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Sts>BOOK</Sts>
				<BookgDt><Dt>2026-10-05</Dt></BookgDt>
				<BkTxCd><Prtry><Cd>171</Cd></Prtry></BkTxCd>
				<NtryDtls>
					<Btch><PmtInfId>MSG-42-RCUR</PmtInfId></Btch>
					<TxDtls>
						<Refs><EndToEndId>E2E-1</EndToEndId><MndtId>M-1</MndtId></Refs>
						<Amt Ccy="EUR">45.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>
						<RmtInf><Ustrd>Beitrag A</Ustrd></RmtInf>
					</TxDtls>
					<TxDtls>
						<Refs><EndToEndId>E2E-2</EndToEndId><MndtId>M-2</MndtId></Refs>
						<Amt Ccy="EUR">30.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>
						<RmtInf><Ustrd>Beitrag B</Ustrd></RmtInf>
					</TxDtls>
				</NtryDtls>
			</Ntry>
			</Stmt></BkToCstmrStmt></Document>
			XML;

		$rows = $this->parser->parse($xml);

		$this->assertCount(1, $rows, 'Der Bankumsatz bleibt eine Zeile');
		$this->assertSame(7500, $rows[0]['amountCents']);
		$details = $rows[0]['sepaDetails'];
		$this->assertCount(2, $details);
		$this->assertSame('E2E-1', $details[0]['endToEndId']);
		$this->assertSame('M-1', $details[0]['mandateReference']);
		$this->assertSame(4500, $details[0]['amountCents']);
		$this->assertSame('MSG-42-RCUR', $details[0]['batchReference']);
		$this->assertSame('171', $details[0]['gvc']);
		$this->assertFalse($details[0]['isReturn']);
		$this->assertSame('E2E-2', $details[1]['endToEndId']);
		$this->assertSame(3000, $details[1]['amountCents']);
	}

	/**
	 * Rücklastschrift (GVC 108 + RtrInf): Rückgabegrund, Ursprungsbetrag und
	 * Bankgebühr müssen strukturiert herauskommen, damit die Verbuchung mit
	 * zwei Gegenkonto-Zeilen (Spec §3.10) daraus rechnen kann.
	 */
	public function testSepaDetailsErkenntRuecklastschrift(): void {
		$xml = <<<'XML'
			<?xml version="1.0" encoding="UTF-8"?>
			<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
			<BkToCstmrStmt><Stmt>
			<Acct><Id><IBAN>DE12500105170648489890</IBAN></Id></Acct>
			<Ntry>
				<Amt Ccy="EUR">50.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts>
				<BookgDt><Dt>2026-10-06</Dt></BookgDt>
				<BkTxCd><Prtry><Cd>108</Cd></Prtry></BkTxCd>
				<NtryDtls>
					<TxDtls>
						<Refs><EndToEndId>E2E-3</EndToEndId><MndtId>M-3</MndtId></Refs>
						<AmtDtls><TxAmt><Amt Ccy="EUR">45.00</Amt></TxAmt></AmtDtls>
						<Chrgs><TotalChargesAndTaxAmt Ccy="EUR">5.00</TotalChargesAndTaxAmt></Chrgs>
						<RtrInf><Rsn><Cd>MD01</Cd></Rsn><AddtlInf>Mandat nicht gefunden</AddtlInf></RtrInf>
					</TxDtls>
				</NtryDtls>
			</Ntry>
			</Stmt></BkToCstmrStmt></Document>
			XML;

		$rows = $this->parser->parse($xml);

		$this->assertSame(-5000, $rows[0]['amountCents']);
		$details = $rows[0]['sepaDetails'];
		$this->assertCount(1, $details);
		$this->assertTrue($details[0]['isReturn']);
		$this->assertSame('E2E-3', $details[0]['endToEndId']);
		$this->assertSame('M-3', $details[0]['mandateReference']);
		$this->assertSame('MD01', $details[0]['returnReasonCode']);
		$this->assertSame('Mandat nicht gefunden', $details[0]['returnReasonText']);
		$this->assertSame(4500, $details[0]['originalAmountCents']);
		$this->assertSame(500, $details[0]['chargesCents']);
	}

	/** Ein Umsatz ohne TxDtls (Normalfall) erzeugt keine SEPA-Detail-Zeilen. */
	public function testSepaDetailsLeerOhneTxDtls(): void {
		$rows = $this->parser->parse($this->fixture());

		$this->assertSame([], $rows[0]['sepaDetails']);
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
