<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Service\CamtCsvParser;
use OCA\Vereinsbuchhaltung\Service\Statement\Camt053Parser;
use OCA\Vereinsbuchhaltung\Service\Statement\Mt940Parser;
use OCA\Vereinsbuchhaltung\Service\Statement\RowNormalizer;
use OCA\Vereinsbuchhaltung\Service\Statement\StatementArchive;
use OCA\Vereinsbuchhaltung\Service\Statement\StatementBalance;
use OCA\Vereinsbuchhaltung\Service\Statement\StatementParserRegistry;
use PHPUnit\Framework\TestCase;

/**
 * CAMT.052 und Kontoauszüge im ZIP-Archiv (Issue #359): manche Institute
 * liefern statt CAMT.053 nur Tagesreports im CAMT.052-Format, gebündelt als
 * ZIP mit einer XML-Datei je Tag.
 */
class StatementArchiveTest extends TestCase {
	private StatementParserRegistry $registry;

	protected function setUp(): void {
		if (!class_exists(\ZipArchive::class)) {
			$this->markTestSkipped('PHP-Erweiterung zip fehlt');
		}
		$n = new RowNormalizer();
		$this->registry = new StatementParserRegistry(new Camt053Parser($n), new Mt940Parser($n), new CamtCsvParser($n));
	}

	/** Ein Buchungseintrag; $status als <Sts><Cd> wie in camt.052.001.08. */
	private function ntry(string $date, string $amount, string $ind, string $purpose, string $status = 'BOOK'): string {
		return "<Ntry><Amt Ccy=\"EUR\">{$amount}</Amt><CdtDbtInd>{$ind}</CdtDbtInd><Sts><Cd>{$status}</Cd></Sts>"
			. "<BookgDt><Dt>{$date}</Dt></BookgDt><ValDt><Dt>{$date}</Dt></ValDt>"
			. "<NtryDtls><TxDtls><RltdPties><Dbtr><Nm>Max Mustermann</Nm></Dbtr></RltdPties>"
			. "<RmtInf><Ustrd>{$purpose}</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry>";
	}

	private function bal(string $code, string $amount, string $date): string {
		return "<Bal><Tp><CdOrPrtry><Cd>{$code}</Cd></CdOrPrtry></Tp><Amt Ccy=\"EUR\">{$amount}</Amt>"
			. "<CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>{$date}</Dt></Dt></Bal>";
	}

	/** Ein CAMT.052-Tagesreport mit <Rpt> statt <Stmt>. */
	private function camt052(string $body): string {
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.052.001.08"><BkToCstmrAcctRpt>'
			. '<GrpHdr><MsgId>M1</MsgId><CreDtTm>2026-01-03T08:00:00</CreDtTm></GrpHdr>'
			. '<Rpt><Id>R1</Id><Acct><Id><IBAN>DE12500105170648489890</IBAN></Id></Acct>'
			. $body
			. '</Rpt></BkToCstmrAcctRpt></Document>';
	}

