<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Eröffnungssaldo je Konto (für korrekte Kontostände der Geldkonten).
 */
class Version000101Date20260622000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('vbh_accounts');
		if (!$table->hasColumn('opening_balance_cents')) {
			$table->addColumn('opening_balance_cents', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
		}
		if (!$table->hasColumn('opening_date')) {
			$table->addColumn('opening_date', Types::DATE, ['notnull' => false]);
		}

		return $schema;
	}
}
