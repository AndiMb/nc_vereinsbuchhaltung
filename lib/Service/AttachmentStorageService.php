<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Attachment;
use OCA\Vereinsbuchhaltung\Db\AttachmentMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IConfig;
use OCP\IL10N;

/**
 * Wo die Beleg-Dateien liegen und wie man an sie herankommt.
 *
 * Drei Ablagen: app-intern (AppData), ein Ordner im Home eines Nextcloud-
 * Nutzers, in dem die App je Buchung einen Unterordner anlegt, oder der
 * Wächter-Ordner. Im Wächter-Ordner gehören die Dateien dem Nutzer: die App
 * legt Uploads unter <Ordner>/<Jahr>/ ab, merkt sich die Nextcloud-Datei-ID
 * ({@see Attachment::isLinked()}) und löscht dort nie etwas.
 *
 * Maßgeblich für den Zugriff ist die Zeile, nicht der eingestellte Modus:
 * verknüpfte Belege gibt es nach einem Moduswechsel in jeder Ablage, und
 * Belege ohne Verweis liegen weiter unter dem berechneten Pfad.
 */
class AttachmentStorageService {

	/** Leerer Nutzer = app-interne Ablage (AppData), gesetzter Nutzer = sein Nextcloud-Home. */
	public const SETTING_USER = 'storage_user';
	public const SETTING_PATH = 'storage_path';
	/** Unterscheidet bei gesetztem Nutzer zwischen App-Ablage und Wächter-Ordner. */
	public const SETTING_MODE = 'storage_mode';
	public const DEFAULT_PATH = 'Vereinsbuchhaltung/Belege';

	public const MODE_APPDATA = 'appdata';
	public const MODE_USER = 'user';
	public const MODE_WATCH = 'watch';

	private $appData;

	private ?Folder $watchRoot = null;
	private bool $watchRootResolved = false;

	public function __construct(
		IAppDataFactory $appDataFactory,
		private IRootFolder $rootFolder,
		private IConfig $config,
		private AttachmentMapper $attachmentMapper,
		private TransactionRunner $transaction,
		private IL10N $l10n,
	) {
		$this->appData = $appDataFactory->get(Application::APP_ID);
	}

	// --- Einstellung -------------------------------------------------------

	public function storageUser(): string {
		return $this->config->getAppValue(Application::APP_ID, self::SETTING_USER, '');
	}

	public function storagePath(): string {
		return trim($this->config->getAppValue(Application::APP_ID, self::SETTING_PATH, self::DEFAULT_PATH), '/');
	}

	/**
	 * Eine der MODE_*-Konstanten. Ohne Nutzer immer die app-interne Ablage –
	 * so war die Einstellung vor dem Wächter-Ordner definiert, und so lässt
	 * sie sich auch per occ nicht in einen Zustand ohne Nutzer-Home bringen.
	 */
	public function mode(): string {
		if ($this->storageUser() === '') {
			return self::MODE_APPDATA;
		}
		$mode = $this->config->getAppValue(Application::APP_ID, self::SETTING_MODE, '');
		return $mode === self::MODE_WATCH ? self::MODE_WATCH : self::MODE_USER;
	}

	/** Liegen die Dateien im Nextcloud-Dateibaum (Nutzerordner oder Wächter-Ordner)? */
	public function isNcMode(): bool {
		return $this->mode() !== self::MODE_APPDATA;
	}

	public function isWatchMode(): bool {
		return $this->mode() === self::MODE_WATCH;
	}

	/**
	 * Stellt die Belegablage auf die app-interne zurück, wenn sie im Home
	 * dieses Nutzers lag – neue Belege haben damit sofort wieder einen Platz.
	 * Gegenstück zu {@see SepaDebtorAccountService::forgetIfSetTo()};
	 * aufgerufen vom UserDeletedListener.
	 *
	 * @return bool ob die Ablage tatsächlich zurückgestellt wurde
	 */
	public function forgetUser(string $uid): bool {
		if ($this->storageUser() !== $uid) {
			return false;
		}
		$this->config->setAppValue(Application::APP_ID, self::SETTING_USER, '');
		$this->config->setAppValue(Application::APP_ID, self::SETTING_MODE, '');
		return true;
	}

