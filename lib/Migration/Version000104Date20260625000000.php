<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Kostenstellen (für die Projekt-/Kostenstellenauswertung).
 */
class Version000104Date20260625000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_costcenters')) {
			$table = $schema->createTable('vbh_costcenters');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('code', Types::STRING, ['notnull' => true, 'length' => 8]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id', 'code'], 'vbh_cc_user_code');
		}

		return $schema;
	}
}
