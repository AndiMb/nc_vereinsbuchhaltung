<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Feste Anteile an Importregeln (Issue #163).
 *
 * Eine Regel kann einen Umsatz auf mehrere Konten aufteilen: feste Beträge
 * in der Reihenfolge `sort_order`, der Rest geht auf das Gegenkonto der
 * Regel (`vbh_rules.contra_account_id`). Regeln ohne Anteile verhalten sich
 * wie zuvor. `cost_center_id` zeigt wie bei der Regel auf `vbh_booking_cc`.
 */
class Version000184Date20261113090000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('vbh_rule_parts')) {
			return null;
		}
		$table = $schema->createTable('vbh_rule_parts');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('rule_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('account_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('amount_cents', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('cost_center_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
		$table->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['rule_id'], 'vbh_rpart_rule');
		$table->addIndex(['user_id'], 'vbh_rpart_user');
		return $schema;
	}
}
