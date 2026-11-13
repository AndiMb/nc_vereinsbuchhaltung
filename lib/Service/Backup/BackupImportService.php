<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Backup;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\ReimbursementEvent;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Service\FieldCipher;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\ReimbursementEventLog;
use OCA\Vereinsbuchhaltung\Service\RevisionService;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\ITempManager;
use OCP\IUserManager;

/**
 * Spielt eine Sicherung ein (Issue #111).
 *
 * Voraussetzungen: dieselbe App-Version wie beim Erstellen (das
 * Tabellenschema muss übereinstimmen) und ein leerer Bestand (die
 * Datensätze behalten ihre IDs, {@see BackupSchema}).
 *
 * Ablauf: entschlüsseln → Prüfsummen aller Tabellen prüfen → Dateien in der
 * app-internen Ablage ablegen → in **einer** Transaktion alle Tabellen leeren
 * und füllen (verschlüsselte Spalten mit dem Schlüssel dieser Instanz neu
 * verschlüsseln, instanzgebundene Verweise zurücksetzen), Stimmigkeit prüfen
 * und die Einstellungen ersetzen.
 *
 * Die Dateien kommen zuerst, weil ihr Ablegen am längsten dauert: Bricht die
 * Anfrage dabei ab (Zeitgrenze, Speicher), ist noch keine Tabelle angefasst
 * und das Ziel weiterhin leer – die verwaisten Dateien räumt der nächste
 * Versuch weg. Scheitert die Transaktion, werden sie sofort wieder entfernt.
 */
class BackupImportService {

	public function __construct(
		private BackupContainer $container,
		private BackupTableStore $tables,
		private BackupConfigStore $config,
		private BackupFileSink $sink,
		private FieldCipher $cipher,
		private TransactionRunner $tx,
		private BackupWipeService $wipe,
		private RevisionService $revision,
		private IAppManager $appManager,
		private ITempManager $tempManager,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IL10N $l10n,
	) {
	}

	/**
	 * Was steckt in der Sicherung, und ließe sie sich hier einspielen?
	 * Verändert nichts.
	 *
	 * @return array{clubName: string, createdAt: string, appVersion: string, installedVersion: string, withFiles: bool, compatible: bool, targetEmpty: bool, rows: int, counts: array<string, int>, files: int, missingFiles: int}
	 * @throws UserFacingException keine Sicherungsdatei, falsches Passwort, beschädigt
	 */
	public function inspect(string $path, #[\SensitiveParameter] string $password): array {
		return $this->withBackup($path, $password, function (\ZipArchive $zip, array $manifest): array {
			$installed = $this->installedVersion();
			$counts = [];
			foreach (BackupSchema::substantiveTables() as $table) {
				$counts[$table] = (int)($manifest['tables'][$table]['rows'] ?? 0);
			}
			return [
				'clubName' => is_string($manifest['clubName'] ?? null) ? $manifest['clubName'] : '',
				'createdAt' => is_string($manifest['createdAt'] ?? null) ? $manifest['createdAt'] : '',
				'appVersion' => $manifest['appVersion'],
				'installedVersion' => $installed,
				'withFiles' => ($manifest['withFiles'] ?? false) === true,
				'compatible' => $manifest['appVersion'] === $installed,
				'targetEmpty' => $this->wipe->isEmpty(),
				'rows' => array_sum(array_map(static fn ($t): int => is_array($t) ? (int)($t['rows'] ?? 0) : 0, $manifest['tables'])),
				'counts' => $counts,
				'files' => (int)($manifest['files']['count'] ?? 0),
				'missingFiles' => count($this->missingFiles($manifest)),
			];
		});
	}

