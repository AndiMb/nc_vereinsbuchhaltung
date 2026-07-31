<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Frei definierbare Kostenstellen: Zuordnung Konto -> Kostenstelle.
 *
 * Bisher ergab sich die Kostenstelle entweder aus der zweiten Zahlengruppe der
 * Kontonummer oder jedes Erfolgskonto war seine eigene. Beides sind Annahmen
 * über den Kontenrahmen, die nur für bestimmte Vereine stimmen. Mit einer
 * ausdrücklichen Zuordnung lassen sich beliebige Konten zu einer Kostenstelle
 * zusammenfassen, unabhängig von ihrer Nummer.
 *
 * Die Spalte bleibt leer, solange niemand zuordnet; die beiden bisherigen
 * Modi arbeiten unverändert weiter (siehe ReportService::costCenterMode()).
 */
class Version000123Date20260731000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('vbh_accounts')) {
			$table = $schema->getTable('vbh_accounts');
			if (!$table->hasColumn('cost_center_id')) {
				$table->addColumn('cost_center_id', Types::BIGINT, [
					'notnull' => false,
					'length' => 20,
				]);
				$changed = true;
			}
			if (!$table->hasIndex('vbh_acc_cc')) {
				$table->addIndex(['cost_center_id'], 'vbh_acc_cc');
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
