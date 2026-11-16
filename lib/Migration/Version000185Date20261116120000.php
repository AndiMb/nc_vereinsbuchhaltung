<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Entwürfe von Auslagenanträgen (Issue #356).
 *
 * Bewusst eigene Tabellen statt eines Status `draft` in `vbh_reimbursements`:
 * ein Entwurf ist die private Vorarbeit eines Mitglieds. Er hat noch keine
 * Nummer, keine Erklärung, keine Freigaberunde und darf weder in der
 * Freigabeliste noch in Zählern, der Dublettenprüfung oder dem Wächter-Ordner
 * auftauchen. Alle Felder sind optional; Pflichtfelder prüft erst das
 * Einreichen, das aus dem Entwurf einen gewöhnlichen Antrag macht.
 *
 * `iban` liegt wie beim Antrag verschlüsselt (ReimbursementDraftMapper).
 * Die Belegdateien liegen in der app-internen Ablage, nie im Wächter-Ordner.
 */
class Version000185Date20261116120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('vbh_reimb_drafts')) {
			$table = $schema->createTable('vbh_reimb_drafts');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('member_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('amount_cents', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$table->addColumn('expense_date', Types::STRING, ['notnull' => false, 'length' => 10]);
			$table->addColumn('purpose', Types::STRING, ['notnull' => false, 'length' => 500]);
			$table->addColumn('invoice_number', Types::STRING, ['notnull' => false, 'length' => 100]);
			$table->addColumn('supplier', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('iban', Types::TEXT, ['notnull' => false]);
			$table->addColumn('account_holder', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 19]);
			$table->addColumn('updated_at', Types::STRING, ['notnull' => true, 'length' => 19]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['member_id'], 'vbh_rdraft_member');
			$changed = true;
		}

		if (!$schema->hasTable('vbh_reimb_draft_files')) {
			$table = $schema->createTable('vbh_reimb_draft_files');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('draft_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('file_name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('mime_type', Types::STRING, ['notnull' => true, 'length' => 127]);
			$table->addColumn('file_size', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('uploaded_at', Types::STRING, ['notnull' => true, 'length' => 19]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['draft_id'], 'vbh_rdfile_draft');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
