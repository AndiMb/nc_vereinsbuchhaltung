<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Liest Kontoauszüge aus einem überwachten Nextcloud-Ordner ein.
 *
 * Gedacht für den Monatsablauf: den im Onlinebanking heruntergeladenen Auszug
 * in einen Ordner legen, den Rest erledigt die App. Das ersetzt nicht das
 * Herunterladen bei der Bank – wohl aber das Hochladen von Hand, samt der
 * Frage, welches der Formate die App denn nun mag.
 *
 * Verarbeitete Dateien wandern nach „verarbeitet/", fehlgeschlagene nach
 * „fehler/" – gelöscht wird nichts. Wer einen Auszug im falschen Format
 * ablegt, soll ihn wiederfinden.
 */
class WatchFolderService {

	public const SETTING_USER = 'statement_watch_user';
	public const SETTING_PATH = 'statement_watch_path';

	/** Der Akteur, unter dem automatische Läufe im Protokoll erscheinen. */
	public const ACTOR = 'automatisch (Wachordner)';

	private const DONE_FOLDER = 'verarbeitet';
	private const FAILED_FOLDER = 'fehler';

	/** Größere Dateien sind kein Kontoauszug mehr, sondern ein Versehen. */
	private const MAX_BYTES = 20 * 1024 * 1024;

	public function __construct(
		private IConfig $config,
		private IRootFolder $rootFolder,
		private ImportService $importService,
		private AuditService $audit,
		private RevisionService $revision,
		private LoggerInterface $logger,
	) {
	}

	public function isConfigured(): bool {
		return $this->watchUser() !== '' && $this->watchPath() !== '';
	}

	private function watchUser(): string {
		return trim($this->config->getAppValue(Application::APP_ID, self::SETTING_USER, ''));
	}

	private function watchPath(): string {
		return trim($this->config->getAppValue(Application::APP_ID, self::SETTING_PATH, ''), '/');
	}

	/**
	 * Verarbeitet alle Dateien im Wachordner.
	 *
	 * @return array<int, array{file:string, ok:bool, new?:int, duplicate?:int,
	 *         autoAssigned?:int, ruleFailed?:int, format?:string, error?:string}>
	 */
	public function run(): array {
		if (!$this->isConfigured()) {
			return [];
		}

		$folder = $this->folder();
		if ($folder === null) {
			return [];
		}

		$results = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if (!$this->isCandidate($node)) {
				continue;
			}
			$results[] = $this->handle($folder, $node);
		}

