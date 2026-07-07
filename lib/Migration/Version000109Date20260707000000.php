<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Finanzplan-Stände (Snapshots): ein benannter, datierter Stand friert die
 * Planwerte eines Geschäftsjahres ein (z. B. „Beschluss Mitgliederversammlung").
 * Die einzelnen Positionen werden mit Kontonummer/-name/-typ eingefroren, damit
 * ein Stand auch nach späteren Kontenänderungen unverändert lesbar bleibt.
 */
class Version000109Date20260707000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_budget_snapshots')) {
			$table = $schema->createTable('vbh_budget_snapshots');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('year', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id', 'year'], 'vbh_snap_user_year');
		}

		if (!$schema->hasTable('vbh_budget_snap_items')) {
			$table = $schema->createTable('vbh_budget_snap_items');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('snapshot_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('account_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('account_number', Types::STRING, ['notnull' => false, 'length' => 32]);
			$table->addColumn('account_name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('account_type', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('amount_cents', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['snapshot_id'], 'vbh_snapitem_snap');
		}

		return $schema;
	}
}