	/**
	 * @param string $importerUid wer einspielt – ist danach in jedem Fall Verwalter
	 * @return array{rows: int, tables: int, files: int, missingFiles: list<array{kind: string, id: int, label: string}>, unknownUsers: list<string>, unknownGroups: list<string>}
	 * @throws UserFacingException wenn die Sicherung nicht eingespielt werden kann; das Ziel ist dann unverändert
	 */
	public function import(string $path, #[\SensitiveParameter] string $password, string $importerUid): array {
		return $this->withBackup($path, $password, function (\ZipArchive $zip, array $manifest) use ($importerUid): array {
			$installed = $this->installedVersion();
			if ($manifest['appVersion'] !== $installed) {
				throw new UserFacingException($this->l10n->t('Diese Sicherung stammt aus Version %1$s der Vereinsbuchhaltung, installiert ist %2$s. Bringen Sie beide Instanzen auf denselben Stand.', [$manifest['appVersion'], $installed]));
			}
			if (!$this->wipe->isEmpty()) {
				throw new UserFacingException($this->l10n->t('Die Buchhaltung ist nicht leer. Eine Sicherung lässt sich nur in einen leeren Bestand einspielen.'));
			}
			$this->verifyTables($zip, $manifest);

			$files = $this->storeFiles($zip);
			try {
				$db = $this->tx->run(function () use ($zip, $manifest, $importerUid): array {
					$db = $this->fillTables($zip, $manifest, $importerUid);
					// Die Einstellungen liegen in derselben Datenbank und rollen mit zurück.
					$settings = json_decode((string)$zip->getFromName('config.json'), true);
					$this->config->replace(is_array($settings) ? $settings : []);
					return $db;
				});
			} catch (\Throwable $e) {
				try {
					$this->sink->clearAppData();
				} catch (\Throwable) {
					// Der eigentliche Fehler zählt; verwaiste Dateien räumt der nächste Versuch weg.
				}
				throw $e;
			}
			$this->revision->bump();

			return [
				'rows' => $db['rows'],
				'tables' => count(BackupSchema::tables()),
				'files' => $files['stored'],
				'missingFiles' => array_merge($this->missingFiles($manifest), $files['failed']),
				'unknownUsers' => $db['unknownUsers'],
				'unknownGroups' => $db['unknownGroups'],
			];
		});
	}

	/**
	 * Entschlüsselt die Sicherung in eine temporäre Datei, öffnet das ZIP
	 * und liest das Inhaltsverzeichnis.
	 *
	 * @template T
	 * @param callable(\ZipArchive, array<string, mixed>): T $fn
	 * @return T
	 */
	private function withBackup(string $path, #[\SensitiveParameter] string $password, callable $fn) {
		if (!class_exists(\ZipArchive::class)) {
			throw new UserFacingException($this->l10n->t('Auf diesem Server fehlt die PHP-Erweiterung zip.'));
		}
		$plain = $this->tempManager->getTemporaryFile('.zip');
		try {
			$this->container->decrypt($path, $plain, $password);
			$zip = new \ZipArchive();
			if ($zip->open($plain, \ZipArchive::RDONLY) !== true) {
				throw $this->foreign();
			}
			try {
				return $fn($zip, $this->manifest($zip));
			} finally {
				$zip->close();
			}
		} finally {
			if (is_file($plain)) {
				@unlink($plain);
			}
		}
	}

	/**
	 * @return array<string, mixed> mit `appVersion` (string) und `tables` (array)
	 */
	private function manifest(\ZipArchive $zip): array {
		$raw = $zip->getFromName('manifest.json');
		$manifest = is_string($raw) ? json_decode($raw, true) : null;
		if (
			!is_array($manifest)
			|| ($manifest['format'] ?? null) !== BackupExportService::FORMAT
			|| ($manifest['app'] ?? null) !== Application::APP_ID
			|| !is_string($manifest['appVersion'] ?? null)
			|| !is_array($manifest['tables'] ?? null)
		) {
			throw $this->foreign();
		}
		return $manifest;
	}

	/**
	 * Jede Tabelle muss in der Sicherung liegen und zu Zeilenzahl und
	 * Prüfsumme des Inhaltsverzeichnisses passen – geprüft, bevor das Ziel
	 * angefasst wird.
	 *
	 * @param array<string, mixed> $manifest
	 */
	private function verifyTables(\ZipArchive $zip, array $manifest): void {
		foreach (array_keys(BackupSchema::tables()) as $table) {
			$expected = $manifest['tables'][$table] ?? null;
			$stream = $zip->getStream('tables/' . $table . '.jsonl');
			if (!is_array($expected) || !is_int($expected['rows'] ?? null) || !is_string($expected['sha256'] ?? null) || $stream === false) {
				if (is_resource($stream)) {
					fclose($stream);
				}
				throw $this->damaged($table);
			}
			$hash = hash_init('sha256');
			$rows = 0;
			while (!feof($stream)) {
				$chunk = fread($stream, 1048576);
				if ($chunk === false || $chunk === '') {
					break;
				}
				hash_update($hash, $chunk);
				$rows += substr_count($chunk, "\n");
			}
			fclose($stream);
			if ($rows !== $expected['rows'] || !hash_equals($expected['sha256'], hash_final($hash))) {
				throw $this->damaged($table);
			}
		}
	}