	// --- Ordner im Dateibaum -----------------------------------------------

	/**
	 * Ein Ordner unter dem Home eines Nutzers, oder null, wenn dort keiner
	 * liegt. Bewusst nicht anlegen: ein Tippfehler im Pfad soll auffallen,
	 * nicht still einen leeren Ordner erzeugen (dasselbe Prinzip wie beim
	 * Wachordner für Kontoauszüge).
	 */
	public function folderAt(string $uid, string $path): ?Folder {
		try {
			$home = $this->userFolder($uid);
			$node = $path === '' ? $home : $home->get($path);
		} catch (\Throwable) {
			return null;
		}
		return $node instanceof Folder ? $node : null;
	}

	public function watchFolder(): ?Folder {
		return $this->isWatchMode() ? $this->folderAt($this->storageUser(), $this->storagePath()) : null;
	}

	/**
	 * Die Unterordner eines Ordners im Home eines Nutzers – für die Ordnerwahl
	 * in den Einstellungen. Versteckte Ordner bleiben außen vor, wie beim Scan
	 * des Wächter-Ordners.
	 *
	 * @return list<array{name: string, path: string}>|null null, wenn es den Ordner nicht gibt
	 */
	public function subfoldersAt(string $uid, string $path): ?array {
		$folder = $this->folderAt($uid, $path);
		if ($folder === null) {
			return null;
		}
		$out = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if (!$node instanceof Folder || str_starts_with($node->getName(), '.')) {
				continue;
			}
			$out[] = ['name' => $node->getName(), 'path' => ltrim($path . '/' . $node->getName(), '/')];
		}
		usort($out, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
		return $out;
	}

	private function userFolder(string $uid): Folder {
		return $this->rootFolder->getUserFolder($uid);
	}