		if ($results !== []) {
			$erfolgreich = count(array_filter($results, static fn (array $r): bool => $r['ok']));
			$neu = array_sum(array_column($results, 'new'));

			$details = [
				'dateien' => count($results),
				'erfolgreich' => $erfolgreich,
				'neu' => $neu,
			];
			// Nur vermerken, wenn tatsächlich etwas liegen blieb – sonst stünde
			// in jedem Protokolleintrag eine nichtssagende Null. Sichtbar wird
			// so der Fall, den niemand mitbekommt: eine Regel hätte zugeordnet,
			// konnte aber nicht (etwa weil das Geschäftsjahr abgeschlossen ist).
			$offen = array_sum(array_column($results, 'ruleFailed'));
			if ($offen > 0) {
				$details['nicht zugeordnet'] = $offen;
			}
			$this->audit->log('Wachordner-Import', 'import', null, $details, self::ACTOR);

			// Den Änderungsstand von Hand hochsetzen: das erledigt sonst die
			// RevisionMiddleware, die nur bei HTTP-Schreibzugriffen greift. Ohne
			// diesen Aufruf pollen offene Browser weiter gegen ein unverändertes
			// Token und zeigten die neuen Umsätze erst nach dem nächsten
			// Neuladen an.
			if ($neu > 0) {
				$this->revision->bump();
			}
		}
		return $results;
	}

	/**
	 * @return array{file:string, ok:bool, new?:int, duplicate?:int,
	 *         autoAssigned?:int, ruleFailed?:int, format?:string, error?:string}
	 */
	private function handle(Folder $folder, Node $node): array {
		$name = $node->getName();
		try {
			$content = $node->getContent();
			if (!is_string($content) || $content === '') {
				throw new \RuntimeException('Die Datei ist leer.');
			}

			$result = $this->importService->commit(Application::BOOK, $name, $content);

			$this->moveTo($folder, $node, self::DONE_FOLDER);
			return [
				'file' => $name,
				'ok' => true,
				'format' => (string)($result['format'] ?? ''),
				'new' => (int)($result['new'] ?? 0),
				'duplicate' => (int)($result['duplicate'] ?? 0),
				'autoAssigned' => (int)($result['autoAssigned'] ?? 0),
				// Zugeordnet werden konnte nicht alles, was eine Regel treffen
				// wollte – etwa weil das Jahr schon abgeschlossen ist. Beim
				// Import von Hand sieht man das in der Liste; hier nicht.
				'ruleFailed' => (int)($result['ruleFailed'] ?? 0),
			];
		} catch (\Throwable $e) {
			$this->logger->warning('Wachordner: {file} konnte nicht eingelesen werden', [
				'app' => Application::APP_ID,
				'file' => $name,
				'exception' => $e,
			]);
			$this->moveTo($folder, $node, self::FAILED_FOLDER, $e->getMessage());
			return ['file' => $name, 'ok' => false, 'error' => $e->getMessage()];
		}
	}

	/**
	 * Verschiebt die Datei in einen Unterordner und legt bei Fehlern eine
	 * Erklärung daneben. Schlägt das Verschieben fehl, wird die Datei beim
	 * nächsten Lauf erneut gelesen – die Dublettenerkennung fängt das ab, es
	 * entstehen also keine doppelten Umsätze.
	 */
	private function moveTo(Folder $folder, Node $node, string $subfolder, ?string $error = null): void {
		try {
			$target = $folder->nodeExists($subfolder)
				? $folder->get($subfolder)
				: $folder->newFolder($subfolder);
			if (!$target instanceof Folder) {
				return;
			}

			$name = $this->freeName($target, $node->getName());
			$node->move($target->getPath() . '/' . $name);

			if ($error !== null) {
				$target->newFile(
					$name . '.fehler.txt',
					'Diese Datei konnte am ' . (new \DateTime())->format('d.m.Y H:i')
					. ' nicht eingelesen werden:' . "\n\n" . $error . "\n\n"
					. 'Die Datei wurde nicht verändert. Nach dem Beheben der Ursache kann sie '
					. 'wieder in den Wachordner zurückgelegt werden.' . "\n"
				);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Wachordner: {file} konnte nicht verschoben werden', [
				'app' => Application::APP_ID,
				'file' => $node->getName(),
				'exception' => $e,
			]);
		}
	}

	/** Hängt bei Namenskonflikt einen Zeitstempel an, statt zu überschreiben. */
	private function freeName(Folder $target, string $name): string {
		if (!$target->nodeExists($name)) {
			return $name;
		}
		return self::stampedName($name, (new \DateTime())->format('Ymd-His'));
	}

	/**
	 * Setzt den Zeitstempel vor die Dateiendung, nicht dahinter.
	 *
	 * Sonst hieße die Datei "auszug.csv_20260730-101500" und wäre weder für
	 * Nextcloud noch für den Menschen als CSV erkennbar – ausgerechnet in dem
	 * Ordner, in dem man sie im Zweifel wiederfinden will.
	 */
	public static function stampedName(string $name, string $stamp): string {
		$dot = strrpos($name, '.');
		// Ein Punkt an Position 0 gehört zu einer versteckten Datei (".auszug"),
		// das ist keine Endung.
		if ($dot === false || $dot === 0) {
			return $name . '_' . $stamp;
		}
		return substr($name, 0, $dot) . '_' . $stamp . substr($name, $dot);
	}

	private function isCandidate(Node $node): bool {
		if ($node instanceof Folder) {
			return false;
		}
		// Eigene Ablageordner und die Fehlerhinweise nicht wieder einlesen.
		if (str_ends_with($node->getName(), '.fehler.txt')) {
			return false;
		}
		try {
			return $node->getSize() > 0 && $node->getSize() <= self::MAX_BYTES;
		} catch (\Throwable) {
			return false;
		}
	}

	private function folder(): ?Folder {
		try {
			$userFolder = $this->rootFolder->getUserFolder($this->watchUser());
			$path = $this->watchPath();
			if (!$userFolder->nodeExists($path)) {
				// Bewusst nicht anlegen: ein Tippfehler im Pfad soll auffallen
				// und nicht stillschweigend einen zweiten, leeren Ordner erzeugen.
				return null;
			}
			$node = $userFolder->get($path);
			return $node instanceof Folder ? $node : null;
		} catch (\Throwable $e) {
			$this->logger->warning('Wachordner nicht erreichbar', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return null;
		}
	}
}
