<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceDocument;
use OCA\Vereinsbuchhaltung\Db\InvoiceDocumentMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceDunningMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Service\Export\InvoiceArchive;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceDocumentStorage;
use OCA\Vereinsbuchhaltung\Service\InvoiceSettings;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/** Rechnungen im Prüfungs-ZIP: Dokumente je Rechnung, Übersicht, Prüfsummen mit Befund. */
class InvoiceArchiveTest extends TestCase {

	private string $zipPath;
	private bool $active = true;
	/** @var array<int, string|null> Dokument-ID => Inhalt */
	private array $files = [];

	protected function setUp(): void {
		$this->zipPath = tempnam(sys_get_temp_dir(), 'vbhzip') ?: '';
		$this->active = true;
		$this->files = [];
	}

	protected function tearDown(): void {
		@unlink($this->zipPath);
	}

	private function invoice(int $id, string $number, string $status, int $gross, string $kind = Invoice::KIND_INVOICE): Invoice {
		$invoice = new Invoice();
		$invoice->setId($id);
		$invoice->setNumber($number);
		$invoice->setKind($kind);
		$invoice->setStatus($status);
		$invoice->setIssuedAt('2026-03-05T10:00:00+00:00');
		$invoice->setIssueDate('2026-03-05');
		$invoice->setDueDate('2026-03-19');
		$invoice->setNetCents($gross);
		$invoice->setTaxCents(0);
		$invoice->setGrossCents($gross);
		$invoice->setOpenItemId($id + 100);
		$invoice->setDebtorSnapshot(json_encode(['name' => 'Muster GmbH']));
		return $invoice;
	}

	private function document(int $id, int $invoiceId, string $kind, string $name, string $content, ?string $storedContent = null): InvoiceDocument {
		$doc = new InvoiceDocument();
		$doc->setId($id);
		$doc->setInvoiceId($invoiceId);
		$doc->setKind($kind);
		$doc->setFileName($name);
		$doc->setSha256(hash('sha256', $content));
		$this->files[$id] = $storedContent ?? $content;
		return $doc;
	}

	/**
	 * @param list<Invoice> $invoices
	 * @param list<InvoiceDocument> $docs
	 */
	private function archive(array $invoices, array $docs): InvoiceArchive {
		$mapper = $this->createMock(InvoiceMapper::class);
		$mapper->method('findIssuedBetween')->willReturn($invoices);
		$docMapper = $this->createMock(InvoiceDocumentMapper::class);
		$docMapper->method('findAll')->willReturn($docs);
		$storage = $this->createMock(InvoiceDocumentStorage::class);
		$storage->method('readOrNull')->willReturnCallback(fn (InvoiceDocument $d) => $this->files[(int)$d->getId()] ?? null);
		$openItems = $this->createMock(OpenItemMapper::class);
		$openItems->method('findByIds')->willReturnCallback(static function (): array {
			$item = new OpenItem();
			$item->setId(101);
			$item->setAmountCents(15000);
			$item->setSettledCents(5000);
			return [101 => $item];
		});
		$dunnings = $this->createMock(InvoiceDunningMapper::class);
		$dunnings->method('lastByInvoices')->willReturn([1 => ['level' => 2, 'sentAt' => '2026-04-01']]);
		$settings = $this->createMock(InvoiceSettings::class);
		$settings->method('isActive')->willReturnCallback(fn () => $this->active);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $params = []) => vsprintf($text, (array)$params));
		return new InvoiceArchive($mapper, $docMapper, $storage, $openItems, $dunnings, $settings, $l10n);
	}

	/** @return array<string, string> */
	private function read(): array {
		$zip = new \ZipArchive();
		$this->assertTrue($zip->open($this->zipPath));
		$out = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$out[(string)$zip->getNameIndex($i)] = (string)$zip->getFromIndex($i);
		}
		$zip->close();
		return $out;
	}

	private function build(InvoiceArchive $archive): int {
		$zip = new \ZipArchive();
		$zip->open($this->zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
		$count = $archive->addTo($zip, '2026-01-01', '2026-12-31');
		$zip->close();
		return $count;
	}

	public function testRechnungMitDokumentenUebersichtUndPruefsummen(): void {
		$archive = $this->archive(
			[$this->invoice(1, 'RE-2026-0001', Invoice::STATUS_SENT, 15000)],
			[$this->document(10, 1, 'pdf', 'RE-2026-0001.pdf', '%PDF-eins'), $this->document(11, 1, 'xrechnung_cii', 'RE-2026-0001-xrechnung.xml', '<xml/>')],
		);

		$this->assertSame(1, $this->build($archive));
		$files = $this->read();

		$this->assertSame('%PDF-eins', $files['rechnungen/RE-2026-0001/RE-2026-0001.pdf']);
		$this->assertSame('<xml/>', $files['rechnungen/RE-2026-0001/RE-2026-0001-xrechnung.xml']);
		$this->assertStringContainsString(hash('sha256', '%PDF-eins') . '  rechnungen/RE-2026-0001/RE-2026-0001.pdf  ok', $files['rechnungen/pruefsummen.txt']);
		$overview = $files['rechnungen/uebersicht.csv'];
		$this->assertStringStartsWith("\xEF\xBB\xBF", $overview);
		$this->assertStringContainsString('"RE-2026-0001";"Rechnung";"2026-03-05";"2026-03-19";"Muster GmbH";"150,00";"0,00";"150,00";"50,00";"100,00";"Versendet";"2"', $overview);
	}

	public function testVeraenderteUndFehlendeDateienSindBefunde(): void {
		$archive = $this->archive(
			[$this->invoice(1, 'RE-2026-0001', Invoice::STATUS_ISSUED, 15000)],
			[
				$this->document(10, 1, 'pdf', 'a.pdf', 'original', 'manipuliert'),
				$this->document(11, 1, 'pdf', 'b.pdf', 'weg'),
			],
		);
		$this->files[11] = null;

		$this->build($archive);
		$sums = $this->read()['rechnungen/pruefsummen.txt'];

		$this->assertStringContainsString('rechnungen/RE-2026-0001/a.pdf  changed', $sums);
		$this->assertStringContainsString('rechnungen/RE-2026-0001/b.pdf  missing', $sums);
	}

	public function testStornorechnungHatKeinenZahlungsstand(): void {
		$archive = $this->archive([$this->invoice(1, 'ST-2026-0001', Invoice::STATUS_ISSUED, -15000, Invoice::KIND_CREDIT)], []);

		$this->build($archive);

		$this->assertStringContainsString('"ST-2026-0001";"Stornorechnung";"2026-03-05";"2026-03-19";"Muster GmbH";"-150,00";"0,00";"-150,00";"";"";"Stornorechnung"', $this->read()['rechnungen/uebersicht.csv']);
	}

	public function testOhneRechnungsmodulOderRechnungenBleibtDasArchivUnberuehrt(): void {
		$this->active = false;
		$this->assertSame(0, $this->build($this->archive([$this->invoice(1, 'RE-2026-0001', Invoice::STATUS_SENT, 15000)], [])));
		$this->assertFileDoesNotExist($this->zipPath, 'ein leeres Archiv legt ZipArchive gar nicht erst an');

		$this->active = true;
		$this->assertSame(0, $this->build($this->archive([], [])));
	}
}
