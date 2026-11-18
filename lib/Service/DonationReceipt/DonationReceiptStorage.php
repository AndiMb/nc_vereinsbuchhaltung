<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\DonationReceipt;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\DonationReceipt;
use OCA\Vereinsbuchhaltung\Db\DonationReceiptMapper;
use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IL10N;

/**
 * Legt das PDF einer Zuwendungsbestätigung ab und liest es wieder (Issue #10) –
 * nach demselben Muster wie die Rechnungsdokumente
 * ({@see \OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceDocumentStorage}):
 * mit Belegablage-Nutzer als gewöhnliche Datei unter `<Ordner>/<Jahr>/`,
 * sonst app-intern unter der ID des Datensatzes (`file_id` = 0).
 */
class DonationReceiptStorage {

	/** Ordner der app-internen Ablage = Dateiart der Sicherung. */
	public const APPDATA_FOLDER = 'donation_receipts';

	private IAppData $appData;

	public function __construct(
		IAppDataFactory $appDataFactory,
		private IRootFolder $rootFolder,
		private AttachmentStorageService $attachmentStorage,
		private DonationReceiptSettings $settings,
		private DonationReceiptMapper $receipts,
		private IL10N $l10n,
	) {
		$this->appData = $appDataFactory->get(Application::APP_ID);
	}

	/** @return list<DonationReceipt> */
	public function all(): array {
		return $this->receipts->findAll();
	}

	/**
	 * Speichert den Datensatz samt PDF. Läuft in der Transaktion des Aufrufers:
	 * scheitert das Schreiben, wird eine schon angelegte Datei wieder entfernt.
	 */
	public function store(DonationReceipt $receipt, string $content): DonationReceipt {
		$receipt->setSha256(hash('sha256', $content));
		$receipt->setFileId(0);

		$user = $this->attachmentStorage->storageUser();
		if ($user !== '') {
			$folder = $this->ensureFolder($this->rootFolder->getUserFolder($user), $this->settings->folder() . '/' . $receipt->getReceiptYear());
			$file = $folder->newFile($folder->getNonExistingName($receipt->getFileName()));
			$file->putContent($content);
			$receipt->setFileId((int)$file->getId());
			$receipt->setFileName($file->getName());
			try {
				return $this->receipts->insert($receipt);
			} catch (\Throwable $e) {
				$this->deleteQuietly($file);
				throw $e;
			}
		}

		$receipt = $this->receipts->insert($receipt);
		try {
			$this->appDataFolder(true)?->newFile((string)$receipt->getId())->putContent($content);
		} catch (\Throwable $e) {
			throw new UserFacingException($this->l10n->t('Das Dokument konnte nicht gespeichert werden.'), 0, $e);
		}
		return $receipt;
	}

	public function read(DonationReceipt $receipt): string {
		$content = $this->readOrNull($receipt);
		if ($content === null) {
			throw new UserFacingException($this->l10n->t('Die Datei zum Dokument wurde nicht gefunden.'));
		}
		return $content;
	}

	public function readOrNull(DonationReceipt $receipt): ?string {
		try {
			if ($receipt->getFileId() > 0) {
				$user = $this->attachmentStorage->storageUser();
				if ($user === '') {
					return null;
				}
				$node = $this->rootFolder->getUserFolder($user)->getFirstNodeById($receipt->getFileId());
				return $node instanceof File ? $node->getContent() : null;
			}
			return $this->appDataFolder(false)?->getFile((string)$receipt->getId())->getContent();
		} catch (\Throwable) {
			return null;
		}
	}

	/** @return 'ok'|'changed'|'missing' */
	public function verify(DonationReceipt $receipt): string {
		$content = $this->readOrNull($receipt);
		if ($content === null) {
			return 'missing';
		}
		return hash_equals($receipt->getSha256(), hash('sha256', $content)) ? 'ok' : 'changed';
	}

	/**
	 * Entfernt die Dateien (Anonymisierung, Sicherung einspielen). Fehlende Dateien sind kein Fehler.
	 *
	 * @param list<DonationReceipt> $receipts
	 */
	public function deleteFiles(array $receipts): void {
		foreach ($receipts as $receipt) {
			try {
				if ($receipt->getFileId() > 0) {
					$user = $this->attachmentStorage->storageUser();
					if ($user !== '') {
						$this->rootFolder->getUserFolder($user)->getFirstNodeById($receipt->getFileId())?->delete();
					}
				} else {
					$this->appDataFolder(false)?->getFile((string)$receipt->getId())->delete();
				}
			} catch (\Throwable) {
			}
		}
	}

	private function deleteQuietly(File $file): void {
		try {
			$file->delete();
		} catch (\Throwable) {
		}
	}

	private function appDataFolder(bool $create): ?ISimpleFolder {
		try {
			return $this->appData->getFolder(self::APPDATA_FOLDER);
		} catch (NotFoundException) {
			return $create ? $this->appData->newFolder(self::APPDATA_FOLDER) : null;
		}
	}

	private function ensureFolder(Folder $root, string $path): Folder {
		$current = $root;
		foreach (array_values(array_filter(explode('/', $path))) as $part) {
			if ($current->nodeExists($part)) {
				$node = $current->get($part);
				if (!($node instanceof Folder)) {
					throw new UserFacingException($this->l10n->t("Pfadkomponente '%s' ist kein Ordner", [$part]));
				}
				$current = $node;
			} else {
				$current = $current->newFolder($part);
			}
		}
		return $current;
	}
}
