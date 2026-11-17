<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Reisekostenabrechnung als Art einer Auslage (Issue #353).
 *
 * `kind` unterscheidet die gewöhnliche Auslage (`expense`, alle bisherigen
 * Anträge) von der Reisekostenabrechnung (`travel`). Deren Angaben – Ziel,
 * Beginn, Ende und die Positionen – liegen als JSON in `travel_details`; sie
 * werden nie einzeln abgefragt und nach dem Einreichen nicht mehr geändert.
 * Entwürfe bekommen dieselben Spalten.
 */
class Version000186Date20261117090000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		foreach (['vbh_reimbursements', 'vbh_reimb_drafts'] as $name) {
			if (!$schema->hasTable($name)) {
				continue;
			}
			$table = $schema->getTable($name);
			if (!$table->hasColumn('kind')) {
				$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'expense']);
				$changed = true;
			}
			if (!$table->hasColumn('travel_details')) {
				$table->addColumn('travel_details', Types::TEXT, ['notnull' => false]);
				$changed = true;
			}
		}
		return $changed ? $schema : null;
	}
}
