<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Service\EInvoice\EInvoiceException;
use OCA\Vereinsbuchhaltung\Service\EInvoice\EInvoiceFormat;
use OCA\Vereinsbuchhaltung\Service\EInvoice\InvoiceSuiteEInvoiceWriter;
use OCA\Vereinsbuchhaltung\Service\EpcQrCodeGenerator;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoicePdfData;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoicePdfRenderer;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\EInvoiceFixture;
use PHPUnit\Framework\TestCase;

/**
 * E-Rechnungs-XML aus einer Rechnung: Schemaprüfung für jedes Format, die
 * Inhalte nach EN 16931 und das Einbetten ins PDF mit Rücklesen.
 */
class InvoiceSuiteEInvoiceWriterTest extends TestCase {
	use EInvoiceFixture;

	protected function setUp(): void {
		if (!class_exists(\horstoeko\invoicesuite\InvoiceSuiteDocumentBuilder::class)) {
			$this->markTestSkipped('horstoeko/invoicesuite ist nicht installiert (composer install).');
		}
	}

	private function writer(): InvoiceSuiteEInvoiceWriter {
		return new InvoiceSuiteEInvoiceWriter();
	}

	/** @dataProvider formate */
	public function testJedesFormatIstSchemagueltig(string $format, string $taxMode, bool $credit): void {
		$xml = $this->writer()->buildXml($this->data($taxMode, $credit), $format);

		$this->assertSame([], $this->writer()->validate($xml, $format), "Schema-Fehler im $format");
		$this->assertStringContainsString($credit ? 'ST-2026-0001' : 'RE-2026-0042', $xml);
	}

	/** @return array<string, array{string, string, bool}> */
	public static function formate(): array {
		return [
			'ZUGFeRD mit Steuer' => [EInvoiceFormat::ZUGFERD, Invoice::TAX_REGULAR, false],
			'ZUGFeRD Kleinunternehmer' => [EInvoiceFormat::ZUGFERD, Invoice::TAX_SMALL_BUSINESS, false],
			'ZUGFeRD Storno' => [EInvoiceFormat::ZUGFERD, Invoice::TAX_REGULAR, true],
			'XRechnung CII mit Steuer' => [EInvoiceFormat::XRECHNUNG_CII, Invoice::TAX_REGULAR, false],
			'XRechnung CII Storno' => [EInvoiceFormat::XRECHNUNG_CII, Invoice::TAX_REGULAR, true],
			'XRechnung UBL mit Steuer' => [EInvoiceFormat::XRECHNUNG_UBL, Invoice::TAX_REGULAR, false],
			'XRechnung UBL Kleinunternehmer' => [EInvoiceFormat::XRECHNUNG_UBL, Invoice::TAX_SMALL_BUSINESS, false],
			'XRechnung UBL Storno (CreditNote)' => [EInvoiceFormat::XRECHNUNG_UBL, Invoice::TAX_REGULAR, true],
		];
	}

	/**
	 * Ohne diese Einschränkung lädt die Bibliothek beim ersten Aufruf jede Klasse aller
	 * Composer-Autoloader – in einer Nextcloud auch deren eigene, von denen manche mit einem
	 * nicht abfangbaren Fatal Error abstürzen (CI, frische Installation ohne Zwischenspeicher).
	 */
	public function testDieSucheNachFormatAnbieternBleibtImNamensraumDerBibliothek(): void {
		\horstoeko\invoicesuite\InvoiceSuiteSettings::setDiscoveryNamespaces([]);

		$this->writer()->buildXml($this->data(), EInvoiceFormat::ZUGFERD);

		$this->assertSame(['horstoeko\\invoicesuite'], \horstoeko\invoicesuite\InvoiceSuiteSettings::getDiscoveryNamespaces());
	}

	public function testZugferdTraegtSummenSteuerzeilenUndKaeuferreferenz(): void {
		$xml = $this->writer()->buildXml($this->data(), EInvoiceFormat::ZUGFERD);

		// 100 + 45 (2 × 25 − 10 %) + 30 = 175 netto; 19,00 + 3,15 USt; 197,15 brutto.
		$this->assertStringContainsString('<ram:LineTotalAmount>175.00</ram:LineTotalAmount>', $xml);
		$this->assertStringContainsString('<ram:TaxTotalAmount currencyID="EUR">22.15</ram:TaxTotalAmount>', $xml);
		$this->assertStringContainsString('<ram:GrandTotalAmount>197.15</ram:GrandTotalAmount>', $xml);
		$this->assertStringContainsString('<ram:DuePayableAmount>197.15</ram:DuePayableAmount>', $xml);
		$this->assertStringContainsString('991-01234-44', $xml);
		$this->assertStringContainsString('DE02120300000000202051', $xml);
		$this->assertStringContainsString('Steuerfrei nach § 4 Nr. 12 UStG', $xml);
		$this->assertStringContainsString('urn:cen.eu:en16931:2017', $xml, 'Profil EN 16931');
	}

	public function testKleinunternehmerAusweisenKeineSteuerMitBefreiungsgrund(): void {
		$xml = $this->writer()->buildXml($this->data(Invoice::TAX_SMALL_BUSINESS), EInvoiceFormat::ZUGFERD);

		$this->assertStringContainsString('<ram:CategoryCode>E</ram:CategoryCode>', $xml);
		$this->assertStringNotContainsString('<ram:CategoryCode>S</ram:CategoryCode>', $xml);
		$this->assertStringContainsString('Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.', $xml);
	}

