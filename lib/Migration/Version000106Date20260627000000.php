<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Finanzplan / Budget je Konto und Geschäftsjahr (für den Soll-Ist-Vergleich).
 */
class Version000106Date20260627000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_budgets')) {
			$table = $schema->createTable('vbh_budgets');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('account_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('year', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('amount_cents', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id', 'account_id', 'year'], 'vbh_budget_unique');
		}

		return $schema;
	}
}