	/** @param array<string, string> $files Name => Inhalt */
	private function zip(array $files): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'vbh-test-');
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::OVERWRITE);
		foreach ($files as $name => $content) {
			$zip->addFromString($name, $content);
		}
		$zip->close();
		$data = (string)file_get_contents($path);
		unlink($path);
		return $data;
	}

	// --- CAMT.052 ---------------------------------------------------------

	public function testCamt052WirdErkanntUndGelesen(): void {
		$xml = $this->camt052(
			$this->ntry('2026-01-02', '60.00', 'CRDT', 'Mitgliedsbeitrag 2026')
			. $this->ntry('2026-01-02', '12.00', 'DBIT', 'Vorgemerkt', 'PDNG')
		);

		[$rows, $source] = $this->registry->parse($xml);

		$this->assertSame('camt', $source);
		$this->assertCount(1, $rows, 'vorgemerkte Posten bleiben wie bei CAMT.053 draußen');
		$this->assertSame('2026-01-02', $rows[0]['bookingDate']);
		$this->assertSame(6000, $rows[0]['amountCents']);
		$this->assertSame('Max Mustermann', $rows[0]['counterparty']);
	}

	public function testCamt052OhneBuchungenIstEinzelnEinFehler(): void {
		$this->expectException(UserFacingException::class);
		$this->registry->parse($this->camt052(''));
	}

	public function testCamt052Salden(): void {
		$balances = $this->registry->balances($this->camt052(
			$this->bal('OPBD', '100.00', '2026-01-02') . $this->bal('CLBD', '160.00', '2026-01-02')
			. $this->ntry('2026-01-02', '60.00', 'CRDT', 'Beitrag')
		));

		$this->assertCount(1, $balances);
		$this->assertSame(10000, $balances[0]->openingCents);
		$this->assertSame(16000, $balances[0]->closingCents);
	}

	// --- ZIP ----------------------------------------------------------------

	public function testZipMitTagesreportenLiestAlleDateien(): void {
		$zip = $this->zip([
			'2026-01-03.xml' => $this->camt052($this->ntry('2026-01-03', '25.00', 'CRDT', 'Spende')),
			'2026-01-02.xml' => $this->camt052($this->ntry('2026-01-02', '60.00', 'CRDT', 'Mitgliedsbeitrag 2026')),
			// Ein Tag ohne Umsatz: im Archiv kein Fehler.
			'2026-01-04.xml' => $this->camt052(''),
			'__MACOSX/._2026-01-02.xml' => 'Ressourcengabel',
			'.DS_Store' => 'macOS',
			'unterordner/' => '',
		]);

		[$rows, $source] = $this->registry->parse($zip);

		$this->assertSame('camt', $source);
		$this->assertSame(['2026-01-02', '2026-01-03'], array_column($rows, 'bookingDate'), 'nach Dateinamen sortiert');
	}

	/**
	 * Untertägige Reports melden eine Buchung mehrfach. Das ist kein Sonderfall
	 * des Archivs: ImportService erkennt doppelte Zeilen derselben Datei am
	 * Dedup-Hash. Hier zählt nur, dass beide Dateien denselben Hash liefern.
	 */
	public function testDieselbeBuchungInZweiReportsHatDenselbenHash(): void {
		$ntry = $this->ntry('2026-01-02', '60.00', 'CRDT', 'Mitgliedsbeitrag 2026');
		[$rows] = $this->registry->parse($this->zip(['a.xml' => $this->camt052($ntry), 'b.xml' => $this->camt052($ntry)]));

		$this->assertCount(2, $rows);
		$this->assertSame($rows[0]['hash'], $rows[1]['hash']);
	}

	public function testZipMitCamt053UndMt940(): void {
		$fixtures = __DIR__ . '/../fixtures/';
		[$camt] = $this->registry->parse($this->zip(['auszug.xml' => (string)file_get_contents($fixtures . 'beispiel-camt053.xml')]));
		[$mt940, $source] = $this->registry->parse($this->zip(['auszug.sta' => (string)file_get_contents($fixtures . 'beispiel-mt940.sta')]));

		$this->assertCount(5, $camt);
		$this->assertCount(5, $mt940);
		$this->assertSame('mt940', $source);
	}

	public function testZipMitGemischtenFormatenWirdAbgelehnt(): void {
		$zip = $this->zip([
			'a.xml' => $this->camt052($this->ntry('2026-01-02', '60.00', 'CRDT', 'Beitrag')),
			'b.sta' => (string)file_get_contents(__DIR__ . '/../fixtures/beispiel-mt940.sta'),
		]);

		$this->expectException(UserFacingException::class);
		$this->expectExceptionMessage('verschiedenen Formaten');
		$this->registry->parse($zip);
	}

	public function testZipOhneBuchungenWirdAbgelehnt(): void {
		$this->expectException(UserFacingException::class);
		$this->expectExceptionMessage('keine Buchungen');
		$this->registry->parse($this->zip(['leer.xml' => $this->camt052(''), '.hidden' => 'x']));
	}

	public function testBeschaedigtesZipWirdAbgelehnt(): void {
		$this->expectException(UserFacingException::class);
		$this->expectExceptionMessage('beschädigt');
		$this->registry->parse("PK\x03\x04" . str_repeat('kaputt', 20));
	}

	/** Eine „ZIP-Bombe“: wenige Kilobyte gepackt, entpackt über der Grenze. */
	public function testZuGrossEntpacktWirdAbgelehnt(): void {
		$zip = $this->zip(['gross.xml' => str_repeat('A', StatementArchive::MAX_TOTAL_BYTES + 1)]);
		$this->assertLessThan(1024 * 1024, strlen($zip));

		$this->expectException(UserFacingException::class);
		$this->expectExceptionMessage('zu groß');
		$this->registry->parse($zip);
	}

	public function testZipSaldenUeberMehrereTage(): void {
		$zip = $this->zip([
			'1.xml' => $this->camt052($this->bal('OPBD', '100.00', '2026-01-02') . $this->bal('CLBD', '160.00', '2026-01-02')
				. $this->ntry('2026-01-02', '60.00', 'CRDT', 'Beitrag')),
			'2.xml' => $this->camt052($this->bal('OPBD', '160.00', '2026-01-03') . $this->bal('CLBD', '185.00', '2026-01-03')
				. $this->ntry('2026-01-03', '25.00', 'CRDT', 'Spende')),
		]);

		$balances = $this->registry->balances($zip);

		$this->assertCount(1, $balances);
		$this->assertSame(10000, $balances[0]->openingCents);
		$this->assertSame('2026-01-02', $balances[0]->openingDate);
		$this->assertSame(18500, $balances[0]->closingCents);
		$this->assertSame('2026-01-03', $balances[0]->closingDate);
		$this->assertSame([], $balances[0]->gaps);
	}

	// --- Salden zusammenführen ----------------------------------------------

	public function testMergeMeldetBruchZwischenAuszuegen(): void {
		$merged = StatementParserRegistry::mergeBalances([
			new StatementBalance('DE1', 18000, '2026-01-03', 20000, '2026-01-03'),
			new StatementBalance('DE1', 10000, '2026-01-02', 16000, '2026-01-02'),
			new StatementBalance('DE2', 500, '2026-01-02', 700, '2026-01-02'),
		]);

		$this->assertCount(2, $merged);
		$this->assertSame(10000, $merged[0]->openingCents);
		$this->assertSame(20000, $merged[0]->closingCents);
		$this->assertSame([['date' => '2026-01-03', 'expectedCents' => 16000, 'actualCents' => 18000]], $merged[0]->gaps);
		$this->assertSame('DE2', $merged[1]->ownAccount);
		$this->assertSame([], $merged[1]->gaps);
	}

	public function testNormaleDateiBleibtUnberuehrt(): void {
		$this->assertFalse(StatementArchive::isZip((string)file_get_contents(__DIR__ . '/../fixtures/beispiel-camt.csv')));
		[$rows, $source] = $this->registry->parse((string)file_get_contents(__DIR__ . '/../fixtures/beispiel-camt.csv'));
		$this->assertSame('csv', $source);
		$this->assertCount(5, $rows);
	}
}
