<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Backup;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ITempManager;

/**
 * Zwischenablage der fertigen Sicherung (Issue #111).
 *
 * Erstellen und Herunterladen sind zwei Anfragen: die erste meldet der
 * Oberfläche, was gesichert wurde und was fehlte (oder woran es
 * scheiterte), die zweite ist der Download des Browsers – so liegt die
 * Datei nie im Speicher der Seite. Dazwischen liegt sie hier, in der
 * app-internen Ablage, unter einem zufälligen Token.
 *
 * Abgelegt ist nur die bereits verschlüsselte Sicherung. Es gibt höchstens
 * eine: eine neue verdrängt eine nicht abgeholte, und das Abholen löscht sie.
 */
class BackupDownloadStore {

	private const FOLDER = 'backup-downloads';

	private IAppData $appData;

	public function __construct(
		IAppDataFactory $appDataFactory,
		private ITempManager $tempManager,
	) {
		$this->appData = $appDataFactory->get(Application::APP_ID);
	}

	/**
	 * Legt die Datei `$path` zum Abholen bereit.
	 *
	 * @return string das Token für {@see take()}
	 */
	public function put(string $path, string $fileName): string {
		$folder = $this->folder();
		foreach ($folder->getDirectoryListing() as $old) {
			$old->delete();
		}
		$token = bin2hex(random_bytes(16));
		$stream = fopen($path, 'rb');
		if ($stream === false) {
			throw new \RuntimeException('Sicherung: fertige Datei lässt sich nicht lesen.');
		}
		try {
			// Der Dateiname der Sicherung reist im Namen der abgelegten Datei mit.
			$folder->newFile($token . '_' . rtrim(strtr(base64_encode($fileName), '+/', '-_'), '='), $stream);
		} finally {
			if (is_resource($stream)) {
				fclose($stream);
			}
		}
		return $token;
	}

	/**
	 * Holt die bereitgelegte Datei in eine temporäre Datei und entfernt sie
	 * aus der Ablage.
	 *
	 * @return array{path: string, fileName: string}|null null bei unbekanntem (oder schon benutztem) Token
	 */
	public function take(string $token): ?array {
		if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
			return null;
		}
		foreach ($this->folder()->getDirectoryListing() as $file) {
			$name = $file->getName();
			if (!str_starts_with($name, $token . '_')) {
				continue;
			}
			$source = $file->read();
			$path = $this->tempManager->getTemporaryFile('.vbhbackup');
			$target = fopen($path, 'wb');
			if (!is_resource($source) || $target === false) {
				throw new \RuntimeException('Sicherung: bereitgelegte Datei lässt sich nicht lesen.');
			}
			stream_copy_to_stream($source, $target);
			fclose($source);
			fclose($target);
			$file->delete();

			$fileName = base64_decode(strtr(substr($name, strlen($token) + 1), '-_', '+/'), true);
			return ['path' => $path, 'fileName' => is_string($fileName) && $fileName !== '' ? $fileName : 'vereinsbuchhaltung.vbhbackup'];
		}
		return null;
	}

	private function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (\Throwable) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}
}
