<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Budget je Kostenstelle (Issue #335).
 *
 * Neue Tabelle `vbh_cc_budgets`: je Kostenstelle und Geschäftsjahr ein
 * Planwert für Einnahmen und einer für Ausgaben. Der Finanzplan je Konto
 * (`vbh_budgets`) bleibt davon unberührt.
 */
class Version000182Date20261112130000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('vbh_cc_budgets')) {
			return null;
		}
		$table = $schema->createTable('vbh_cc_budgets');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('cost_center_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('period_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('income_cents', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
		$table->addColumn('expense_cents', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['cost_center_id', 'period_id'], 'vbh_ccb_cc_period');
		$table->addIndex(['period_id'], 'vbh_ccb_period');
		return $schema;
	}
}
