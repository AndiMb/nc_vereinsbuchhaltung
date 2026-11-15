<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Backup;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Die App-Einstellungen, die in eine Sicherung gehören (Issue #111): alles
 * außer den instanzgebundenen und Nextclouds eigenen Werten
 * ({@see BackupSchema::isBackedUpConfigKey()}).
 */
class BackupConfigStore {

	public function __construct(
		private IAppConfig $config,
	) {
	}

	/**
	 * @return array<string, string> Schlüssel → Wert, nach Schlüssel sortiert
	 */
	public function all(): array {
		$values = [];
		foreach ($this->config->getKeys(Application::APP_ID) as $key) {
			if (!BackupSchema::isBackedUpConfigKey($key)) {
				continue;
			}
			try {
				$values[$key] = $this->config->getValueString(Application::APP_ID, $key);
			} catch (\Throwable) {
				// Ein Wert anderen Typs stammt nicht von dieser App – sie schreibt nur Zeichenketten.
				continue;
			}
		}
		ksort($values);
		return $values;
	}

	/** Entfernt alle gesicherten Einstellungen; instanzgebundene bleiben stehen. */
	public function clear(): void {
		foreach ($this->config->getKeys(Application::APP_ID) as $key) {
			if (BackupSchema::isBackedUpConfigKey($key)) {
				$this->config->deleteKey(Application::APP_ID, $key);
			}
		}
	}

	/**
	 * Ersetzt die gesicherten Einstellungen durch die übergebenen. Schlüssel,
	 * die nicht in eine Sicherung gehören, werden übergangen – auch wenn eine
	 * (veränderte) Sicherungsdatei sie nennt.
	 *
	 * @param array<array-key, mixed> $values
	 */
	public function replace(array $values): void {
		$this->clear();
		foreach ($values as $key => $value) {
			if (!is_string($key) || !is_string($value) || preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $key) !== 1 || !BackupSchema::isBackedUpConfigKey($key)) {
				continue;
			}
			$this->config->setValueString(Application::APP_ID, $key, $value);
		}
	}
}
