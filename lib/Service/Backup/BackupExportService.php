<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Backup;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Service\FieldCipher;
use OCA\Vereinsbuchhaltung\Service\SafeFileName;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\ITempManager;

/**
 * Erstellt die Sicherung des gesamten Bestands (Issue #111).
 *
 * Inhalt (ein ZIP, als Ganzes verschlüsselt durch {@see BackupContainer}):
 *
 *   manifest.json            Format, App-Version, Zeilenzahl und Prüfsumme je Tabelle
 *   config.json              die gesicherten Einstellungen
 *   tables/<tabelle>.jsonl   eine Zeile je Datensatz, wie die Datenbank ihn führt
 *   files/<art>/<id>         Dateien unter der ID ihres Datensatzes
 *   files/logo               das Vereinslogo
 *
 * Spalten, die die Instanz mit ihrem eigenen Schlüssel verschlüsselt (IBANs),
 * stehen in der Sicherung im Klartext – sonst wären sie in einer anderen
 * Instanz verloren. Die Sicherung selbst schützt das Passwort.
 */
class BackupExportService {

	public const FORMAT = 1;

	/** Ab so vielen ungeschriebenen Byte wird das ZIP zwischendurch auf die Platte geschrieben. */
	private const FLUSH_BYTES = 33554432;

	public function __construct(
		private BackupContainer $container,
		private BackupTableStore $tables,
		private BackupConfigStore $config,
		private BackupFileSource $files,
		private FieldCipher $cipher,
		private TransactionRunner $tx,
		private IAppManager $appManager,
		private ITempManager $tempManager,
		private IL10N $l10n,
	) {
	}

	/**
	 * @return array{path: string, fileName: string, rows: int, files: int, missingFiles: list<array{kind: string, id: int}>}
	 *                                                                                                                        `path` ist eine temporäre Datei
	 * @throws UserFacingException zu kurzes Passwort, nicht lesbarer verschlüsselter Wert
	 */
	public function export(string $password, bool $withFiles): array {
		$this->container->assertPassword($password);
		if (!class_exists(\ZipArchive::class)) {
			throw new UserFacingException($this->l10n->t('Auf diesem Server fehlt die PHP-Erweiterung zip.'));
		}

		$zipPath = $this->tempManager->getTemporaryFile('.zip');
		$zip = new \ZipArchive();
		if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			throw new \RuntimeException('Sicherung: ZIP lässt sich nicht anlegen.');
		}
		$pending = 0;
		$tempFiles = [];
		try {
			$settings = $this->config->all();

			// Alle Tabellen in einer Transaktion: ein Stand, auch wenn nebenher gebucht wird.
			$tableInfo = $this->tx->run(function () use ($zip, &$tempFiles): array {
				$info = [];
				foreach (BackupSchema::tables() as $table => $def) {
					$path = $this->tempManager->getTemporaryFile('.jsonl');
					$tempFiles[] = $path;
					$info[$table] = $this->writeTable($table, $def['encrypted'], $path);
					$zip->addFile($path, 'tables/' . $table . '.jsonl');
				}
				return $info;
			});
			$this->flush($zip, $zipPath);

			$fileCount = 0;
			$fileBytes = 0;
			$missing = [];
			if ($withFiles) {
				foreach ($this->files->files() as $file) {
					$content = ($file['read'])();
					if ($content === null) {
						$missing[] = ['kind' => $file['kind'], 'id' => $file['id']];
						continue;
					}
					$name = 'files/' . $file['kind'] . '/' . $file['id'];
					$zip->addFromString($name, $content);
					// Belege sind meist schon komprimiert (PDF, JPEG) – nur ablegen.
					$zip->setCompressionName($name, \ZipArchive::CM_STORE);
					$fileCount++;
					$fileBytes += strlen($content);
					$pending += strlen($content);
					unset($content);
					if ($pending >= self::FLUSH_BYTES) {
						$this->flush($zip, $zipPath);
						$pending = 0;
					}
				}
			}
			$logo = $this->files->logo();
			if ($logo !== null) {
				$zip->addFromString('files/logo', $logo);
			}

			$clubName = trim($settings['club_name'] ?? '');
			$zip->addFromString('config.json', json_encode((object)$settings, self::jsonFlags() | JSON_PRETTY_PRINT));
			$zip->addFromString('manifest.json', json_encode([
				'format' => self::FORMAT,
				'app' => Application::APP_ID,
				'appVersion' => $this->appManager->getAppVersion(Application::APP_ID),
				'createdAt' => date('c'),
				'clubName' => $clubName,
				'withFiles' => $withFiles,
				'tables' => $tableInfo,
				'files' => ['count' => $fileCount, 'bytes' => $fileBytes],
				'missingFiles' => $missing,
			], self::jsonFlags() | JSON_PRETTY_PRINT));
			if ($zip->close() !== true) {
				throw new \RuntimeException('Sicherung: ZIP ließ sich nicht schreiben.');
			}

			$target = $this->tempManager->getTemporaryFile('.vbhbackup');
			$this->container->encrypt($zipPath, $target, $password);
		} catch (\Throwable $e) {
			// Ein noch offenes ZIP schriebe beim Zerstören auf die Platte – verwerfen.
			@$zip->unchangeAll();
			@$zip->close();
			throw $e;
		} finally {
			foreach ([...$tempFiles, $zipPath] as $path) {
				if (is_file($path)) {
					@unlink($path);
				}
			}
		}

