<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use OCP\IL10N;

/**
 * Nachweis-Ablage eines Mandats: eine echte Nextcloud-Datei, referenziert per
 * File-ID (`Mandate::document_file_id`), kein DB-BLOB, kein AppData (Spec
 * §3.2 „Nachweis-Ablage").
 *
 * Anders als {@see AttachmentStorageService} (drei Ablagearten: AppData,
 * Nutzerordner, Wächter-Ordner) kennt diese Klasse nur eine: einen Ordner im
 * Home eines fest eingerichteten Nextcloud-Nutzers. Die Spec führt für
 * Mandats-Dokumente nur eine einzige neue Einstellung
 * (`mandate_document_folder`, §4) und keinen eigenen „welcher Nutzer"-Schalter
 * – deshalb wird hier bewusst derselbe Speicher-Nutzer wiederverwendet, den
 * die Belegablage bereits verwaltet ({@see AttachmentStorageService::storageUser()}).
 * Ist dort keiner eingerichtet (app-interne Ablage), ist die Nachweis-Ablage
 * schlicht noch nicht nutzbar – dafür sorgt `show_missing_document_warning`
 * für einen sichtbaren Dauer-Hinweis statt eines stillen Fehlers.
 */
class MandateDocumentService {

	public const SETTING_FOLDER = 'mandate_document_folder';
	public const DEFAULT_FOLDER = 'SEPA-Mandate';
	public const SETTING_SHOW_MISSING_WARNING = 'show_missing_document_warning';

	/**
	 * `document_file_id` eines Nachweises, der in der app-internen Ablage
	 * liegt (unter der Mandats-ID) statt im Nutzerordner. So kommen die
	 * Nachweise beim Einspielen einer Sicherung an (Issue #111): die
	 * Nextcloud-Datei der Quellinstanz gibt es im Ziel nicht.
	 */
	public const APPDATA_FILE_ID = 0;
	private const APPDATA_FOLDER = 'mandates';

	private IAppData $appData;

	public function __construct(
		private IRootFolder $rootFolder,
		private IAppConfig $config,
		private AttachmentStorageService $attachmentStorage,
		private IL10N $l10n,
		IAppDataFactory $appDataFactory,
	) {
		$this->appData = $appDataFactory->get(Application::APP_ID);
	}

	public function folderPath(): string {
		$path = trim($this->config->getValueString(Application::APP_ID, self::SETTING_FOLDER, self::DEFAULT_FOLDER));
		$path = trim(str_replace('\\', '/', $path), '/');
		return $path !== '' ? $path : self::DEFAULT_FOLDER;
	}

	/** Ob der abschaltbare Dauer-Hinweis „Mandat ohne Nachweis" grundsätzlich aktiv ist (Default an, Spec §3.2). */
	public function showMissingDocumentWarning(): bool {
		return $this->config->getValueString(Application::APP_ID, self::SETTING_SHOW_MISSING_WARNING, '1') === '1';
	}

	/** Ob überhaupt ein Nutzer-Home als Ablageort eingerichtet ist. */
	public function isConfigured(): bool {
		return $this->attachmentStorage->storageUser() !== '';
	}

