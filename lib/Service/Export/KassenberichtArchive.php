<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Period;
use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IL10N;

/**
 * Legt den Kassenbericht beim Abschluss eines Geschäftsjahres als PDF ab
 * (Issue #364): ein fester Stand des abgeschlossenen Jahres, statt ihn bei
 * jedem Aufruf neu zu berechnen.
 *
 * Abgelegt wird im Home des Belegablage-Nutzers unter dem Berichtsordner, wie
 * Rechnungen unter ihrem Ordner. Ohne Belegablage-Nutzer gibt es kein Ziel, und
 * es bleibt beim Abschluss ohne Datei. Wird ein Jahr wiedereröffnet und erneut
 * abgeschlossen, entsteht eine weitere Datei („(2)“); die alte bleibt als
 * Nachweis des früheren Standes liegen.
 */
class KassenberichtArchive {

	public const SETTING_ENABLED = 'report_archive_on_close';
	public const SETTING_FOLDER = 'report_folder';
	public const DEFAULT_FOLDER = 'Vereinsbuchhaltung/Berichte';

	public function __construct(
		private KassenberichtPdfRenderer $renderer,
		private AttachmentStorageService $storage,
		private IRootFolder $rootFolder,
		private IAppConfig $config,
		private AuditService $audit,
		private IL10N $l10n,
	) {
	}

	public function enabled(): bool {
		return $this->config->getValueString(Application::APP_ID, self::SETTING_ENABLED, '0') === '1';
	}

	public function folder(): string {
		$folder = trim($this->config->getValueString(Application::APP_ID, self::SETTING_FOLDER, ''), '/');
		return $folder !== '' ? $folder : self::DEFAULT_FOLDER;
	}

	/**
	 * Erzeugt und legt das PDF ab, wenn die Ablage eingeschaltet und ein
	 * Belegablage-Nutzer eingerichtet ist.
	 *
	 * @return string|null Pfad der Datei im Home des Ablage-Nutzers, null wenn nichts abzulegen war
	 * @throws \RuntimeException wenn Erzeugen oder Ablegen scheitern
	 */
	public function storeOnClose(string $userId, Period $period): ?string {
		if (!$this->enabled()) {
			return null;
		}
		$user = $this->storage->storageUser();
		if ($user === '') {
			return null;
		}
		$content = $this->renderer->render($userId, $period);
		$folder = $this->ensureFolder($this->rootFolder->getUserFolder($user), $this->folder());
		$file = $folder->newFile($folder->getNonExistingName($this->renderer->fileName($period)));
		$file->putContent($content);
		$path = $this->folder() . '/' . $file->getName();
		$this->audit->log('Kassenbericht abgelegt', 'period', (int)$period->getId(), [
			'zeitraum' => $period->getLabel(),
			'datei' => $path,
			'sha256' => hash('sha256', $content),
		]);
		return $path;
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
