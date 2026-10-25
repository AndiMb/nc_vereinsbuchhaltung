<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Invoice;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceDocument;
use OCA\Vereinsbuchhaltung\Db\InvoiceDocumentMapper;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\InvoiceSettings;
use OCA\Vereinsbuchhaltung\Service\SafeFileName;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IL10N;

/**
 * Legt erzeugte Rechnungsdokumente ab und liest sie wieder.
 *
 * Ist für die Belegablage ein Nextcloud-Nutzer eingerichtet, liegen die Dateien
 * als gewöhnliche Dateien in dessen Ordner unter `<Rechnungsordner>/<Jahr>/`
 * und sind für Vereinsmitglieder mit Zugriff auch in der Dateien-App
 * auffindbar; die Datei-ID steht in `vbh_invoice_documents.file_id`. Ohne
 * eingerichteten Nutzer landen sie im app-internen Speicher (`file_id` = 0,
 * Dateiname = ID des Dokumentsatzes). Beide Wege sind für die Aufrufer gleich.
 *
 * Jedes Dokument trägt seinen SHA-256 als Nachweis (GoBD): {@see verify()}
 * zeigt, ob die Datei noch dem erzeugten Stand entspricht.
 */
class InvoiceDocumentStorage {

	private IAppData $appData;

	public function __construct(
		IAppDataFactory $appDataFactory,
		private IRootFolder $rootFolder,
		private AttachmentStorageService $attachmentStorage,
		private InvoiceSettings $settings,
		private InvoiceDocumentMapper $documents,
		private IL10N $l10n,
	) {
		$this->appData = $appDataFactory->get(Application::APP_ID);
	}

	/** @return list<InvoiceDocument> alle Dokumente, zum Aufräumen nach einem Reset */
	public function all(): array {
		return $this->documents->findAll();
	}

	/**
	 * Speichert den Inhalt als neues Dokument der Rechnung.
	 *
	 * @throws \RuntimeException wenn die Ablage nicht beschreibbar ist
	 */
	public function store(Invoice $invoice, string $kind, string $content, string $extension, ?string $profile = null): InvoiceDocument {
		$baseName = SafeFileName::of((string)($invoice->getNumber() ?? ('Rechnung-' . $invoice->getId())), 100);
		$fileName = $baseName . '.' . $extension;

		$doc = new InvoiceDocument();
		$doc->setInvoiceId((int)$invoice->getId());
		$doc->setKind($kind);
		$doc->setFileName($fileName);
		$doc->setSha256(hash('sha256', $content));
		$doc->setProfile($profile);
		$doc->setGeneratedAt((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));
		$doc->setFileId(0);

		$user = $this->attachmentStorage->storageUser();
		if ($user !== '') {
			$year = substr((string)($invoice->getIssueDate() ?? date('Y-m-d')), 0, 4);
			$folder = $this->ensureFolder($this->rootFolder->getUserFolder($user), $this->settings->folder() . '/' . $year);
			$file = $folder->newFile($folder->getNonExistingName($fileName));
			$file->putContent($content);
			$doc->setFileId((int)$file->getId());
			$doc->setFileName($file->getName());
			return $this->documents->insert($doc);
		}

		$doc = $this->documents->insert($doc);
		try {
			$this->appDataFolder(true)->newFile((string)$doc->getId())->putContent($content);
		} catch (\Throwable $e) {
			$this->documents->delete($doc);
			throw new \RuntimeException($this->l10n->t('Das Dokument konnte nicht gespeichert werden.'), 0, $e);
		}
		return $doc;
	}

	/**
	 * @throws \RuntimeException wenn die Datei fehlt
	 */
	public function read(InvoiceDocument $doc): string {
		$content = $this->readOrNull($doc);
		if ($content === null) {
			throw new \RuntimeException($this->l10n->t('Die Datei zum Dokument wurde nicht gefunden.'));
		}
		return $content;
	}

	public function readOrNull(InvoiceDocument $doc): ?string {
		try {
			if ($doc->getFileId() > 0) {
				$user = $this->attachmentStorage->storageUser();
				if ($user === '') {
					return null;
				}
				$node = $this->rootFolder->getUserFolder($user)->getFirstNodeById($doc->getFileId());
				return $node instanceof File ? $node->getContent() : null;
			}
			return $this->appDataFolder(false)?->getFile((string)$doc->getId())->getContent();
		} catch (NotFoundException) {
			return null;
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Entspricht die abgelegte Datei noch dem erzeugten Stand?
	 *
	 * @return 'ok'|'changed'|'missing'
	 */
	public function verify(InvoiceDocument $doc): string {
		$content = $this->readOrNull($doc);
		if ($content === null) {
			return 'missing';
		}
		return hash_equals($doc->getSha256(), hash('sha256', $content)) ? 'ok' : 'changed';
	}

	/**
	 * Entfernt die Dateien der Dokumente (nach einem Reset). Fehlende Dateien
	 * sind kein Fehler.
	 *
	 * @param list<InvoiceDocument> $documents
	 */
	public function deleteFiles(array $documents): void {
		foreach ($documents as $doc) {
			try {
				if ($doc->getFileId() > 0) {
					$user = $this->attachmentStorage->storageUser();
					if ($user !== '') {
						$this->rootFolder->getUserFolder($user)->getFirstNodeById($doc->getFileId())?->delete();
					}
				} else {
					$this->appDataFolder(false)?->getFile((string)$doc->getId())->delete();
				}
			} catch (\Throwable) {
			}
		}
	}

	private function appDataFolder(bool $create): ?\OCP\Files\SimpleFS\ISimpleFolder {
		try {
			return $this->appData->getFolder('invoices');
		} catch (NotFoundException) {
			return $create ? $this->appData->newFolder('invoices') : null;
		}
	}

	private function ensureFolder(Folder $root, string $path): Folder {
		$current = $root;
		foreach (array_values(array_filter(explode('/', $path))) as $part) {
			if ($current->nodeExists($part)) {
				$node = $current->get($part);
				if (!($node instanceof Folder)) {
					throw new \RuntimeException($this->l10n->t("Pfadkomponente '%s' ist kein Ordner", [$part]));
				}
				$current = $node;
			} else {
				$current = $current->newFolder($part);
			}
		}
		return $current;
	}
}