	private function ensureFolder(Folder $root, string $path): Folder {
		$parts = array_values(array_filter(explode('/', $path)));
		$current = $root;
		foreach ($parts as $part) {
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

	private function getNcFolder(int $journalId): Folder {
		return $this->ensureFolder($this->userFolder($this->storageUser()), $this->storagePath() . '/' . $journalId);
	}

	private function appDataFolder() {
		try {
			return $this->appData->getFolder('attachments');
		} catch (\OCP\Files\NotFoundException) {
			return $this->appData->newFolder('attachments');
		}
	}

	// --- Ein Beleg und seine Datei -----------------------------------------

	/**
	 * Löst den Dateiverweis eines Belegs auf; null für Belege ohne Verweis
	 * oder wenn die Datei nicht (mehr) da ist.
	 *
	 * Gesucht wird nur im Home des Besitzers: dort kann die Datei umbenannt
	 * oder verschoben worden sein, die Kennung bleibt. Im Papierkorb liegt sie
	 * außerhalb dieses Baums und gilt bis zum Wiederherstellen als fehlend.
	 */
	/**
	 * Die verwiesene Datei – oder null, wenn sie fehlt. Im Wächter-Modus zählt
	 * nur, was im Wächter-Ordner liegt: eine hinausgeschobene Datei gilt als
	 * fehlend, sonst bliebe sie über die App für alle Leser erreichbar, auch
	 * wenn sie in der Dateien-App längst in einem privaten Ordner liegt.
	 */
	public function nodeOrNull(Attachment $attachment): ?File {
		if (!$attachment->isLinked()) {
			return null;
		}
		try {
			$root = $this->isWatchMode() ? $this->watchRoot() : $this->userFolder((string)$attachment->getFileOwner());
			$node = $root?->getFirstNodeById((int)$attachment->getFileId());
		} catch (\Throwable) {
			return null;
		}
		return $node instanceof File ? $node : null;
	}

	/** Der Wächter-Ordner, je Anfrage einmal aufgelöst – nodeOrNull() läuft je Beleg. */
	private function watchRoot(): ?Folder {
		if (!$this->watchRootResolved) {
			$this->watchRoot = $this->watchFolder();
			$this->watchRootResolved = true;
		}
		return $this->watchRoot;
	}

	/** @throws \RuntimeException wenn die Datei nicht (mehr) auffindbar ist */
	private function nodeFor(Attachment $attachment): File {
		return $this->nodeOrNull($attachment)
			?? throw new \RuntimeException($this->l10n->t('Die Datei zu diesem Beleg wurde nicht gefunden.'));
	}

	/** Ist die verwiesene Datei noch da? Für Belege ohne Verweis immer true. */
	public function exists(Attachment $attachment): bool {
		return !$attachment->isLinked() || $this->nodeOrNull($attachment) !== null;
	}

	/**
	 * Bleibt die Datei beim Löschen des Belegs stehen? Verwiesene Dateien und
	 * alles im Wächter-Ordner gehören dem Verein, nicht der App – dort löst
	 * „Beleg löschen" nur die Verknüpfung.
	 */
	public function keepsFile(Attachment $attachment): bool {
		return $attachment->isLinked() || $this->isWatchMode();
	}

	/** Der Nutzer, in dessen Home der Beleg liegt – null bei app-interner Ablage. */
	public function ownerOf(Attachment $attachment): ?string {
		if ($attachment->isLinked()) {
			return $attachment->getFileOwner();
		}
		return $this->isNcMode() ? $this->storageUser() : null;
	}

	/**
	 * Pfad relativ zum Home des Besitzers, mit führendem Schrägstrich – für
	 * den Nextcloud-Viewer. Null, wenn die Datei nicht im Dateibaum liegt.
	 *
	 * @param File|null $node der schon aufgelöste Knoten, damit der Aufrufer
	 *                        ihn nicht ein zweites Mal suchen muss
	 */
	public function ncPathOf(Attachment $attachment, ?File $node): ?string {
		if ($attachment->isLinked()) {
			if ($node === null) {
				return null;
			}
			$path = $this->userFolder((string)$attachment->getFileOwner())->getRelativePath($node->getPath());
			return $path === null ? null : '/' . ltrim($path, '/');
		}
		return $this->isNcMode() ? '/' . $this->getNcFilePath($attachment->getId(), $attachment->getJournalId(), $attachment->getFileName()) : null;
	}

	/**
	 * Pfad der Datei relativ zum Nutzer-Home (ohne führenden Slash) – unter
	 * der aktuellen Ablage oder, für den Backfill, unter einer früheren.
	 */
	public function getNcFilePath(int $id, int $journalId, string $fileName, ?string $basePath = null): string {
		return ($basePath ?? $this->storagePath()) . '/' . $journalId . '/' . $this->ncFileName($id, $fileName);
	}

	private function ncFileName(int $id, string $fileName): string {
		return $id . '_' . preg_replace('/[^\w.\-]/', '_', $fileName);
	}

	/**
	 * Die Beleg-Datei unter einem Namen im Nextcloud-Dateibaum.
	 *
	 * Folder::get() liefert einen Node – das kann auch ein Ordner sein. Nur eine
	 * File hat getContent()/putContent(). Liegt an der Stelle etwas anderes, ist
	 * die Ablage nicht so aufgebaut, wie diese Klasse sie anlegt; dann lieber
	 * eine verständliche Meldung als ein Aufruf ins Leere.
	 *
	 * @throws \RuntimeException wenn dort keine Datei liegt
	 */
	private function ncFile(Folder $folder, string $name): File {
		$node = $folder->get($name);
		if (!$node instanceof File) {
			throw new \RuntimeException($this->l10n->t('In der Belegablage liegt unter "%s" keine Datei.', [$name]));
		}
		return $node;
	}

	// --- Schreiben ----------------------------------------------------------

	/**
	 * Legt den Inhalt eines eben angelegten Belegs ab. Im Wächter-Ordner wird
	 * der Beleg dabei zum Verweis auf die neue Datei und aktualisiert.
	 *
	 * @param int $year Jahr des Buchungsdatums, bestimmt den Jahresordner im Wächter-Ordner
	 */
	public function store(Attachment $attachment, int $year, string $content): Attachment {
		if (!$this->isWatchMode()) {
			$this->putFile($attachment->getId(), $attachment->getJournalId(), $attachment->getFileName(), $content);
			return $attachment;
		}
		$file = $this->putWatchFile($year, $attachment->getFileName(), $content);
		$attachment->setFileName($file->getName());
		$this->attach($attachment, $file);
		return $this->attachmentMapper->update($attachment);
	}

	/** Macht den Beleg zum Verweis auf diese Datei im Home des Ablage-Nutzers – oder des genannten Besitzers. */
	public function attach(Attachment $attachment, File $file, ?string $owner = null): void {
		$attachment->setFileId($file->getId());
		$attachment->setFileOwner($owner ?? $this->storageUser());
	}

	private function putFile(int $id, int $journalId, string $fileName, string $content): void {
		if ($this->isNcMode()) {
			$folder = $this->getNcFolder($journalId);
			$name = $this->ncFileName($id, $fileName);
			if ($folder->nodeExists($name)) {
				$this->ncFile($folder, $name)->putContent($content);
			} else {
				$folder->newFile($name)->putContent($content);
			}
		} else {
			$this->appDataFolder()->newFile((string)$id)->putContent($content);
		}
	}

	/**
	 * Legt einen Upload im Wächter-Ordner ab, unter <Ordner>/<Jahr>/<Name>.
	 *
	 * Der Jahresordner ist der einzige Ort, den die App dort beschreibt; alles
	 * andere im Ordner ordnet der Nutzer selbst. Der Dateiname bleibt lesbar
	 * (kein ID-Präfix wie in der App-Ablage), bei Namensgleichheit vergibt
	 * Nextcloud den üblichen Zusatz „(2)".
	 *
	 * @throws \RuntimeException wenn der Wächter-Ordner nicht existiert
	 */
	private function putWatchFile(int $year, string $fileName, string $content): File {
		$folder = $this->watchFolder();
		if ($folder === null) {
			throw new \RuntimeException($this->l10n->t('Der Wächter-Ordner für Belege existiert nicht. Bitte in den Einstellungen prüfen.'));
		}
		$target = $this->ensureFolder($folder, (string)$year);
		$file = $target->newFile($target->getNonExistingName(SafeFileName::of($fileName, 200)));
		$file->putContent($content);
		return $file;
	}

	// --- Lesen ----------------------------------------------------------------

	public function contentOf(Attachment $attachment): string {
		return $attachment->isLinked()
			? $this->nodeFor($attachment)->getContent()
			: $this->legacyFile($attachment)->getContent();
	}

	/**
	 * Die Datei eines Belegs ohne Verweis: unter <Ablage>/<BuchungsID>/ im
	 * Nutzer-Home, sonst in der app-internen Ablage. Beide Orte werden nur
	 * gelesen – einen fehlenden Ordner legt das Lesen nicht an, das setzte im
	 * Wächter-Ordner leere Buchungsordner ins Archiv. Der Rückfall auf die
	 * app-interne Ablage hält Belege aus der Zeit vor einem Wechsel der
	 * Ablage lesbar.
	 *
	 * @throws NotFoundException wenn die Datei an keinem der beiden Orte liegt
	 */
	private function legacyFile(Attachment $attachment): File|ISimpleFile {
		if ($this->isNcMode()) {
			$folder = $this->existingFolder($this->userFolder($this->storageUser()), $this->storagePath() . '/' . $attachment->getJournalId());
			if ($folder !== null) {
				try {
					return $this->ncFile($folder, $this->ncFileName($attachment->getId(), $attachment->getFileName()));
				} catch (NotFoundException) {
					// Nicht im Nutzerordner – vielleicht aus der app-internen Zeit.
				}
			}
		}
		return $this->appDataFolder()->getFile((string)$attachment->getId());
	}

	/** Wie ensureFolder(), legt aber nichts an: null, sobald ein Teil des Pfads fehlt. */
	private function existingFolder(Folder $root, string $path): ?Folder {
		$current = $root;
		foreach (array_values(array_filter(explode('/', $path))) as $part) {
			if (!$current->nodeExists($part)) {
				return null;
			}
			$node = $current->get($part);
			if (!$node instanceof Folder) {
				throw new \RuntimeException($this->l10n->t("Pfadkomponente '%s' ist kein Ordner", [$part]));
			}
			$current = $node;
		}
		return $current;
	}

	/**
	 * Wie {@see contentOf()}, liefert die Datei aber als Lesestrom.
	 *
	 * Für den ZIP-Export der Belege: dort werden potenziell hunderte Dateien zu
	 * je bis zu 20 MB verarbeitet, die nicht alle gleichzeitig in den Speicher
	 * passen müssen.
	 *
	 * Die Ablagen liefern verschiedene Dateiobjekte, und die haben für
	 * dasselbe Anliegen verschiedene Methoden: der Nextcloud-Dateibaum eine
	 * {@see File} mit fopen(), die app-interne Ablage eine
	 * {@see \OCP\Files\SimpleFS\ISimpleFile} mit read(). Deshalb wird der Strom
	 * in jedem Zweig einzeln geholt statt hinterher gemeinsam – ein fopen() auf
	 * der ISimpleFile gibt es nicht und lief bis 0.31.1 in einen
	 * Undefined-Method-Fehler, der den ZIP-Export bei app-interner Ablage jeden
	 * Beleg als "nicht gefunden" melden ließ (Issue #40).
	 *
	 * @return resource
	 */
	public function streamOf(Attachment $attachment) {
		if ($attachment->isLinked()) {
			$stream = $this->nodeFor($attachment)->fopen('r');
		} else {
			$file = $this->legacyFile($attachment);
			$stream = $file instanceof File ? $file->fopen('r') : $file->read();
		}
		if (!is_resource($stream)) {
			throw new \RuntimeException($this->l10n->t('Beleg-Datei konnte nicht geöffnet werden.'));
		}
		return $stream;
	}

	// --- Löschen --------------------------------------------------------------

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
			$this->deleteOne($attachment);
		}
	}

