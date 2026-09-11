<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Geschäftsjahre als eigene Tabelle – Schritt 3 von 3: aufräumen (Issue #8).
 *
 * Entfernt die alten Jahresspalten und die Tabelle der Festschreibungen. Beide
 * sind seit Version000133 in vbh_periods abgebildet.
 *
 * Warum sie weg müssen und nicht als Reserve stehen bleiben: zwei Wahrheiten
 * für dieselbe Frage laufen früher oder später auseinander. Genau dagegen gab
 * es bisher Journal::setDateWithYear() – einen einzigen erlaubten Weg, Datum
 * und Jahr gemeinsam zu setzen, damit die redundante Spalte nie vom Datum
 * abweicht. Diese Vorsichtsmaßnahme entfällt mit der Spalte.
 *
 * Auf SQLite baut Doctrine die Tabellen zum Entfernen einer Spalte neu auf
 * (Kopieren, Umbenennen). Bei großen Beständen dauert dieser Schritt daher
 * länger als die beiden davor.
 */
class Version000135Date20260908020000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		foreach (['vbh_journal', 'vbh_budgets', 'vbh_budget_snapshots'] as $name) {
			if (!$schema->hasTable($name)) {
				continue;
			}
			$table = $schema->getTable($name);
			if ($table->hasColumn('year')) {
				$table->dropColumn('year');
			}
		}

		if ($schema->hasTable('vbh_year_close')) {
			$schema->dropTable('vbh_year_close');
		}

		return $schema;
	}
}