		return [
			'path' => $target,
			'fileName' => self::fileName($clubName),
			'rows' => array_sum(array_column($tableInfo, 'rows')),
			'files' => $fileCount,
			'missingFiles' => $missing,
		];
	}

	/**
	 * Schreibt eine Tabelle als JSON-Lines und liefert Zeilenzahl und Prüfsumme.
	 *
	 * @param list<string> $encrypted
	 * @return array{rows: int, sha256: string}
	 */
	private function writeTable(string $table, array $encrypted, string $path): array {
		$out = fopen($path, 'wb');
		if ($out === false) {
			throw new \RuntimeException('Sicherung: temporäre Datei lässt sich nicht schreiben.');
		}
		$hash = hash_init('sha256');
		$rows = 0;
		try {
			if ($this->tables->exists($table)) {
				foreach ($this->tables->rows($table) as $row) {
					foreach ($encrypted as $column) {
						if (is_string($row[$column] ?? null) && $row[$column] !== '') {
							try {
								$row[$column] = $this->cipher->decrypt($row[$column]);
							} catch (\RuntimeException) {
								throw new UserFacingException($this->l10n->t('Ein verschlüsselter Wert lässt sich nicht lesen (Tabelle %1$s, Datensatz %2$s). Die Sicherung wurde nicht erstellt.', [$table, (string)($row[BackupSchema::PK] ?? '?')]));
							}
						}
					}
					$line = json_encode($row, self::jsonFlags()) . "\n";
					hash_update($hash, $line);
					fwrite($out, $line);
					$rows++;
				}
			}
		} finally {
			fclose($out);
		}
		return ['rows' => $rows, 'sha256' => hash_final($hash)];
	}

	/**
	 * Schreibt das bisher Gesammelte auf die Platte. ZipArchive hält
	 * hinzugefügte Inhalte bis zum Schließen im Speicher; bei hunderten
	 * Belegen liefe der sonst voll.
	 */
	private function flush(\ZipArchive $zip, string $zipPath): void {
		if ($zip->close() !== true || $zip->open($zipPath) !== true) {
			throw new \RuntimeException('Sicherung: ZIP ließ sich nicht schreiben.');
		}
	}

	private static function jsonFlags(): int {
		return JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
	}

	private static function fileName(string $clubName): string {
		$club = SafeFileName::of($clubName, 60);
		$club = trim((string)preg_replace('/-{2,}/', '-', (string)preg_replace('/[^\p{L}\p{N}.-]+/u', '-', $club)), '-._');
		return 'vereinsbuchhaltung-' . ($club !== '' ? $club . '-' : '') . date('Y-m-d') . '.vbhbackup';
	}
}
