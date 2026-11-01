<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Auslagenerstattung (docs/reimbursement-design.md): Anträge, ihre
 * Append-only-Historie mit Hash-Kette und die zugehörigen Belegdateien.
 * `vbh_open_items` bekommt additiv den Verweis auf den Antrag, aus dem ein
 * Posten vom Typ `reimbursement` entstanden ist.
 */
class Version000168Date20261101000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_reimbursements')) {
			$t = $schema->createTable('vbh_reimbursements');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('member_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('amount_cents', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('expense_date', Types::STRING, ['notnull' => true, 'length' => 10]);
			$t->addColumn('purpose', Types::STRING, ['notnull' => true, 'length' => 500]);
			// Chiffrat (FieldCipher), daher TEXT statt STRING
			$t->addColumn('iban', Types::TEXT, ['notnull' => true]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 24]);
			// N zum Zeitpunkt der Einreichung (Snapshot, Design §2)
			$t->addColumn('required_approvals', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('submitted_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('submitted_at', Types::STRING, ['notnull' => true, 'length' => 19]);
			$t->addColumn('decided_at', Types::STRING, ['notnull' => false, 'length' => 19]);
			$t->addColumn('paid_at', Types::STRING, ['notnull' => false, 'length' => 19]);
			$t->addColumn('rejection_reason', Types::TEXT, ['notnull' => false]);
			$t->addColumn('open_item_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('journal_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['member_id'], 'vbh_reimb_member');
			$t->addIndex(['status'], 'vbh_reimb_status');
		}

		if (!$schema->hasTable('vbh_reimbursement_events')) {
			$t = $schema->createTable('vbh_reimbursement_events');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('reimbursement_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('seq', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('actor_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('actor_role', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 19]);
			$t->addColumn('comment', Types::TEXT, ['notnull' => false]);
			$t->addColumn('data', Types::TEXT, ['notnull' => false]);
			$t->addColumn('prev_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->setPrimaryKey(['id']);
			// Die Folgenummer ist je Antrag lückenlos; die Eindeutigkeit fängt
			// zwei gleichzeitig angehängte Ereignisse ab.
			$t->addUniqueIndex(['reimbursement_id', 'seq'], 'vbh_reimb_ev_seq');
		}

		if (!$schema->hasTable('vbh_reimbursement_files')) {
			$t = $schema->createTable('vbh_reimbursement_files');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('reimbursement_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('file_name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('mime_type', Types::STRING, ['notnull' => true, 'length' => 100]);
			$t->addColumn('file_size', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			// Wächter-Modus: Nextcloud-Dateikennung und Besitzer der Datei
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('file_owner', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('content_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('uploaded_at', Types::STRING, ['notnull' => true, 'length' => 19]);
			$t->addColumn('removed_at', Types::STRING, ['notnull' => false, 'length' => 19]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['reimbursement_id'], 'vbh_reimb_file_reimb');
			$t->addIndex(['file_id'], 'vbh_reimb_file_fid');
		}

		if ($schema->hasTable('vbh_open_items')) {
			$items = $schema->getTable('vbh_open_items');
			if (!$items->hasColumn('reimbursement_id')) {
				$items->addColumn('reimbursement_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			}
		}

		return $schema;
	}
}
