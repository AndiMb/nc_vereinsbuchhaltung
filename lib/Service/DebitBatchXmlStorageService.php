<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\DebitBatch;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IL10N;

/**
 * Optionale XML-Ablage im NC-Ordner (Spec §2.2/§3.5 „Ablage in einen
 * NC-Ordner: optional, Default aus – die Datei enthält alle IBANs im
 * Klartext", Issue #71). Default aus, `verwalter`-Einstellung (Spec §3.9).
 *
 * Dasselbe Ein-Ablageort-Muster wie {@see MandateDocumentService}: ein
 * Ordner im Home des bereits für die Belegablage eingerichteten
 * Nextcloud-Nutzers ({@see AttachmentStorageService::storageUser()}), kein
 * eigener „welcher Nutzer"-Schalter. Anders als beim Mandats-Nachweis wird
 * hier keine Datei-ID zurückgeschrieben – die abgelegte Kopie ist reine
 * Compliance-Ablage, kein von der App weiter referenziertes Dokument.
 */
class DebitBatchXmlStorageService {

	public const SETTING_ENABLED = 'xml_folder_enabled';
	public const SETTING_PATH = 'xml_folder_path';
	public const DEFAULT_PATH = 'SEPA-Einreichungen';

	public function __construct(
		private IRootFolder $rootFolder,
		private IConfig $config,
		private AttachmentStorageService $attachmentStorage,
		private IL10N $l10n,
	) {
	}

	public function isEnabled(): bool {
		return $this->config->getAppValue(Application::APP_ID, self::SETTING_ENABLED, '0') === '1';
	}

	public function setEnabled(bool $enabled): void {
		$this->config->setAppValue(Application::APP_ID, self::SETTING_ENABLED, $enabled ? '1' : '0');
	}

	public function folderPath(): string {
		$path = trim(str_replace('\\', '/', $this->config->getAppValue(Application::APP_ID, self::SETTING_PATH, self::DEFAULT_PATH)), '/');
		return $path !== '' ? $path : self::DEFAULT_PATH;
	}

	public function setFolderPath(string $path): void {
		$path = trim(str_replace('\\', '/', $path), '/');
		$this->config->setAppValue(Application::APP_ID, self::SETTING_PATH, $path !== '' ? $path : self::DEFAULT_PATH);
	}

	/** Ob überhaupt ein Nutzer-Home als Ablageort eingerichtet ist. */
	public function isConfigured(): bool {
		return $this->attachmentStorage->storageUser() !== '';
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
	 * Legt die pain.008-Datei eines Laufs ab – nur, wenn die Ablage
	 * eingeschaltet UND ein Ablage-Nutzer eingerichtet ist; sonst ein stiller
	 * No-Op, denn die Ablage ist ausdrücklich optional (Default aus) und darf
	 * eine erfolgreiche Freigabe/Terminverschiebung nicht blockieren – siehe
	 * {@see DebitBatchService::release()} (dort landet ein Fehlschlag hier nur
	 * im Audit-Protokoll, nicht als geworfene Exception).
	 *
	 * @throws \RuntimeException wenn die Ablage eingeschaltet, aber kein
	 *                           Ablage-Nutzer eingerichtet ist
	 */
	public function store(DebitBatch $batch, string $xml): void {
		if (!$this->isEnabled()) {
			return;
		}
		if (!$this->isConfigured()) {
			throw new \RuntimeException($this->l10n->t('Für die XML-Ablage ist noch kein Nextcloud-Nutzer eingerichtet (Einstellungen → Belegablage).'));
		}
		$folder = $this->ensureFolder($this->rootFolder->getUserFolder($this->attachmentStorage->storageUser()), $this->folderPath());
		$name = $folder->getNonExistingName($batch->getMsgId() . '.xml');
		$file = $folder->newFile($name);
		$file->putContent($xml);
	}
}
