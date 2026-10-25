<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceLine;
use OCA\Vereinsbuchhaltung\Service\EInvoice\EInvoiceData;
use OCA\Vereinsbuchhaltung\Service\EInvoice\EInvoiceException;
use OCA\Vereinsbuchhaltung\Service\EInvoice\EInvoiceFormat;
use OCA\Vereinsbuchhaltung\Service\EInvoice\InvoiceSuiteEInvoiceWriter;
use OCA\Vereinsbuchhaltung\Service\EpcQrCodeGenerator;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoicePdfData;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoicePdfRenderer;
use PHPUnit\Framework\TestCase;

/**
 * E-Rechnungs-XML aus einer Rechnung: Schemaprüfung für jedes Format, die
 * Inhalte nach EN 16931 und das Einbetten ins PDF mit Rücklesen.
 */
class InvoiceSuiteEInvoiceWriterTest extends TestCase {

	protected function setUp(): void {
		if (!class_exists(\horstoeko\invoicesuite\InvoiceSuiteDocumentBuilder::class)) {
			$this->markTestSkipped('horstoeko/invoicesuite ist nicht installiert (composer install).');
		}
	}

	/** @param list<array{name: string, qty: int, price: int, discountBp?: int, category?: string, rate?: int}> $rows */
	private function data(string $taxMode = Invoice::TAX_REGULAR, bool $credit = false, ?array $rows = null, bool $complete = true): EInvoiceData {
		$rows ??= [
			['name' => 'Kursgebühr', 'qty' => 10000, 'price' => 10000, 'category' => 'S', 'rate' => 1900],
			['name' => 'Eintritt', 'qty' => 20000, 'price' => 2500, 'discountBp' => 1000, 'category' => 'S', 'rate' => 700],
			['name' => 'Raumnutzung', 'qty' => 10000, 'price' => 3000, 'category' => 'E', 'rate' => 0],
		];
		$lines = [];
		$net = 0;
		foreach ($rows as $i => $row) {
			$l = new InvoiceLine();
			$l->setPosition($i + 1);
			$l->setName($row['name']);
			$l->setQuantityE4($credit ? -$row['qty'] : $row['qty']);
			$l->setUnitCode('LS');
			$l->setUnitPriceCents($row['price']);
			$l->setDiscountBp($row['discountBp'] ?? 0);
			$l->setTaxCategory($taxMode === Invoice::TAX_REGULAR ? ($row['category'] ?? 'S') : 'E');
			$l->setTaxRateBp($taxMode === Invoice::TAX_REGULAR ? ($row['rate'] ?? 1900) : 0);
			$cents = \OCA\Vereinsbuchhaltung\Service\InvoiceCalculator::lineNet($row['qty'], $row['price'], $row['discountBp'] ?? 0);
			$l->setLineNetCents($credit ? -$cents : $cents);
			$net += $l->getLineNetCents();
			$lines[] = $l;
		}
		$totals = \OCA\Vereinsbuchhaltung\Service\InvoiceCalculator::totals(array_map(static fn ($l) => [
			'lineNetCents' => $l->getLineNetCents(), 'taxRateBp' => $l->getTaxRateBp(), 'taxCategory' => $l->getTaxCategory(),
		], $lines), $taxMode);
		$inv = new Invoice();
		$inv->setId(1);
		$inv->setKind($credit ? Invoice::KIND_CREDIT : Invoice::KIND_INVOICE);
		$inv->setNumber($credit ? 'ST-2026-0001' : 'RE-2026-0042');
		$inv->setIssueDate('2026-03-05');
		$inv->setDeliveryDate('2026-03-01');
		$inv->setDueDate('2026-03-19');
		$inv->setCurrency('EUR');
		$inv->setTaxMode($taxMode);
		$inv->setExemptionText($taxMode === Invoice::TAX_REGULAR ? 'Steuerfrei nach § 4 Nr. 12 UStG' : 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.');
		$inv->setNetCents($totals['netCents']);
		$inv->setTaxCents($totals['taxCents']);
		$inv->setGrossCents($totals['grossCents']);
		$inv->setNoteHead('vielen Dank für Ihren Auftrag.');
		$inv->setPaymentTerms('Zahlbar innerhalb von 14 Tagen ohne Abzug.');
		$inv->setBuyerReference('991-01234-44');
		$issuer = ['name' => 'TSV Beispiel e. V.', 'street' => 'Hauptstr. 1', 'postalCode' => '12345', 'city' => 'Beispielstadt', 'country' => 'DE',
			'taxNumber' => '12/345/67890', 'vatId' => '', 'registerCourt' => 'AG Beispielstadt', 'registerNo' => 'VR 1', 'representatives' => 'Erika Muster, 1. Vorsitzende',
			'phone' => '0123 456789', 'email' => 'kasse@tsv-beispiel.example'];
		$debtor = ['name' => 'Stadt Beispielstadt', 'street' => 'Rathausplatz 1', 'postalCode' => '12345', 'city' => 'Beispielstadt', 'country' => 'DE',
			'customerNo' => 'K-17', 'email' => 'rechnung@beispielstadt.example', 'leitwegId' => '991-01234-44', 'vatId' => null];
		if (!$complete) {
			$issuer['phone'] = '';
			$issuer['email'] = '';
			$debtor['email'] = '';
		}
		return new EInvoiceData($inv, $lines, $debtor, $issuer, ['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001', 'holder' => 'TSV Beispiel e. V.'], $credit ? 'RE-2026-0001' : null, $credit ? '2026-02-01' : null);
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