	/**
	 * Läuft in der Transaktion: leert alle Tabellen und füllt sie aus der Sicherung.
	 *
	 * @param array<string, mixed> $manifest
	 * @return array{rows: int, unknownUsers: list<string>, unknownGroups: list<string>}
	 */
	private function fillTables(\ZipArchive $zip, array $manifest, string $importerUid): array {
		foreach (array_merge(array_keys(BackupSchema::tables()), BackupSchema::excludedTables()) as $table) {
			if ($this->tables->exists($table)) {
				$this->tables->truncate($table);
			}
		}

		$seen = new BackupImportTally();
		$rows = 0;
		foreach (BackupSchema::tables() as $table => $def) {
			$count = $this->tables->insert($table, $this->rows($zip, $table, $def, $importerUid, $seen));
			if ($count !== $manifest['tables'][$table]['rows']) {
				throw $this->damaged($table);
			}
			$rows += $count;
		}

		if ($seen->debit !== $seen->credit) {
			throw $this->inconsistent($this->l10n->t('Soll und Haben der Buchungen'));
		}
		foreach ($seen->events as $events) {
			usort($events, static fn (ReimbursementEvent $a, ReimbursementEvent $b): int => $a->getSeq() <=> $b->getSeq());
			if (!ReimbursementEventLog::verify($events)) {
				throw $this->inconsistent($this->l10n->t('Prüfkette der Auslagenanträge'));
			}
		}

		// Wer einspielt, muss danach an den Bestand herankommen – auch wenn die Sicherung ihn nicht kennt.
		if (!$seen->importerFound) {
			$this->tables->insert('vbh_permissions', [[
				BackupSchema::PK => $this->tables->maxId('vbh_permissions') + 1,
				'principal_type' => 'user',
				'principal_id' => $importerUid,
				'role' => PermissionService::ROLE_ADMIN,
			]]);
		}

		foreach (array_keys(BackupSchema::tables()) as $table) {
			$this->tables->syncSequence($table);
		}

		$unknownUsers = array_values(array_filter(array_keys($seen->users), fn (string $uid): bool => !$this->userManager->userExists($uid)));
		$unknownGroups = array_values(array_filter(array_keys($seen->groups), fn (string $gid): bool => !$this->groupManager->groupExists($gid)));
		sort($unknownUsers);
		sort($unknownGroups);
		return ['rows' => $rows, 'unknownUsers' => $unknownUsers, 'unknownGroups' => $unknownGroups];
	}

