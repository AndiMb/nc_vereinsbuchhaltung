<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceDocument;
use OCA\Vereinsbuchhaltung\Db\InvoiceDocumentMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceMapper;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\EInvoice\EInvoiceData;
use OCA\Vereinsbuchhaltung\Service\EInvoice\EInvoiceException;
use OCA\Vereinsbuchhaltung\Service\EInvoice\EInvoiceFormat;
use OCA\Vereinsbuchhaltung\Service\EInvoice\EInvoiceService;
use OCA\Vereinsbuchhaltung\Service\EInvoice\EInvoiceWriter;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceDocumentService;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceDocumentStorage;
use OCA\Vereinsbuchhaltung\Service\InvoiceSettings;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * E-Rechnung ablegen: Prüfung vor dem Speichern, ein Dokument je Format,
 * Hybrid-PDF für ZUGFeRD, Vorschlag nach Art des Empfängers.
 */
class EInvoiceServiceTest extends TestCase {

	private Invoice $invoice;
	private InvoiceMapper&MockObject $invoices;
	private InvoiceDocumentMapper&MockObject $documents;
	private InvoiceDocumentService&MockObject $documentService;
	private InvoiceDocumentStorage&MockObject $storage;
	private InvoiceSettings&MockObject $settings;
	private EInvoiceWriter&MockObject $writer;

	protected function setUp(): void {
		$this->invoice = new Invoice();
		$this->invoice->setId(7);
		$this->invoice->setKind(Invoice::KIND_INVOICE);
		$this->invoice->setStatus(Invoice::STATUS_ISSUED);
		$this->invoice->setIssuedAt('2026-03-05T10:00:00+00:00');
		$this->invoice->setNumber('RE-2026-0007');
		$this->invoice->setDebtorSnapshot(json_encode(['name' => 'Muster GmbH', 'kind' => 'organisation']));
	}

