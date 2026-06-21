<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initiales Schema der Vereinsbuchhaltung.
 */
class Version000100Date20260621000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// --- Kontenrahmen ----------------------------------------------------
		if (!$schema->hasTable('vbh_accounts')) {
			$table = $schema->createTable('vbh_accounts');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('number', Types::STRING, ['notnull' => true, 'length' => 20]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			// asset | liability | equity | income | expense
			$table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 16]);
			// freie Kategorie/Gruppe für Auswertungen
			$table->addColumn('category', Types::STRING, ['notnull' => false, 'length' => 255]);
			// kennzeichnet das/die Geldkonto/-konten (Bankkonto)
			$table->addColumn('is_bank', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('active', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id', 'number'], 'vbh_acc_user_number');
			$table->addIndex(['user_id', 'type'], 'vbh_acc_user_type');
		}

		// --- Importe ---------------------------------------------------------
		if (!$schema->hasTable('vbh_imports')) {
			$table = $schema->createTable('vbh_imports');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('filename', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('rows_total', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('rows_new', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('rows_duplicate', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'vbh_imp_user');
		}

		// --- Bankbuchungen ---------------------------------------------------
		if (!$schema->hasTable('vbh_bank_tx')) {
			$table = $schema->createTable('vbh_bank_tx');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('import_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$table->addColumn('booking_date', Types::DATE, ['notnull' => true]);
			$table->addColumn('value_date', Types::DATE, ['notnull' => false]);
			// Betrag in Cent (Integer), positiv = Eingang, negativ = Ausgang
			$table->addColumn('amount_cents', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('currency', Types::STRING, ['notnull' => true, 'length' => 3, 'default' => 'EUR']);
			$table->addColumn('booking_text', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('purpose', Types::TEXT, ['notnull' => false]);
			$table->addColumn('counterparty', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('counterparty_iban', Types::STRING, ['notnull' => false, 'length' => 40]);
			$table->addColumn('counterparty_bic', Types::STRING, ['notnull' => false, 'length' => 16]);
			$table->addColumn('own_account', Types::STRING, ['notnull' => false, 'length' => 40]);
			// Dedup-Hash (sha256 hex)
			$table->addColumn('hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			// unassigned | assigned
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'unassigned']);
			// zugeordnetes Gegenkonto (Kategorie)
			$table->addColumn('contra_account_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$table->addColumn('journal_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id', 'hash'], 'vbh_tx_user_hash');
			$table->addIndex(['user_id', 'status'], 'vbh_tx_user_status');
			$table->addIndex(['user_id', 'booking_date'], 'vbh_tx_user_date');
		}

		// --- Journal (Buchungssatz-Kopf) ------------------------------------
		if (!$schema->hasTable('vbh_journal')) {
			$table = $schema->createTable('vbh_journal');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('date', Types::DATE, ['notnull' => true]);
			$table->addColumn('description', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('document_ref', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('bank_tx_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id', 'date'], 'vbh_jrn_user_date');
		}

		// --- Journal-Zeilen (Soll/Haben) ------------------------------------
		if (!$schema->hasTable('vbh_journal_line')) {
			$table = $schema->createTable('vbh_journal_line');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('journal_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('account_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			// Soll/Haben in Cent, jeweils >= 0
			$table->addColumn('debit_cents', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$table->addColumn('credit_cents', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['journal_id'], 'vbh_jl_journal');
			$table->addIndex(['account_id'], 'vbh_jl_account');
		}

		// --- Auto-Zuordnungsregeln ------------------------------------------
		if (!$schema->hasTable('vbh_rules')) {
			$table = $schema->createTable('vbh_rules');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			// counterparty | purpose | iban
			$table->addColumn('match_field', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('match_value', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('contra_account_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('priority', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'vbh_rule_user');
		}

		return $schema;
	}
}