	private function owner(): string {
		return $this->attachmentStorage->storageUser();
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

	/**
	 * Legt den Nachweis ab und liefert dessen Nextcloud-Datei-ID – die
	 * Aufruferin (MandateService) trägt sie in `document_file_id` ein.
	 *
	 * @throws \RuntimeException wenn kein Ablage-Nutzer eingerichtet ist
	 */
	public function store(Mandate $mandate, string $fileName, string $content): int {
		if (!$this->isConfigured()) {
			throw new UserFacingException($this->l10n->t('Für die Mandats-Nachweisablage ist noch kein Nextcloud-Nutzer eingerichtet (Einstellungen → Belegablage).'));
		}
		$folder = $this->ensureFolder($this->rootFolder->getUserFolder($this->owner()), $this->folderPath());
		$name = $folder->getNonExistingName($mandate->getMandateReference() . '_' . SafeFileName::of($fileName, 150));
		$file = $folder->newFile($name);
		$file->putContent($content);
		return $file->getId();
	}

	/**
	 * Die verwiesene Datei im Nutzerordner, oder null wenn sie fehlt oder
	 * keine hinterlegt ist. Ein app-intern abgelegter Nachweis ist keine Datei
	 * im Dateibaum – dafür {@see documentOrNull()}.
	 */
	public function nodeOrNull(Mandate $mandate): ?File {
		$fileId = $mandate->getDocumentFileId();
		if ($fileId === null || $fileId === self::APPDATA_FILE_ID || !$this->isConfigured()) {
			return null;
		}
		try {
			$node = $this->rootFolder->getUserFolder($this->owner())->getFirstNodeById($fileId);
		} catch (\Throwable) {
			return null;
		}
		return $node instanceof File ? $node : null;
	}

	public function hasDocument(Mandate $mandate): bool {
		if ($mandate->getDocumentFileId() === self::APPDATA_FILE_ID) {
			return $this->appDataFolder(false)?->fileExists((string)$mandate->getId()) ?? false;
		}
		return $mandate->getDocumentFileId() !== null && $this->nodeOrNull($mandate) !== null;
	}

	/**
	 * Der Nachweis zum Ausliefern, wo immer er liegt.
	 *
	 * @return array{content: string, name: string, mimeType: string}|null null, wenn keiner hinterlegt ist oder die Datei fehlt
	 */
	public function documentOrNull(Mandate $mandate): ?array {
		if ($mandate->getDocumentFileId() === self::APPDATA_FILE_ID) {
			try {
				$content = $this->appDataFolder(false)?->getFile((string)$mandate->getId())->getContent();
			} catch (\Throwable) {
				return null;
			}
			if (!is_string($content)) {
				return null;
			}
			// Die app-interne Ablage kennt weder Namen noch Typ; beides ergibt sich aus dem Inhalt.
			[$extension, $mimeType] = match (true) {
				str_starts_with($content, '%PDF') => ['.pdf', 'application/pdf'],
				str_starts_with($content, "\x89PNG") => ['.png', 'image/png'],
				str_starts_with($content, "\xFF\xD8\xFF") => ['.jpg', 'image/jpeg'],
				default => ['', 'application/octet-stream'],
			};
			return ['content' => $content, 'name' => SafeFileName::of((string)$mandate->getMandateReference(), 150) . $extension, 'mimeType' => $mimeType];
		}
		$node = $this->nodeOrNull($mandate);
		if ($node === null) {
			return null;
		}
		try {
			return ['content' => $node->getContent(), 'name' => $node->getName(), 'mimeType' => $node->getMimeType()];
		} catch (\Throwable) {
			return null;
		}
	}

	public function contentOrNull(Mandate $mandate): ?string {
		return $this->documentOrNull($mandate)['content'] ?? null;
	}

	/** Legt einen Nachweis app-intern unter der Mandats-ID ab; das Mandat verweist darauf mit {@see APPDATA_FILE_ID}. */
	public function storeInAppData(int $mandateId, string $content): void {
		$folder = $this->appDataFolder(true);
		$name = (string)$mandateId;
		if ($folder->fileExists($name)) {
			$folder->getFile($name)->putContent($content);
		} else {
			$folder->newFile($name, $content);
		}
	}

	public function deleteFromAppData(int $mandateId): void {
		try {
			$this->appDataFolder(false)?->getFile((string)$mandateId)->delete();
		} catch (\Throwable) {
			// schon weg
		}
	}

	/** Entfernt alle app-intern abgelegten Nachweise („Bestand vollständig leeren“). */
	public function deleteAppDataFiles(): void {
		try {
			$this->appDataFolder(false)?->delete();
		} catch (\Throwable) {
			// schon weg
		}
	}

	/**
	 * @return ($create is true ? ISimpleFolder : ISimpleFolder|null)
	 */
	private function appDataFolder(bool $create): ?ISimpleFolder {
		try {
			return $this->appData->getFolder(self::APPDATA_FOLDER);
		} catch (\Throwable) {
			return $create ? $this->appData->newFolder(self::APPDATA_FOLDER) : null;
		}
	}
}
