<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Attachment;
use OCA\Vereinsbuchhaltung\Db\AttachmentMapper;
use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceDocument;
use OCA\Vereinsbuchhaltung\Db\InvoiceDocumentMapper;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceReceiptLinker;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Issue #366: Das Rechnungs-PDF hängt sich als verknüpfter Beleg an die Zahlungsbuchung.
 */
class InvoiceReceiptLinkerTest extends TestCase {

	private const STORAGE_USER = 'kassenwart';
	private const JOURNAL_ID = 55;
	private const FILE_ID = 4711;

	private InvoiceDocumentMapper&MockObject $documents;
	private AttachmentMapper&MockObject $attachments;
	private AttachmentStorageService&MockObject $storage;
	private IRootFolder&MockObject $rootFolder;
	private Folder&MockObject $home;
	private Invoice $invoice;

	protected function setUp(): void {
		$this->documents = $this->createMock(InvoiceDocumentMapper::class);
		$this->attachments = $this->createMock(AttachmentMapper::class);
		$this->storage = $this->createMock(AttachmentStorageService::class);
		$this->storage->method('storageUser')->willReturn(self::STORAGE_USER);
		$this->storage->method('attach')->willReturnCallback(static function (Attachment $a, File $file): void {
			$a->setFileId($file->getId());
			$a->setFileOwner(self::STORAGE_USER);
		});
		$this->home = $this->createMock(Folder::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->rootFolder->method('getUserFolder')->with(self::STORAGE_USER)->willReturn($this->home);
		$this->invoice = new Invoice();
		$this->invoice->setId(1);
	}

	private function linker(): InvoiceReceiptLinker {
		return new InvoiceReceiptLinker($this->documents, $this->attachments, $this->storage, $this->rootFolder, $this->createMock(LoggerInterface::class));
	}

	private function pdfDocument(int $fileId): InvoiceDocument {
		$doc = new InvoiceDocument();
		$doc->setInvoiceId(1);
		$doc->setKind(InvoiceDocument::KIND_PDF);
		$doc->setFileId($fileId);
		$doc->setFileName('RE-2026-0001.pdf');
		return $doc;
	}

	private function pdfFile(): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(self::FILE_ID);
		$file->method('getName')->willReturn('RE-2026-0001.pdf');
		$file->method('getMimetype')->willReturn('application/pdf');
		$file->method('getSize')->willReturn(12345);
		return $file;
	}

	public function testVerknuepftDasPdfOhneEsZuKopieren(): void {
		$this->documents->method('findByInvoiceAndKind')->with(1, InvoiceDocument::KIND_PDF)->willReturn($this->pdfDocument(self::FILE_ID));
		$file = $this->pdfFile();
		$file->expects($this->never())->method('getContent');
		$file->expects($this->never())->method('putContent');
		$this->home->method('getFirstNodeById')->with(self::FILE_ID)->willReturn($file);
		$this->attachments->method('findAllByJournal')->willReturn([]);
		$this->attachments->expects($this->once())->method('insert')->willReturnArgument(0);

		$attachment = $this->linker()->linkToJournal($this->invoice, self::JOURNAL_ID);

		$this->assertNotNull($attachment);
		$this->assertSame(self::JOURNAL_ID, $attachment->getJournalId());
		$this->assertSame(Application::BOOK, $attachment->getUserId());
		$this->assertSame('RE-2026-0001.pdf', $attachment->getFileName());
		$this->assertSame('application/pdf', $attachment->getMimeType());
		$this->assertSame(12345, $attachment->getFileSize());
		$this->assertSame(self::FILE_ID, $attachment->getFileId());
		$this->assertSame(self::STORAGE_USER, $attachment->getFileOwner());
	}

	/** Ohne Ablage-Nutzer liegt das PDF app-intern (file_id 0) – nichts zu verknüpfen. */
	public function testPdfImAppSpeicherWirdUebersprungen(): void {
		$this->documents->method('findByInvoiceAndKind')->willReturn($this->pdfDocument(0));
		$this->attachments->expects($this->never())->method('insert');

		$this->assertNull($this->linker()->linkToJournal($this->invoice, self::JOURNAL_ID));
	}

	public function testOhnePdfBleibtDieBuchungOhneBeleg(): void {
		$this->documents->method('findByInvoiceAndKind')->willReturn(null);
		$this->attachments->expects($this->never())->method('insert');

		$this->assertNull($this->linker()->linkToJournal($this->invoice, self::JOURNAL_ID));
	}

	public function testGeloeschteDateiWirdUebersprungen(): void {
		$this->documents->method('findByInvoiceAndKind')->willReturn($this->pdfDocument(self::FILE_ID));
		$this->home->method('getFirstNodeById')->willReturn(null);
		$this->attachments->expects($this->never())->method('insert');

		$this->assertNull($this->linker()->linkToJournal($this->invoice, self::JOURNAL_ID));
	}

	/** Ein Fehler beim Suchen der Datei darf die Zahlung nicht scheitern lassen. */
	public function testFehlerBeimSuchenWirdGeschluckt(): void {
		$this->documents->method('findByInvoiceAndKind')->willReturn($this->pdfDocument(self::FILE_ID));
		$this->home->method('getFirstNodeById')->willThrowException(new \RuntimeException('Speicher weg'));
		$this->attachments->expects($this->never())->method('insert');

		$this->assertNull($this->linker()->linkToJournal($this->invoice, self::JOURNAL_ID));
	}

	public function testHaengtDasPdfSchonAnDerBuchungKommtKeinZweitesDazu(): void {
		$this->documents->method('findByInvoiceAndKind')->willReturn($this->pdfDocument(self::FILE_ID));
		$this->home->method('getFirstNodeById')->willReturn($this->pdfFile());
		$existing = new Attachment();
		$existing->setFileId(self::FILE_ID);
		$this->attachments->method('findAllByJournal')->with(self::JOURNAL_ID)->willReturn([$existing]);
		$this->attachments->expects($this->never())->method('insert');

		$this->assertNull($this->linker()->linkToJournal($this->invoice, self::JOURNAL_ID));
	}
}