	/**
	 * Löscht einen einzelnen Beleg – Datensatz und Datei, in dieser Reihenfolge.
	 *
	 * Der einzige richtige Weg, einen Beleg loszuwerden, auch außerhalb einer
	 * Transaktion: {@see TransactionRunner::afterCommit()} führt die Aufgabe
	 * dann sofort aus, aber eben erst nachdem der Datensatz weg ist. Wer die
	 * Datei zuerst löscht, hat bei einem Fehler auf der Datenbankseite einen
	 * Beleg-Datensatz ohne Datei – und die ist nicht wiederherstellbar.
	 *
	 * @return bool ob die Datei stehen geblieben ist ({@see keepsFile()})
	 */
	public function deleteOne(Attachment $attachment): bool {
		$id = $attachment->getId();
		$journalId = $attachment->getJournalId();
		$fileName = $attachment->getFileName();
		$keep = $this->keepsFile($attachment);
		$this->attachmentMapper->delete($attachment);
		if (!$keep) {
			$this->transaction->afterCommit(function () use ($id, $journalId, $fileName): void {
				$this->deleteFile($id, $journalId, $fileName);
			});
		}
		return $keep;
	}

	private function deleteFile(int $id, int $journalId, string $fileName): void {
		try {
			if ($this->isNcMode()) {
				$userFolder = $this->userFolder($this->storageUser());
				$path = $this->getNcFilePath($id, $journalId, $fileName);
				if ($userFolder->nodeExists($path)) {
					$userFolder->get($path)->delete();
					return;
				}
			}
			// Nicht im Nutzerordner: ein Beleg aus der app-internen Zeit, siehe legacyFile().
			$this->appData->getFolder('attachments')->getFile((string)$id)->delete();
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
	 * entfernt. Im Wächter-Ordner wird nichts gelöscht: der ist das Archiv.
	 *
	 * @param array<int, \OCA\Vereinsbuchhaltung\Db\Attachment> $attachments
	 */
	public function deleteAllFiles(array $attachments): void {
		if ($this->isWatchMode()) {
			return;
		}
		if (!$this->isNcMode()) {
			try {
				$this->appData->getFolder('attachments')->delete();
			} catch (\Throwable) {
				// Ordner existiert nicht – kein Fehler.
			}
			return;
		}

		$own = array_filter($attachments, static fn (Attachment $a): bool => !$a->isLinked());
		foreach ($own as $attachment) {
			$this->deleteFile($attachment->getId(), $attachment->getJournalId(), $attachment->getFileName());
		}
		$this->removeEmptyJournalFolders($own);
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
			$userFolder = $this->userFolder($this->storageUser());
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
