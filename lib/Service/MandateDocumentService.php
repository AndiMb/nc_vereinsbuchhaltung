<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
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

	public function __construct(
		private IRootFolder $rootFolder,
		private IConfig $config,
		private AttachmentStorageService $attachmentStorage,
		private IL10N $l10n,
	) {
	}

	public function folderPath(): string {
		$path = trim($this->config->getAppValue(Application::APP_ID, self::SETTING_FOLDER, self::DEFAULT_FOLDER));
		$path = trim(str_replace('\\', '/', $path), '/');
		return $path !== '' ? $path : self::DEFAULT_FOLDER;
	}

	/** Ob der abschaltbare Dauer-Hinweis „Mandat ohne Nachweis" grundsätzlich aktiv ist (Default an, Spec §3.2). */
	public function showMissingDocumentWarning(): bool {
		return $this->config->getAppValue(Application::APP_ID, self::SETTING_SHOW_MISSING_WARNING, '1') === '1';
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
					throw new \RuntimeException($this->l10n->t("Pfadkomponente '%s' ist kein Ordner", [$part]));
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
			throw new \RuntimeException($this->l10n->t('Für die Mandats-Nachweisablage ist noch kein Nextcloud-Nutzer eingerichtet (Einstellungen → Belegablage).'));
		}
		$folder = $this->ensureFolder($this->rootFolder->getUserFolder($this->owner()), $this->folderPath());
		$name = $folder->getNonExistingName($mandate->getMandateReference() . '_' . SafeFileName::of($fileName, 150));
		$file = $folder->newFile($name);
		$file->putContent($content);
		return $file->getId();
	}

	/** Die verwiesene Datei, oder null wenn sie fehlt oder keine hinterlegt ist. */
	public function nodeOrNull(Mandate $mandate): ?File {
		$fileId = $mandate->getDocumentFileId();
		if ($fileId === null || !$this->isConfigured()) {
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
		return $mandate->getDocumentFileId() !== null && $this->nodeOrNull($mandate) !== null;
	}
}
