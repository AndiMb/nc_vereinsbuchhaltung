<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Zwei Indizes für die Listen, die nur noch eine Periode laden (#255).
 *
 * - vbh_attachments (user_id, journal_id): Belegzähler je Buchung.
 * - vbh_journal_line (journal_id, account_id): Kontoauszug und Saldenvortrag
 *   verbinden Zeilen mit dem Journal und filtern auf Konten.
 *
 * Reine Indizes, keine Datenänderung; ein zweiter Lauf legt nichts mehr an.
 */
class Version000172Date20261102000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		$indexes = [
			'vbh_attachments' => ['vbh_attach_user_journal', ['user_id', 'journal_id']],
			'vbh_journal_line' => ['vbh_jl_journal_account', ['journal_id', 'account_id']],
		];
		foreach ($indexes as $tableName => [$indexName, $columns]) {
			if (!$schema->hasTable($tableName)) {
				continue;
			}
			$table = $schema->getTable($tableName);
			if (!$table->hasIndex($indexName)) {
				$table->addIndex($columns, $indexName);
				$changed = true;
			}
		}
		return $changed ? $schema : null;
	}
}