	public function testStornoIstTyp381MitPositivenBetraegenUndBezugAufDieRechnung(): void {
		$xml = $this->writer()->buildXml($this->data(Invoice::TAX_REGULAR, true), EInvoiceFormat::XRECHNUNG_CII);

		$this->assertStringContainsString('<ram:TypeCode>381</ram:TypeCode>', $xml);
		$this->assertStringContainsString('<ram:GrandTotalAmount>197.15</ram:GrandTotalAmount>', $xml);
		$this->assertStringContainsString('RE-2026-0001', $xml, 'BT-25: Verweis auf die Ursprungsrechnung');
	}

	public function testUblUnterscheidetRechnungUndGutschrift(): void {
		$invoice = $this->writer()->buildXml($this->data(), EInvoiceFormat::XRECHNUNG_UBL);
		$credit = $this->writer()->buildXml($this->data(Invoice::TAX_REGULAR, true), EInvoiceFormat::XRECHNUNG_UBL);

		$this->assertStringContainsString('<Invoice', $invoice);
		$this->assertStringContainsString('<CreditNote', $credit);
	}

	public function testXRechnungVerlangtKontaktZahlungsangabenUndElektronischeAdressen(): void {
		try {
			$this->writer()->buildXml($this->data(Invoice::TAX_REGULAR, false, null, false), EInvoiceFormat::XRECHNUNG_CII);
			$this->fail('Ohne Kontakt fehlen Pflichtangaben.');
		} catch (EInvoiceException $e) {
			$this->assertContains('Telefonnummer des Vereins (Einstellungen)', $e->getProblems());
			$this->assertContains('E-Mail-Adresse des Vereins (Einstellungen)', $e->getProblems());
			$this->assertContains('E-Mail-Adresse des Empfängers (elektronische Adresse)', $e->getProblems());
		}
		// ZUGFeRD ist da nachsichtiger.
		$this->assertNotSame('', $this->writer()->buildXml($this->data(Invoice::TAX_REGULAR, false, null, false), EInvoiceFormat::ZUGFERD));
	}

	public function testHybridPdfLaesstSichEinbettenUndZuruecklesen(): void {
		if (!class_exists(\TCPDF::class)) {
			$this->markTestSkipped('TCPDF fehlt');
		}
		$data = $this->data();
		$xml = $this->writer()->buildXml($data, EInvoiceFormat::ZUGFERD);
		$pdfData = new InvoicePdfData($data->invoice, $data->lines, $data->debtor, $data->issuer, $data->bank);
		$pdf = (new InvoicePdfRenderer(new EpcQrCodeGenerator()))->render($pdfData);

		$hybrid = $this->writer()->embedIntoPdf($xml, $pdf, EInvoiceFormat::ZUGFERD);

		$this->assertStringStartsWith('%PDF-', $hybrid);
		$this->assertStringContainsString('factur-x.xml', $hybrid);
		$extracted = $this->writer()->extractXml($hybrid);
		$this->assertStringContainsString('RE-2026-0042', $extracted);
		$this->assertSame([], $this->writer()->validate($extracted, EInvoiceFormat::ZUGFERD));
	}

	public function testUnbekanntesFormatWirdAbgelehnt(): void {
		$this->expectException(EInvoiceException::class);
		$this->writer()->buildXml($this->data(), 'edifact');
	}

	/**
	 * Schreibt Beispielrechnungen in das Verzeichnis aus `EINVOICE_DUMP_DIR`, damit ein
	 * externer Prüfer (KoSIT-Validator, siehe CI-Job „E-Rechnung") sie gegen die
	 * Schematron-Regeln von EN 16931 und XRechnung prüfen kann. Ohne die Variable
	 * läuft nichts.
	 */
	public function testBeispieleFuerDieExternePruefungSchreiben(): void {
		$dir = getenv('EINVOICE_DUMP_DIR');
		if ($dir === false || $dir === '') {
			$this->markTestSkipped('EINVOICE_DUMP_DIR nicht gesetzt');
		}
		if (!is_dir($dir)) {
			mkdir($dir, 0777, true);
		}
		$samples = [
			'zugferd-steuer' => [EInvoiceFormat::ZUGFERD, Invoice::TAX_REGULAR, false],
			'zugferd-kleinunternehmer' => [EInvoiceFormat::ZUGFERD, Invoice::TAX_SMALL_BUSINESS, false],
			'zugferd-storno' => [EInvoiceFormat::ZUGFERD, Invoice::TAX_REGULAR, true],
			'xrechnung-cii-steuer' => [EInvoiceFormat::XRECHNUNG_CII, Invoice::TAX_REGULAR, false],
			'xrechnung-cii-kleinunternehmer' => [EInvoiceFormat::XRECHNUNG_CII, Invoice::TAX_SMALL_BUSINESS, false],
			'xrechnung-cii-storno' => [EInvoiceFormat::XRECHNUNG_CII, Invoice::TAX_REGULAR, true],
			'xrechnung-ubl-steuer' => [EInvoiceFormat::XRECHNUNG_UBL, Invoice::TAX_REGULAR, false],
			'xrechnung-ubl-kleinunternehmer' => [EInvoiceFormat::XRECHNUNG_UBL, Invoice::TAX_SMALL_BUSINESS, false],
			'xrechnung-ubl-storno' => [EInvoiceFormat::XRECHNUNG_UBL, Invoice::TAX_REGULAR, true],
		];
		foreach ($samples as $name => [$format, $mode, $credit]) {
			file_put_contents($dir . '/' . $name . '.xml', $this->writer()->buildXml($this->data($mode, $credit), $format));
		}
		$this->assertCount(count($samples), glob($dir . '/*.xml') ?: []);
	}
}
