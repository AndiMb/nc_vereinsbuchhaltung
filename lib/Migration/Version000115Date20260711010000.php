<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Änderungsprotokoll (Audit-Log): wer hat wann was geändert. Wird beim
 * Zurücksetzen aller Daten bewusst NICHT geleert – gerade das Zurücksetzen
 * selbst soll nachvollziehbar bleiben.
 */
class Version000115Date20260711010000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_audit_log')) {
			$table = $schema->createTable('vbh_audit_log');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('ts', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('object_type', Types::STRING, ['notnull' => false, 'length' => 32]);
			$table->addColumn('object_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$table->addColumn('details', Types::TEXT, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['ts'], 'vbh_audit_ts');
		}

		return $schema;
	}
}
