<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Notiz/Kommentar je Finanzplan-Wert (z. B. Herleitung der Planzahl).
 */
class Version000110Date20260708000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('vbh_budgets')) {
			$table = $schema->getTable('vbh_budgets');
			if (!$table->hasColumn('note')) {
				$table->addColumn('note', Types::STRING, ['notnull' => false, 'length' => 1000, 'default' => '']);
			}
		}

		return $schema;
	}
}