	/**
	 * Die Zeilen einer Tabelle, wie sie im Ziel stehen sollen.
	 *
	 * @param array{encrypted: list<string>, replace: array<string, int|null>, replaceIfFile?: array<string, string>} $def
	 * @return \Generator<int, array<string, mixed>>
	 */
	private function rows(\ZipArchive $zip, string $table, array $def, string $importerUid, BackupImportTally $seen): \Generator {
		$stream = $zip->getStream('tables/' . $table . '.jsonl');
		if ($stream === false) {
			throw $this->damaged($table);
		}
		try {
			while (($line = fgets($stream)) !== false) {
				if (trim($line) === '') {
					continue;
				}
				try {
					$row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
				} catch (\JsonException) {
					throw $this->damaged($table);
				}
				if (!is_array($row) || !is_int($row[BackupSchema::PK] ?? null)) {
					throw $this->damaged($table);
				}

				foreach ($def['encrypted'] as $column) {
					if (is_string($row[$column] ?? null) && $row[$column] !== '') {
						$row[$column] = $this->cipher->encrypt($row[$column]);
					}
				}
				foreach ($def['replace'] as $column => $value) {
					if (array_key_exists($column, $row)) {
						$row[$column] = $value;
					}
				}
				foreach ($def['replaceIfFile'] ?? [] as $column => $kind) {
					if (($row[$column] ?? null) !== null) {
						$row[$column] = $zip->locateName('files/' . $kind . '/' . $row[BackupSchema::PK]) !== false ? BackupSchema::APPDATA_FILE_ID : null;
					}
				}

				switch ($table) {
					case 'vbh_journal_line':
						$seen->debit += (int)($row['debit_cents'] ?? 0);
						$seen->credit += (int)($row['credit_cents'] ?? 0);
						break;
					case 'vbh_reimbursement_events':
						$seen->events[(int)($row['reimbursement_id'] ?? 0)][] = ReimbursementEvent::fromRow($row);
						break;
					case 'vbh_permissions':
						$principal = (string)($row['principal_id'] ?? '');
						if (($row['principal_type'] ?? '') === 'user') {
							if ($principal === $importerUid) {
								$row['role'] = PermissionService::ROLE_ADMIN;
								$seen->importerFound = true;
							}
							$seen->users[$principal] = true;
						} else {
							$seen->groups[$principal] = true;
						}
						break;
					case 'vbh_members':
						if (is_string($row['nc_user_id'] ?? null) && $row['nc_user_id'] !== '') {
							$seen->users[$row['nc_user_id']] = true;
						}
						break;
				}

				yield $row;
			}
		} finally {
			fclose($stream);
		}
	}

	/**
	 * Legt die Dateien der Sicherung in der app-internen Ablage ab.
	 *
	 * @return array{stored: int, failed: list<array{kind: string, id: int, label: string}>}
	 */
	private function storeFiles(\ZipArchive $zip): array {
		$this->sink->clearAppData();
		$stored = 0;
		$failed = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if (!is_string($name) || preg_match('#^files/(' . implode('|', BackupSchema::FILE_KINDS) . ')/([1-9][0-9]{0,17})$#', $name, $m) !== 1) {
				continue;
			}
			try {
				$content = $zip->getFromIndex($i);
				if (!is_string($content)) {
					throw new \RuntimeException('nicht lesbar');
				}
				$this->sink->store($m[1], (int)$m[2], $content);
				$stored++;
			} catch (\Throwable) {
				$failed[] = ['kind' => $m[1], 'id' => (int)$m[2], 'label' => ''];
			}
		}
		$logo = $zip->getFromName('files/logo');
		if (is_string($logo) && $logo !== '') {
			try {
				$this->sink->storeLogo($logo);
			} catch (\Throwable) {
				// Ohne Logo geht es auch.
			}
		}
		return ['stored' => $stored, 'failed' => $failed];
	}

	/**
	 * Dateien, die schon beim Erstellen der Sicherung fehlten.
	 *
	 * @param array<string, mixed> $manifest
	 * @return list<array{kind: string, id: int, label: string}> `label` ist der Name, unter dem die Quelle die Datei kannte ('' in Sicherungen ohne diese Angabe)
	 */
	private function missingFiles(array $manifest): array {
		$missing = [];
		foreach (is_array($manifest['missingFiles'] ?? null) ? $manifest['missingFiles'] : [] as $entry) {
			if (is_array($entry) && in_array($entry['kind'] ?? null, BackupSchema::FILE_KINDS, true) && is_int($entry['id'] ?? null)) {
				$label = is_string($entry['label'] ?? null) ? $entry['label'] : '';
				$missing[] = ['kind' => $entry['kind'], 'id' => $entry['id'], 'label' => mb_substr($label, 0, 200)];
			}
		}
		return $missing;
	}

	private function installedVersion(): string {
		return $this->appManager->getAppVersion(Application::APP_ID);
	}

	private function foreign(): UserFacingException {
		return new UserFacingException($this->l10n->t('Das ist keine Sicherungsdatei der Vereinsbuchhaltung.'));
	}

	private function damaged(string $table): UserFacingException {
		return new UserFacingException($this->l10n->t('Die Sicherung ist beschädigt (Tabelle %s).', [$table]));
	}

	private function inconsistent(string $what): UserFacingException {
		return new UserFacingException($this->l10n->t('Die Sicherung ist in sich nicht stimmig (%s).', [$what]));
	}
}
