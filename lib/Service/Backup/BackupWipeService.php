<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Backup;

use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\RevisionService;

/**
 * „Bestand vollständig leeren“ (Issue #111) – die Vorstufe zum Einspielen
 * einer Sicherung in eine Instanz, die schon Daten hat.
 *
 * Anders als „Alle Daten löschen“ ({@see \OCA\Vereinsbuchhaltung\Service\ResetService}),
 * das Mitglieder, Mandate, Beitragsgruppen und Einstellungen stehen lässt,
 * geht hier alles, was eine Sicherung enthält. Stehen bleiben das
 * Änderungsprotokoll, die Rollen und die instanzgebundenen Einstellungen
 * (Ablage, Wächter-Ordner).
 */
class BackupWipeService {

	public function __construct(
		private BackupTableStore $tables,
		private BackupConfigStore $config,
		private BackupFileSink $sink,
		private TransactionRunner $tx,
		private RevisionService $revision,
	) {
	}

	/** Leer heißt: keine Tabelle mit eigenem Inhalt ist gefüllt ({@see BackupSchema::substantiveTables()}). */
	public function isEmpty(): bool {
		return array_sum($this->counts()) === 0;
	}

	/**
	 * @return array<string, int> Zeilenzahl je Inhaltstabelle
	 */
	public function counts(): array {
		$counts = [];
		foreach (BackupSchema::substantiveTables() as $table) {
			$counts[$table] = $this->tables->exists($table) ? $this->tables->count($table) : 0;
		}
		return $counts;
	}

	public function wipe(): void {
		$deleteFiles = $this->sink->prepareWipe();

		$this->tx->run(function (): void {
			foreach (array_merge(array_keys(BackupSchema::tables()), BackupSchema::excludedTables()) as $table) {
				if (!in_array($table, BackupSchema::keptOnWipe(), true) && $this->tables->exists($table)) {
					$this->tables->truncate($table);
				}
			}
		});

		$this->config->clear();
		$deleteFiles();
		$this->revision->bump();
	}
}
