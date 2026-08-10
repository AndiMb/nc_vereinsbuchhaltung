<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * SEPA-Sammeleinzüge (pain.008-Export) und ihre Zeilen, siehe
 * SepaBatchService. Ergänzt außerdem vbh_open_items um ein optionales
 * mandate_id: nur offene Posten mit gesetztem Mandat sind für den
 * SEPA-Export überhaupt sichtbar (siehe Version000118 – debtor bleibt
 * Freitext für alle anderen offenen Posten unverändert).
 */
class Version000126Date20260810020000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('vbh_open_items') && !$schema->getTable('vbh_open_items')->hasColumn('mandate_id')) {
			$schema->getTable('vbh_open_items')->addColumn('mandate_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$changed = true;
		}

		if (!$schema->hasTable('vbh_sepa_batches')) {
			$table = $schema->createTable('vbh_sepa_batches');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('execution_date', Types::STRING, ['notnull' => true, 'length' => 10]);
			$table->addColumn('message_id', Types::STRING, ['notnull' => true, 'length' => 35]);
			$table->addColumn('creditor_id', Types::STRING, ['notnull' => true, 'length' => 35]);
			$table->addColumn('creditor_name', Types::STRING, ['notnull' => true, 'length' => 255]);
			// Das einziehende Vereinskonto (SEPA-Rolle "Creditor", nicht zu
			// verwechseln mit den Mitgliedern, die SEPA-seitig "Debtor" heißen).
			$table->addColumn('creditor_iban', Types::STRING, ['notnull' => true, 'length' => 34]);
			$table->addColumn('creditor_bic', Types::STRING, ['notnull' => false, 'length' => 11]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['message_id'], 'vbh_sepa_batch_msgid');
			$changed = true;
		}

		if (!$schema->hasTable('vbh_sepa_batch_items')) {
			$table = $schema->createTable('vbh_sepa_batch_items');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('batch_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('open_item_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('mandate_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('amount_cents', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('sequence_type', Types::STRING, ['notnull' => true, 'length' => 8]);
			$table->addColumn('end_to_end_id', Types::STRING, ['notnull' => true, 'length' => 35]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']);
			$table->addColumn('return_reason', Types::STRING, ['notnull' => false, 'length' => 8]);
			$table->addColumn('return_date', Types::STRING, ['notnull' => false, 'length' => 10]);
			$table->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['batch_id'], 'vbh_sepa_bi_batch');
			$table->addIndex(['open_item_id'], 'vbh_sepa_bi_openitem');
			$table->addUniqueIndex(['end_to_end_id'], 'vbh_sepa_bi_e2e');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
