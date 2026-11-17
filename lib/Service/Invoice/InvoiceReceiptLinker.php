<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Invoice;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Attachment;
use OCA\Vereinsbuchhaltung\Db\AttachmentMapper;
use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceDocument;
use OCA\Vereinsbuchhaltung\Db\InvoiceDocumentMapper;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Hängt das PDF einer Rechnung als Beleg an die Buchung ihrer Zahlung (Issue #366).
 *
 * Die App hat das PDF selbst erzeugt und weiß, zu welcher Rechnung eine Zahlung
 * gehört; der Kassenwart muss den Beleg deshalb nicht von Hand suchen. Verknüpft
 * wird nur, nicht kopiert: Die Datei bleibt im Rechnungsordner, ihr SHA-256
 * stimmt weiter, und „Beleg löschen“ oder das Zurücknehmen der Zahlung entfernt
 * nur die Verknüpfung ({@see AttachmentStorageService::keepsFile()}).
 *
 * Ohne eingerichteten Ablage-Nutzer liegt das PDF im app-internen Speicher
 * (`file_id` = 0). Dort gibt es keine Datei zum Verknüpfen, und eine Kopie
 * würde das Original verdoppeln – die Buchung bleibt dann ohne Beleg wie bisher.
 * Fehlt das PDF (noch), gilt dasselbe.
 */
class InvoiceReceiptLinker {

	public function __construct(
		private InvoiceDocumentMapper $documents,
		private AttachmentMapper $attachments,
		private AttachmentStorageService $storage,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return Attachment|null der neue Beleg, null wenn es nichts zu verknüpfen gab
	 */
	public function linkToJournal(Invoice $invoice, int $journalId): ?Attachment {
		$file = $this->pdfOf($invoice);
		if ($file === null) {
			return null;
		}
		foreach ($this->attachments->findAllByJournal($journalId) as $existing) {
			if ((int)$existing->getFileId() === (int)$file->getId()) {
				return null;
			}
		}
		$attachment = new Attachment();
		$attachment->setJournalId($journalId);
		$attachment->setUserId(Application::BOOK);
		$attachment->setFileName($file->getName());
		$attachment->setMimeType($file->getMimetype());
		$attachment->setFileSize((int)$file->getSize());
		$attachment->setUploadedAt(new \DateTime()); // UTC-Zeitstempel (#263)
		$this->storage->attach($attachment, $file);
		return $this->attachments->insert($attachment);
	}

	/** Die Datei des Rechnungs-PDFs im Home des Ablage-Nutzers – oder null. */
	private function pdfOf(Invoice $invoice): ?File {
		$doc = $this->documents->findByInvoiceAndKind((int)$invoice->getId(), InvoiceDocument::KIND_PDF);
		if ($doc === null || $doc->getFileId() <= 0) {
			return null;
		}
		$user = $this->storage->storageUser();
		if ($user === '') {
			return null;
		}
		try {
			$node = $this->rootFolder->getUserFolder($user)->getFirstNodeById($doc->getFileId());
		} catch (\Throwable $e) {
			// Der Beleg ist eine Bequemlichkeit; die Zahlung selbst darf daran nicht scheitern.
			$this->logger->warning('Rechnungs-PDF für die Zahlungsbuchung nicht auffindbar', ['exception' => $e, 'invoiceId' => $invoice->getId()]);
			return null;
		}
		return $node instanceof File ? $node : null;
	}
}
