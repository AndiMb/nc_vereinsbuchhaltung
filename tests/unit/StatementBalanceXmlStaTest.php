<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Statement\Camt053Parser;
use OCA\Vereinsbuchhaltung\Service\Statement\Mt940Parser;
use OCA\Vereinsbuchhaltung\Service\Statement\RowNormalizer;
use PHPUnit\Framework\TestCase;

/** Salden aus MT940 (:60F:/:62F:) und CAMT.053 (<Bal>) für die Kontoabstimmung (Issue #159). */
class StatementBalanceXmlStaTest extends TestCase {
	private Mt940Parser $mt940;
	private Camt053Parser $camt;

	protected function setUp(): void {
		$n = new RowNormalizer();
		$this->mt940 = new Mt940Parser($n);
		$this->camt = new Camt053Parser($n);
	}

	private function sta(string $opening, string $closing, string $no = '00001'): string {
		return ":20:STARTUMSATZ\n:25:50010517/0648489890\n:28C:{$no}/001\n{$opening}\n"
			. ":61:2601020102C60,00NTRFNONREF//POS1\n:86:166?00GUTSCHRIFT?20Beitrag\n{$closing}\n-\n";
	}

	public function testMt940Beispieldatei(): void {
		$balances = $this->mt940->balances((string)file_get_contents(__DIR__ . '/../fixtures/beispiel-mt940.sta'));

		$this->assertCount(1, $balances);
		$this->assertSame(100000, $balances[0]->openingCents);
		$this->assertSame('2026-01-01', $balances[0]->openingDate);
		$this->assertSame(211160, $balances[0]->closingCents);
		$this->assertSame('2026-01-31', $balances[0]->closingDate);
		$this->assertSame([], $balances[0]->gaps);
		$this->assertSame('0648489890', $balances[0]->ownAccount);
	}

	public function testMt940SollSaldoIstNegativ(): void {
		$balances = $this->mt940->balances($this->sta(':60F:D260101EUR50,00', ':62F:C260131EUR10,00'));

		$this->assertSame(-5000, $balances[0]->openingCents);
		$this->assertSame(1000, $balances[0]->closingCents);
	}

	public function testMt940ZweiAuszuegeDesselbenKontos(): void {
		// Bewusst in umgekehrter Reihenfolge in der Datei.
		$content = $this->sta(':60F:C260201EUR1100,00', ':62F:C260228EUR900,00', '00002')
			. $this->sta(':60F:C260101EUR1000,00', ':62F:C260131EUR1100,00');

		$balances = $this->mt940->balances($content);

		$this->assertCount(1, $balances);
		$this->assertSame(100000, $balances[0]->openingCents);
		$this->assertSame('2026-01-01', $balances[0]->openingDate);
		$this->assertSame(90000, $balances[0]->closingCents);
		$this->assertSame('2026-02-28', $balances[0]->closingDate);
		$this->assertSame([], $balances[0]->gaps);
	}

	public function testMt940BruchZwischenAuszuegen(): void {
		$content = $this->sta(':60F:C260101EUR1000,00', ':62F:C260131EUR1100,00')
			. $this->sta(':60F:C260201EUR1150,00', ':62F:C260228EUR900,00', '00002');

		$balances = $this->mt940->balances($content);

		$this->assertSame([['date' => '2026-02-01', 'expectedCents' => 110000, 'actualCents' => 115000]], $balances[0]->gaps);
	}

	public function testMt940ZwischensaldenZaehlenNicht(): void {
		$this->assertSame([], $this->mt940->balances($this->sta(':60M:C260101EUR1000,00', ':62M:C260131EUR1100,00')));
	}

	private function camtXml(string $balances): string {
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt>'
			. '<Acct><Id><IBAN>DE12500105170648489890</IBAN></Id></Acct>'
			. $balances
			. '<Ntry><Amt Ccy="EUR">60.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Sts>BOOK</Sts><BookgDt><Dt>2026-01-02</Dt></BookgDt>'
			. '<NtryDtls><TxDtls><RmtInf><Ustrd>Beitrag</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry>'
			. '</Stmt></BkToCstmrStmt></Document>';
	}

	private function bal(string $code, string $amount, string $ind, string $date): string {
		return "<Bal><Tp><CdOrPrtry><Cd>{$code}</Cd></CdOrPrtry></Tp><Amt Ccy=\"EUR\">{$amount}</Amt><CdtDbtInd>{$ind}</CdtDbtInd><Dt><Dt>{$date}</Dt></Dt></Bal>";
	}

	public function testCamtOpbdUndClbd(): void {
		$balances = $this->camt->balances($this->camtXml(
			$this->bal('OPBD', '1000.00', 'CRDT', '2026-01-01') . $this->bal('CLBD', '250.00', 'DBIT', '2026-01-31')
		));

		$this->assertCount(1, $balances);
		$this->assertSame('DE12500105170648489890', $balances[0]->ownAccount);
		$this->assertSame(100000, $balances[0]->openingCents);
		$this->assertSame('2026-01-01', $balances[0]->openingDate);
		$this->assertSame(-25000, $balances[0]->closingCents);
		$this->assertSame('2026-01-31', $balances[0]->closingDate);
	}

	public function testCamtPrcdAlsAnfang(): void {
		$balances = $this->camt->balances($this->camtXml(
			$this->bal('PRCD', '40.00', 'CRDT', '2025-12-31') . $this->bal('CLBD', '100.00', 'CRDT', '2026-01-31')
		));

		$this->assertSame(4000, $balances[0]->openingCents);
		$this->assertSame(10000, $balances[0]->closingCents);
	}

	public function testCamtOhneBal(): void {
		$this->assertSame([], $this->camt->balances((string)file_get_contents(__DIR__ . '/../fixtures/beispiel-camt053.xml')));
		$this->assertSame([], $this->camt->balances($this->camtXml($this->bal('CLBD', '100.00', 'CRDT', '2026-01-31'))));
	}
}
