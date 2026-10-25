<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Rechnungsmodul Stufe 3 (Epic #168): Positionsvorlagen, wiederkehrende
 * Rechnungen (Pläne) und Mahnungen zu Rechnungen.
 */
class Version000166Date20261025140000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_invoice_templates')) {
			$t = $schema->createTable('vbh_invoice_templates');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('description', Types::TEXT, ['notnull' => false]);
			$t->addColumn('unit_code', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'C62']);
			$t->addColumn('unit_price_cents', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
			$t->addColumn('tax_category', Types::STRING, ['notnull' => true, 'length' => 4, 'default' => 'S']);
			$t->addColumn('tax_rate_bp', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('account_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['name'], 'vbh_invtpl_name');
		}

		if (!$schema->hasTable('vbh_invoice_plans')) {
			$t = $schema->createTable('vbh_invoice_plans');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('debtor_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('interval_months', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$t->addColumn('start_date', Types::STRING, ['notnull' => true, 'length' => 10]);
			$t->addColumn('end_date', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('next_run', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('period_mode', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'interval']);
			$t->addColumn('lines', Types::TEXT, ['notnull' => true]);
			$t->addColumn('note_head', Types::TEXT, ['notnull' => false]);
			$t->addColumn('note_foot', Types::TEXT, ['notnull' => false]);
			$t->addColumn('payment_terms', Types::TEXT, ['notnull' => false]);
			$t->addColumn('due_days', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('buyer_reference', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('order_reference', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('auto_issue', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('auto_send', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->addColumn('run_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('last_run_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('last_invoice_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('last_error', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['next_run'], 'vbh_invplan_next');
		}

		if (!$schema->hasTable('vbh_invoice_dunnings')) {
			$t = $schema->createTable('vbh_invoice_dunnings');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('invoice_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('level', Types::SMALLINT, ['notnull' => true]);
			$t->addColumn('sent_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('sent_to', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('remaining_cents', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('document_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('auto', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['invoice_id'], 'vbh_invdun_invoice');
		}

		return $schema;
	}
}
