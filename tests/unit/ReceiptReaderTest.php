<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\EInvoice\EInvoiceFormat;
use OCA\Vereinsbuchhaltung\Service\EInvoice\InvoiceSuiteEInvoiceWriter;
use OCA\Vereinsbuchhaltung\Service\EpcQrCodeGenerator;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoicePdfData;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoicePdfRenderer;
use OCA\Vereinsbuchhaltung\Service\ReceiptIndex\ReceiptFacts;
use OCA\Vereinsbuchhaltung\Service\ReceiptIndex\ReceiptReader;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\EInvoiceFixture;
use PHPUnit\Framework\TestCase;

/**
 * Belegdatei lesen: Dateiname, PDF-Textebene und eingebettete E-Rechnung.
 */
class ReceiptReaderTest extends TestCase {
	use EInvoiceFixture;

	private function reader(): ReceiptReader {
		return new ReceiptReader(null, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-31'));
	}

	private function textPdf(?string ...$lines): string {
		if (!class_exists(\TCPDF::class)) {
			$this->markTestSkipped('TCPDF fehlt');
		}
		$pdf = new \TCPDF();
		$pdf->setPrintHeader(false);
		$pdf->setPrintFooter(false);
		$pdf->AddPage();
		$pdf->setCompression(false);
		$pdf->SetFont('helvetica', '', 12);
		foreach ($lines as $line) {
			if ($line !== null) {
				$pdf->Cell(0, 8, $line, 0, 1);
			}
		}
		// TCPDF schreibt je Seite unsichtbar "Powered by TCPDF"; gleich lang durch Leerzeichen ersetzen (Offsets bleiben gültig).
		$marker = '(Powered by TCPDF \(www.tcpdf.org\))';
		return str_replace($marker, '(' . str_repeat(' ', strlen($marker) - 2) . ')', $pdf->Output('', 'S'));
	}

	public function testBildZaehltNurDenDateinamen(): void {
		$facts = $this->reader()->read('RG-28-26.jpg', 'image/jpeg', 1000, static fn () => throw new \LogicException('nicht lesen'));

		$this->assertSame(ReceiptFacts::STATUS_SKIPPED, $facts->status);
		$this->assertSame(ReceiptFacts::SOURCE_NAME, $facts->source);
		$this->assertSame(['2826'], $facts->refs);
		$this->assertSame(['2826'], $facts->nameRefs);
	}

	public function testGrossesPdfWirdNichtGelesen(): void {
		$called = false;
		$facts = $this->reader()->read('RG-2026-4711.pdf', 'application/pdf', 16 * 1024 * 1024, function () use (&$called): string {
			$called = true;
			return '';
		});

		$this->assertFalse($called);
		$this->assertSame(ReceiptFacts::STATUS_SKIPPED, $facts->status);
		$this->assertSame(['20264711'], $facts->refs);
	}

	public function testTextPdfLiefertKerneBetraegeDaten(): void {
		$bytes = $this->textPdf('Rechnung Nr. R-2026-4711', 'Datum 15.10.2026', 'Gesamt 119,00 €');

		$facts = $this->reader()->read('scan.pdf', 'application/pdf', strlen($bytes), static fn () => $bytes);

		$this->assertSame(ReceiptFacts::SOURCE_TEXT, $facts->source);
		$this->assertSame(ReceiptFacts::STATUS_OK, $facts->status);
		$this->assertContains('20264711', $facts->refs);
		$this->assertSame([], $facts->nameRefs);
		$this->assertContains(11900, $facts->amounts);
		$this->assertContains('2026-10-15', $facts->dates);
	}

	public function testPdfOhneTextIstNoText(): void {
		$bytes = $this->textPdf();

		$facts = $this->reader()->read('RG-2026-4711.pdf', 'application/pdf', strlen($bytes), static fn () => $bytes);

		$this->assertSame(ReceiptFacts::STATUS_NO_TEXT, $facts->status);
		$this->assertSame(ReceiptFacts::SOURCE_NAME, $facts->source);
		$this->assertSame(['20264711'], $facts->refs);
	}

	public function testHybridPdfLiefertRechnungsfelder(): void {
		if (!class_exists(\TCPDF::class) || !class_exists(\horstoeko\invoicesuite\InvoiceSuiteDocumentBuilder::class)) {
			$this->markTestSkipped('TCPDF oder horstoeko/invoicesuite fehlt');
		}
		$data = $this->data();
		$writer = new InvoiceSuiteEInvoiceWriter();
		$xml = $writer->buildXml($data, EInvoiceFormat::ZUGFERD);
		$pdfData = new InvoicePdfData($data->invoice, $data->lines, $data->debtor, $data->issuer, $data->bank);
		$pdf = (new InvoicePdfRenderer(new EpcQrCodeGenerator()))->render($pdfData);
		$hybrid = $writer->embedIntoPdf($xml, $pdf, EInvoiceFormat::ZUGFERD);

		$facts = $this->reader()->read('beleg.pdf', 'application/pdf', strlen($hybrid), static fn () => $hybrid);

		$this->assertSame(ReceiptFacts::SOURCE_EINVOICE, $facts->source);
		$this->assertSame(ReceiptFacts::STATUS_OK, $facts->status);
		$this->assertSame('RE-2026-0042', $facts->invoiceNo);
		$this->assertSame($data->invoice->getGrossCents(), $facts->totalCents);
		$this->assertSame('2026-03-05', $facts->issueDate);
		$this->assertSame('TSV Beispiel e. V.', $facts->vendor);
		$this->assertContains('20260042', $facts->refs);
		$this->assertContains($data->invoice->getGrossCents(), $facts->amounts);
		$this->assertContains('2026-03-05', $facts->dates);
	}

	public function testKaputteBytesSindUnreadable(): void {
		$facts = $this->reader()->read('RG-2026-4711.pdf', 'application/pdf', 15, static fn () => '%PDF-1.4 kaputt');

		$this->assertSame(ReceiptFacts::STATUS_UNREADABLE, $facts->status);
		$this->assertSame(['20264711'], $facts->refs);
	}

	public function testFactsLassenSichUmwandeln(): void {
		$facts = new ReceiptFacts('einvoice', 'ok', ['1234'], ['1234'], [11900], ['2026-10-15'], 'R-1234', 11900, '2026-10-15', 'Firma');

		$this->assertEquals($facts, ReceiptFacts::fromArray($facts->toArray()));
	}
}
