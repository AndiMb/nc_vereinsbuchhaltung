<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Attachment;
use OCA\Vereinsbuchhaltung\Db\AttachmentMapper;
use OCA\Vereinsbuchhaltung\Db\Reimbursement;
use OCA\Vereinsbuchhaltung\Db\ReimbursementFile;
use OCA\Vereinsbuchhaltung\Db\ReimbursementFileMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Die Belegdateien eines Auslagenantrags: prüfen, ablegen, entfernen und bei
 * der Auszahlung an die Buchung hängen. Wohin die Datei physisch kommt, regelt
 * die {@see AttachmentStorageService} – Mitglieder laden nur hoch, sie sehen
 * den Wächter-Ordner nie.
 */
class ReimbursementFileService {

	/** Wie bei den Buchungsbelegen, siehe AttachmentController. */
	public const MAX_SIZE = 20 * 1024 * 1024;

	public function __construct(
		private AttachmentStorageService $storage,
		private ReimbursementFileMapper $mapper,
		private AttachmentMapper $attachmentMapper,
		private TransactionRunner $transaction,
		private ITimeFactory $time,
		private IL10N $l10n,
	) {
	}

	/**
	 * @throws \InvalidArgumentException bei zu großer Datei oder falschem Dateityp
	 * @throws \RuntimeException wenn die Datei nicht abgelegt werden konnte
	 */
	public function add(Reimbursement $reimbursement, string $name, string $content): ReimbursementFile {
		$size = strlen($content);
		if ($size === 0) {
			throw new \InvalidArgumentException($this->l10n->t('Die Datei ist leer.'));
		}
		if ($size > self::MAX_SIZE) {
			throw new \InvalidArgumentException($this->l10n->t('Datei zu groß (max. 20 MB)'));
		}
		$mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
		if ($mime === false || !AttachmentWatchFolderService::isReceipt($mime)) {
			throw new \InvalidArgumentException($this->l10n->t('Nur Bilder (JPG/PNG/GIF/WebP) und PDFs erlaubt'));
		}

		$file = new ReimbursementFile();
		$file->setReimbursementId($reimbursement->getId());
		$file->setFileName(basename($name) !== '' ? basename($name) : 'beleg');
		$file->setMimeType($mime);
		$file->setFileSize($size);
		$file->setContentHash(hash('sha256', $content));
		$file->setUploadedAt($this->time->getDateTime()->format('Y-m-d H:i:s'));
		$file = $this->mapper->insert($file);

		try {
			$this->storage->storeReimbursementFile($file, (int)substr($reimbursement->getExpenseDate(), 0, 4), $content);
			$file = $this->mapper->update($file);
		} catch (\Throwable $e) {
			$this->mapper->delete($file);
			throw new \RuntimeException($this->l10n->t('Datei konnte nicht gespeichert werden: %s', [$e->getMessage()]), 0, $e);
		}

		// Wird der umgebende Vorgang zurückgerollt, ist die Zeile weg, die Datei aber nicht.
		$stored = clone $file;
		$this->transaction->afterRollback(fn () => $this->storage->purgeReimbursementFile($stored));
		return $file;
	}

	/**
	 * Eine nicht entfernte Datei dieses Antrags.
	 *
	 * @throws DoesNotExistException wenn es sie nicht gibt oder sie zu einem anderen Antrag gehört
	 */
	public function findActiveFor(int $reimbursementId, int $fileId): ReimbursementFile {
		$file = $this->mapper->find($fileId);
		if ($file->getReimbursementId() !== $reimbursementId || $file->isRemoved()) {
			throw new DoesNotExistException('Receipt not found');
		}
		return $file;
	}

	/** Markiert die Datei als entfernt und löscht sie nach dem Commit. */
	public function remove(ReimbursementFile $file): void {
		$file->setRemovedAt($this->time->getDateTime()->format('Y-m-d H:i:s'));
		$this->mapper->update($file);
		$this->storage->deleteReimbursementFile($file);
	}

	/**
	 * Dateien anderer Anträge mit demselben Inhalt: ein Hinweis an die
	 * Verwalter, kein Hindernis.
	 *
	 * @return ReimbursementFile[]
	 */
	public function duplicatesOf(ReimbursementFile $file): array {
		$hash = $file->getContentHash();
		if ($hash === null) {
			return [];
		}
		return $this->mapper->findOtherByContentHash($hash, $file->getReimbursementId());
	}

	/** @throws \RuntimeException wenn die Datei nicht (mehr) auffindbar ist */
	public function content(ReimbursementFile $file): string {
		return $this->storage->reimbursementContent($file);
	}

	/**
	 * Legt für jede Datei des Antrags den normalen Beleg-Eintrag an der Buchung
	 * an (siehe {@see AttachmentStorageService::moveReimbursementFileToJournal()}).
	 */
	public function linkToJournal(Reimbursement $reimbursement, int $journalId): void {
		foreach ($this->mapper->findActive($reimbursement->getId()) as $file) {
			$attachment = new Attachment();
			$attachment->setJournalId($journalId);
			$attachment->setUserId(Application::BOOK);
			$attachment->setFileName($file->getFileName());
			$attachment->setMimeType($file->getMimeType());
			$attachment->setFileSize($file->getFileSize());
			$attachment->setUploadedAt(new \DateTime());
			$attachment = $this->attachmentMapper->insert($attachment);
			$this->storage->moveReimbursementFileToJournal($file, $attachment);
			$this->attachmentMapper->update($attachment);
		}
	}
}
