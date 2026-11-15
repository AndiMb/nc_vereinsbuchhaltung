<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Backup;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AttachmentMapper;
use OCA\Vereinsbuchhaltung\Db\ReimbursementFileMapper;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceDocumentStorage;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * Wohin die Dateien einer Sicherung im Ziel kommen (Issue #111): in die
 * app-interne Ablage, je Art ein Ordner, die Datei unter der ID ihres
 * Datensatzes. Genau dort suchen die Dienste der App einen Beleg, dessen
 * Datensatz auf keine Nextcloud-Datei verweist – deshalb setzt der Import
 * diese Verweise zurück ({@see BackupSchema::tables()}).
 *
 * Die Ordnernamen sind die der lesenden Dienste (AttachmentStorageService,
 * InvoiceDocumentStorage, MandateDocumentService, BrandingService).
 */
class BackupFileSink {

	private const LOGO_FOLDER = 'branding';
	private const LOGO_FILE = 'logo';

	private IAppData $appData;

	public function __construct(
		IAppDataFactory $appDataFactory,
		private AttachmentMapper $attachments,
		private ReimbursementFileMapper $reimbursementFiles,
		private AttachmentStorageService $attachmentStorage,
		private InvoiceDocumentStorage $invoiceDocuments,
	) {
		$this->appData = $appDataFactory->get(Application::APP_ID);
	}

	/**
	 * @param string $kind eine der {@see BackupSchema::FILE_KINDS}
	 */
	public function store(string $kind, int $id, string $content): void {
		if (!in_array($kind, BackupSchema::FILE_KINDS, true)) {
			throw new \InvalidArgumentException('Unbekannte Dateiart.');
		}
		$this->write($kind, (string)$id, $content);
	}

	public function storeLogo(string $content): void {
		$this->write(self::LOGO_FOLDER, self::LOGO_FILE, $content);
	}

	/**
	 * Leert die app-interne Ablage. Vor dem Einspielen nötig: läge dort noch
	 * eine Datei aus einem früheren Bestand, erschiene sie als Beleg eines
	 * eingespielten Datensatzes mit derselben ID.
	 */
	public function clearAppData(): void {
		foreach (BackupSchema::FILE_KINDS as $kind) {
			try {
				$this->appData->getFolder($kind)->delete();
			} catch (\Throwable) {
				// Ordner gibt es nicht – nichts zu tun.
			}
		}
		try {
			$this->appData->getFolder(self::LOGO_FOLDER)->getFile(self::LOGO_FILE)->delete();
		} catch (\Throwable) {
			// kein Logo
		}
	}

	/**
	 * Für „Bestand vollständig leeren“: merkt sich, welche Dateien zum
	 * Bestand gehören – nach dem Leeren der Tabellen weiß das niemand mehr –
	 * und liefert die Aufgabe, die sie löscht. Sie läuft erst nach dem
	 * Commit: ein Rollback bringt die Datensätze zurück, gelöschte Dateien nicht.
	 *
	 * Gelöscht wird, was die App selbst abgelegt hat. Was im Wächter-Ordner
	 * liegt, die Mandatsnachweise im Nutzerordner und die SEPA-Dateien
	 * bleiben – das sind Dateien des Vereins, nicht der App.
	 */
	public function prepareWipe(): \Closure {
		$attachments = $this->attachments->findAllForUser(Application::BOOK);
		$reimbursementFiles = $this->reimbursementFiles->findAll();
		$invoiceDocuments = $this->invoiceDocuments->all();

		return function () use ($attachments, $reimbursementFiles, $invoiceDocuments): void {
			foreach ([
				fn () => $this->attachmentStorage->deleteAllFiles($attachments),
				fn () => $this->attachmentStorage->deleteAllReimbursementFiles($reimbursementFiles),
				fn () => $this->invoiceDocuments->deleteFiles($invoiceDocuments),
				fn () => $this->clearAppData(),
			] as $task) {
				try {
					$task();
				} catch (\Throwable) {
					// Eine verwaiste Datei ist ärgerlich, aber kein Grund, das Leeren als gescheitert zu melden.
				}
			}
		};
	}

	private function write(string $folderName, string $fileName, string $content): void {
		$folder = $this->folder($folderName);
		if ($folder->fileExists($fileName)) {
			$folder->getFile($fileName)->putContent($content);
		} else {
			$folder->newFile($fileName, $content);
		}
	}

	private function folder(string $name): ISimpleFolder {
		try {
			return $this->appData->getFolder($name);
		} catch (\Throwable) {
			return $this->appData->newFolder($name);
		}
	}
}
