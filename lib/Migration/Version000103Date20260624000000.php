<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Konten-Hierarchie: parent_id für Unterkonten.
 */
class Version000103Date20260624000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('vbh_accounts');
		if (!$table->hasColumn('parent_id')) {
			$table->addColumn('parent_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
		}
		if (!$table->hasIndex('vbh_acc_parent')) {
			$table->addIndex(['user_id', 'parent_id'], 'vbh_acc_parent');
		}

		return $schema;
	}
}
