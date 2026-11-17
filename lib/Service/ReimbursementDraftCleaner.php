<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\ReimbursementDraft;
use OCA\Vereinsbuchhaltung\Db\ReimbursementDraftFile;
use OCA\Vereinsbuchhaltung\Db\ReimbursementDraftFileMapper;
use OCA\Vereinsbuchhaltung\Db\ReimbursementDraftMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * Ablage und Löschen von Entwürfen (Issue #356), ohne Abhängigkeit zum
 * Antragsdienst – so können Mitgliederverwaltung und Anonymisierung Entwürfe
 * mit abräumen, ohne den ganzen Auslagen-Dienstbaum zu laden.
 *
 * Die Dateien liegen in der app-internen Ablage unter
 * `reimbursement-drafts/<Datei-ID>`.
 */
class ReimbursementDraftCleaner {

	public const FOLDER = 'reimbursement-drafts';

	private IAppData $appData;

	public function __construct(
		private ReimbursementDraftMapper $drafts,
		private ReimbursementDraftFileMapper $files,
		private TransactionRunner $transaction,
		IAppDataFactory $appDataFactory,
	) {
		$this->appData = $appDataFactory->get(Application::APP_ID);
	}

	/** Alle Entwürfe des Mitglieds – beim Löschen und Anonymisieren des Mitglieds. */
	public function deleteAllForMember(int $memberId): void {
		$this->deleteDrafts($this->drafts->findByMember($memberId));
	}

	/** @param ReimbursementDraft[] $drafts */
	public function deleteDrafts(array $drafts): void {
		if ($drafts === []) {
			return;
		}
		$files = $this->files->findByDrafts(array_map(static fn (ReimbursementDraft $d): int => $d->getId(), $drafts));
		$this->transaction->run(function () use ($drafts, $files): void {
			foreach ($files as $file) {
				$this->files->delete($file);
			}
			foreach ($drafts as $draft) {
				$this->drafts->delete($draft);
			}
		});
		$this->purge(array_map(static fn (ReimbursementDraftFile $f): int => $f->getId(), $files));
	}

	/**
	 * Löscht Dateien aus der Ablage. Läuft gerade eine Transaktion, erst nach
	 * dem Commit – ein Rollback brächte die Zeilen zurück, die Dateien nicht.
	 *
	 * @param list<int> $fileIds
	 */
	public function purge(array $fileIds): void {
		if ($fileIds !== []) {
			$this->transaction->afterCommit(fn () => $this->purgeNow($fileIds));
		}
	}

	public function store(int $fileId, string $content): void {
		$this->folder()->newFile((string)$fileId)->putContent($content);
	}

	/** @throws NotFoundException wenn die Datei fehlt */
	public function content(int $fileId): string {
		return $this->folder()->getFile((string)$fileId)->getContent();
	}

	/** @param list<int> $fileIds */
	public function purgeNow(array $fileIds): void {
		try {
			$folder = $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return;
		}
		foreach ($fileIds as $fileId) {
			try {
				$folder->getFile((string)$fileId)->delete();
			} catch (\Throwable) {
				// Schon weg – nichts zu tun.
			}
		}
	}

	private function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}
}
