<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Backup;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AttachmentMapper;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\ReimbursementFileMapper;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\BrandingService;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceDocumentStorage;
use OCA\Vereinsbuchhaltung\Service\MandateDocumentService;

/**
 * Die Dateien, die zu einem Bestand gehören (Issue #111): Buchungsbelege,
 * Belege der Auslagenanträge, Rechnungsdokumente, Mandatsnachweise und das
 * Logo. Gelesen wird über die Dienste, die die Dateien auch sonst ausliefern
 * – wo immer sie liegen (app-intern, Nutzerordner, Wächter-Ordner).
 *
 * Jede Datei wird erst beim Aufruf von `read` geladen, damit nie mehr als
 * eine im Speicher liegt.
 */
class BackupFileSource {

	public function __construct(
		private AttachmentMapper $attachments,
		private ReimbursementFileMapper $reimbursementFiles,
		private MandateMapper $mandates,
		private AttachmentStorageService $attachmentStorage,
		private InvoiceDocumentStorage $invoiceDocuments,
		private MandateDocumentService $mandateDocuments,
		private BrandingService $branding,
	) {
	}

	/**
	 * @return \Generator<int, array{kind: string, id: int, read: callable(): ?string}>
	 *                                                                                  `read` liefert null, wenn die Datei fehlt oder nicht lesbar ist
	 */
	public function files(): \Generator {
		foreach ($this->attachments->findAllForUser(Application::BOOK) as $attachment) {
			yield ['kind' => 'attachments', 'id' => $attachment->getId(), 'read' => fn (): ?string => $this->quiet(fn (): string => $this->attachmentStorage->contentOf($attachment))];
		}
		foreach ($this->reimbursementFiles->findAll() as $file) {
			$content = fn (): ?string => $this->quiet(fn (): string => $this->attachmentStorage->reimbursementContent($file));
			if ($file->isRemoved()) {
				// Ein entfernter Beleg darf fehlen – seine Datei ist meist schon gelöscht.
				$probe = $content();
				if ($probe === null) {
					continue;
				}
				$content = static fn (): string => $probe;
			}
			yield ['kind' => 'reimbursements', 'id' => $file->getId(), 'read' => $content];
		}
		foreach ($this->invoiceDocuments->all() as $document) {
			yield ['kind' => 'invoices', 'id' => $document->getId(), 'read' => fn (): ?string => $this->invoiceDocuments->readOrNull($document)];
		}
		foreach ($this->mandates->findAll() as $mandate) {
			if ($mandate->getDocumentFileId() === null) {
				continue;
			}
			yield ['kind' => 'mandates', 'id' => $mandate->getId(), 'read' => fn (): ?string => $this->quiet(fn (): ?string => $this->mandateDocuments->nodeOrNull($mandate)?->getContent())];
		}
	}

	public function logo(): ?string {
		return $this->branding->getLogo()['content'] ?? null;
	}

	/**
	 * @param callable(): ?string $read
	 */
	private function quiet(callable $read): ?string {
		try {
			return $read();
		} catch (\Throwable) {
			return null;
		}
	}
}