	private function service(): EInvoiceService {
		$this->invoices = $this->createMock(InvoiceMapper::class);
		$this->invoices->method('find')->willReturn($this->invoice);
		$this->documents = $this->createMock(InvoiceDocumentMapper::class);
		$this->documentService = $this->createMock(InvoiceDocumentService::class);
		$this->documentService->method('eInvoiceData')->willReturn(new EInvoiceData($this->invoice, [], ['name' => 'Muster GmbH'], [], null));
		$this->storage = $this->createMock(InvoiceDocumentStorage::class);
		$this->settings = $this->createMock(InvoiceSettings::class);
		$this->writer = $this->createMock(EInvoiceWriter::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $params = []) => vsprintf($text, (array)$params));
		return new EInvoiceService($this->invoices, $this->documents, $this->documentService, $this->storage, $this->settings, $this->writer, $this->createMock(AuditService::class), $l10n);
	}

	private function stored(string $kind): InvoiceDocument {
		$doc = new InvoiceDocument();
		$doc->setId(3);
		$doc->setKind($kind);
		$doc->setSha256('abc');
		return $doc;
	}

	public function testXRechnungWirdGeprueftUndAlsXmlAbgelegt(): void {
		$s = $this->service();
		$this->writer->method('buildXml')->willReturn('<xml/>');
		$this->writer->method('validate')->willReturn([]);
		$this->writer->expects($this->never())->method('embedIntoPdf');
		$this->storage->expects($this->once())->method('store')
			->with($this->invoice, 'xrechnung_cii', '<xml/>', 'xml', 'XRechnung 3.0', '-xrechnung')
			->willReturn($this->stored('xrechnung_cii'));

		$doc = $s->generate(7, EInvoiceFormat::XRECHNUNG_CII);

		$this->assertSame('xrechnung_cii', $doc->getKind());
	}

	public function testZugferdBettetDasXmlInDasPdfEin(): void {
		$s = $this->service();
		$this->writer->method('buildXml')->willReturn('<xml/>');
		$this->writer->method('validate')->willReturn([]);
		$this->documentService->method('pdfContent')->willReturn(['content' => '%PDF', 'fileName' => 'x.pdf']);
		$this->writer->expects($this->once())->method('embedIntoPdf')->with('<xml/>', '%PDF', 'zugferd')->willReturn('%PDF-hybrid');
		$this->writer->method('extractXml')->willReturn('<xml/>');
		$this->storage->expects($this->once())->method('store')
			->with($this->invoice, 'zugferd_pdf', '%PDF-hybrid', 'pdf', 'EN 16931', '-zugferd')
			->willReturn($this->stored('zugferd_pdf'));

		$s->generate(7, EInvoiceFormat::ZUGFERD);
	}

	public function testSchemafehlerVerhindernDasSpeichern(): void {
		$s = $this->service();
		$this->writer->method('buildXml')->willReturn('<xml/>');
		$this->writer->method('validate')->willReturn(['Element fehlt']);
		$this->storage->expects($this->never())->method('store');

		try {
			$s->generate(7, EInvoiceFormat::XRECHNUNG_UBL);
			$this->fail('Ausnahme erwartet');
		} catch (EInvoiceException $e) {
			$this->assertSame(['Element fehlt'], $e->getProblems());
		}
	}

	public function testEingebettetesXmlMussDemErzeugtenEntsprechen(): void {
		$s = $this->service();
		$this->writer->method('buildXml')->willReturn('<xml/>');
		$this->writer->method('validate')->willReturn([]);
		$this->documentService->method('pdfContent')->willReturn(['content' => '%PDF', 'fileName' => 'x.pdf']);
		$this->writer->method('embedIntoPdf')->willReturn('%PDF-hybrid');
		$this->writer->method('extractXml')->willReturn('<anders/>');
		$this->storage->expects($this->never())->method('store');

		$this->expectException(EInvoiceException::class);
		$s->generate(7, EInvoiceFormat::ZUGFERD);
	}

	public function testVorhandenesDokumentBleibtUnveraendert(): void {
		$s = $this->service();
		$existing = $this->stored('xrechnung_cii');
		$this->documents->method('findByInvoiceAndKind')->willReturn($existing);
		$this->storage->method('readOrNull')->willReturn('<alt/>');
		$this->writer->expects($this->never())->method('buildXml');
		$this->storage->expects($this->never())->method('store');

		$this->assertSame($existing, $s->generate(7, EInvoiceFormat::XRECHNUNG_CII));
	}

	public function testEntwurfUndUnbekanntesFormatWerdenAbgelehnt(): void {
		$s = $this->service();
		try {
			$s->generate(7, 'edifact');
			$this->fail('Ausnahme erwartet');
		} catch (\InvalidArgumentException) {
		}
		$this->invoice->setIssuedAt(null);
		$this->expectException(\InvalidArgumentException::class);
		$s->generate(7, EInvoiceFormat::ZUGFERD);
	}

	public function testVorschlagRichtetSichNachDemEmpfaenger(): void {
		$this->assertSame('xrechnung_cii', EInvoiceFormat::suggestFor('behoerde'));
		$this->assertSame('zugferd', EInvoiceFormat::suggestFor('organisation'));
		$this->assertNull(EInvoiceFormat::suggestFor('person'));
		$this->assertNull(EInvoiceFormat::suggestFor(null));
	}

	public function testBeimFestschreibenEntstehtEineERechnungNurWennEingeschaltet(): void {
		$s = $this->service();
		$this->settings->method('eInvoiceAuto')->willReturn(false);
		$this->writer->expects($this->never())->method('buildXml');

		$this->assertNull($s->generateSuggested(7));
	}

	public function testFehlendeAngabenBeimAutomatischenErzeugenSindKeinFehler(): void {
		$s = $this->service();
		$this->settings->method('eInvoiceAuto')->willReturn(true);
		$this->writer->method('buildXml')->willThrowException(new EInvoiceException('fehlt', ['Anschrift']));

		$this->assertNull($s->generateSuggested(7));
	}
}
