<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AttachmentMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;

class AttachmentStorageService {

	private $appData;

	public function __construct(
		IAppDataFactory $appDataFactory,
		private IRootFolder $rootFolder,
		private IConfig $config,
		private AttachmentMapper $attachmentMapper,
		private TransactionRunner $transaction,
	) {
		$this->appData = $appDataFactory->get(Application::APP_ID);
	}

	/**
	 * Löscht alle Anhänge (DB-Zeilen und Dateien) eines Buchungssatzes.
	 * Muss von jedem Pfad aufgerufen werden, der Buchungssätze löscht,
	 * damit keine verwaisten Belege zurückbleiben.
	 *
	 * Die Datei selbst wird erst nach dem Commit entfernt: Wird die umgebende
	 * Transaktion zurückgerollt, ist der Buchungssatz samt Beleg-Datensatz
	 * wieder da – die Datei wäre andernfalls schon weg und nicht
	 * wiederherstellbar (siehe TransactionRunner::afterCommit()).
	 */
	public function deleteForJournal(int $journalId): void {
		foreach ($this->attachmentMapper->findAllByJournal($journalId) as $attachment) {
			$id = $attachment->getId();
			$attachmentJournalId = $attachment->getJournalId();
			$fileName = $attachment->getFileName();
			$this->attachmentMapper->delete($attachment);
			$this->transaction->afterCommit(function () use ($id, $attachmentJournalId, $fileName): void {
				$this->deleteFile($id, $attachmentJournalId, $fileName);
			});
		}
	}

	private function storageUser(): string {
		return $this->config->getAppValue(Application::APP_ID, 'storage_user', '');
	}

	private function storagePath(): string {
		return trim($this->config->getAppValue(Application::APP_ID, 'storage_path', 'Vereinsbuchhaltung/Belege'), '/');
	}

	public function isNcMode(): bool {
		return $this->storageUser() !== '';
	}

	/** Pfad der Datei relativ zum Nutzer-Home (ohne führenden Slash). Nur im NC-Modus sinnvoll. */
	public function getNcFilePath(int $id, int $journalId, string $fileName): string {
		return $this->storagePath() . '/' . $journalId . '/' . $this->ncFileName($id, $fileName);
	}

	private function sanitizeName(string $name): string {
		return preg_replace('/[^\w.\-]/', '_', $name);
	}

	private function ncFileName(int $id, string $fileName): string {
		return $id . '_' . $this->sanitizeName($fileName);
	}

	private function ensureFolder(Folder $root, string $path): Folder {
		$parts = array_values(array_filter(explode('/', $path)));
		$current = $root;
		foreach ($parts as $part) {
			if ($current->nodeExists($part)) {
				$node = $current->get($part);
				if (!($node instanceof Folder)) {
					throw new \RuntimeException("Pfadkomponente '$part' ist kein Ordner");
				}
				$current = $node;
			} else {
				$current = $current->newFolder($part);
			}
		}
		return $current;
	}

	private function getNcFolder(int $journalId): Folder {
		$userFolder = $this->rootFolder->getUserFolder($this->storageUser());
		return $this->ensureFolder($userFolder, $this->storagePath() . '/' . $journalId);
	}

	private function appDataFolder() {
		try {
			return $this->appData->getFolder('attachments');
		} catch (\OCP\Files\NotFoundException) {
			return $this->appData->newFolder('attachments');
		}
	}

	public function putFile(int $id, int $journalId, string $fileName, string $content): void {
		if ($this->isNcMode()) {
			$folder = $this->getNcFolder($journalId);
			$name = $this->ncFileName($id, $fileName);
			if ($folder->nodeExists($name)) {
				$folder->get($name)->putContent($content);
			} else {
				$file = $folder->newFile($name);
				$file->putContent($content);
			}
		} else {
			$folder = $this->appDataFolder();
			$file = $folder->newFile((string)$id);
			$file->putContent($content);
		}
	}

	public function getFileContent(int $id, int $journalId, string $fileName): string {
		if ($this->isNcMode()) {
			$folder = $this->getNcFolder($journalId);
			$name = $this->ncFileName($id, $fileName);
			return $folder->get($name)->getContent();
		} else {
			$folder = $this->appDataFolder();
			return $folder->getFile((string)$id)->getContent();
		}
	}

	public function deleteFile(int $id, int $journalId, string $fileName): void {
		try {
			if ($this->isNcMode()) {
				$userFolder = $this->rootFolder->getUserFolder($this->storageUser());
				$path = $this->storagePath() . '/' . $journalId . '/' . $this->ncFileName($id, $fileName);
				if ($userFolder->nodeExists($path)) {
					$userFolder->get($path)->delete();
				}
			} else {
				$folder = $this->appData->getFolder('attachments');
				$folder->getFile((string)$id)->delete();
			}
		} catch (\Throwable) {
			// Datei schon weg – ignorieren.
		}
	}

	/**
	 * Entfernt beim Zurücksetzen die Dateien der übergebenen Anhänge.
	 *
	 * Bewusst dateiweise statt den Ablageordner rekursiv zu löschen: der Ordner
	 * liegt im Home eines echten Nextcloud-Nutzers und kann – gerade wenn der
	 * Pfad einmal falsch konfiguriert war – auch fremde Dateien enthalten, die
	 * ein Reset der Buchhaltung nicht mitnehmen darf. Im appdata-Modus gehört
	 * der Ordner ausschließlich dieser App, dort wird er weiterhin als Ganzes
	 * entfernt.
	 *
	 * @param array<int, \OCA\Vereinsbuchhaltung\Db\Attachment> $attachments
	 */
	public function deleteAllFiles(array $attachments): void {
		if (!$this->isNcMode()) {
			try {
				$this->appData->getFolder('attachments')->delete();
			} catch (\Throwable) {
				// Ordner existiert nicht – kein Fehler.
			}
			return;
		}

		foreach ($attachments as $attachment) {
			$this->deleteFile($attachment->getId(), $attachment->getJournalId(), $attachment->getFileName());
		}
		$this->removeEmptyJournalFolders($attachments);
	}

	/**
	 * Räumt die je Buchung angelegten Unterordner ab, sofern sie nach dem
	 * Löschen der Belege leer sind. Der Ablage-Wurzelordner selbst bleibt
	 * stehen – ihn hat die Nutzerin bewusst angelegt.
	 *
	 * @param array<int, \OCA\Vereinsbuchhaltung\Db\Attachment> $attachments
	 */
	private function removeEmptyJournalFolders(array $attachments): void {
		$journalIds = [];
		foreach ($attachments as $attachment) {
			$journalIds[$attachment->getJournalId()] = true;
		}
		try {
			$userFolder = $this->rootFolder->getUserFolder($this->storageUser());
		} catch (\Throwable) {
			return;
		}
		foreach (array_keys($journalIds) as $journalId) {
			try {
				$path = $this->storagePath() . '/' . $journalId;
				if (!$userFolder->nodeExists($path)) {
					continue;
				}
				$node = $userFolder->get($path);
				if ($node instanceof Folder && $node->getDirectoryListing() === []) {
					$node->delete();
				}
			} catch (\Throwable) {
				// Ordner schon weg oder nicht löschbar – kein Grund abzubrechen.
			}
		}
	}
}
