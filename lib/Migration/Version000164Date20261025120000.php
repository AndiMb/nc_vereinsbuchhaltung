<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Rechnungsmodul (Epic #168, Design: docs/rechnungsmodul-design.md).
 *
 * Neue Tabellen: Debitoren, Rechnungen, Rechnungspositionen, erzeugte
 * Dokumente, Nummernkreise. Dazu additiv an `vbh_open_items` (geteilte Tabelle
 * der Kern-Buchhaltung, nur additive Migrationen): `invoice_id`, `reference`
 * (Rechnungsnummer als Schlüssel für den Bankabgleich) und `settled_cents`
 * (bereits ausgeglichener Betrag, Teilzahlungen).
 *
 * Beträge stehen in Cent, Mengen in Zehntausendstel (`quantity_e4`), Rabatt
 * und Steuersatz in Basispunkten (`discount_bp`, `tax_rate_bp`: 1900 = 19 %).
 * Ganzzahlen statt Kommazahlen, damit keine Rundungsfehler in die Summen
 * wandern. Die Steuerspalten gibt es von Anfang an, auch wenn Stufe 1 nur den
 * Kleinunternehmer-Modus rechnet (Stufe 2 braucht dann keine Migration).
 *
 * Keine `user_id`: gemeinsamer Bestand des Vereins wie bei den übrigen
 * Tabellen. Idempotent: Tabellen und Spalten werden nur angelegt, wenn sie
 * fehlen.
 */
class Version000164Date20261025120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_debtors')) {
			$t = $schema->createTable('vbh_debtors');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'organisation']);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('name2', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('street', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('postal_code', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('city', Types::STRING, ['notnull' => false, 'length' => 128]);
			$t->addColumn('country', Types::STRING, ['notnull' => true, 'length' => 2, 'default' => 'DE']);
			$t->addColumn('email', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('vat_id', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('leitweg_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('customer_no', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('member_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('note', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('redacted_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['name'], 'vbh_debtor_name');
			$t->addIndex(['member_id'], 'vbh_debtor_member');
		}

		if (!$schema->hasTable('vbh_number_ranges')) {
			$t = $schema->createTable('vbh_number_ranges');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('prefix_pattern', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('padding', Types::INTEGER, ['notnull' => true, 'default' => 4]);
			$t->addColumn('reset_mode', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'yearly']);
			$t->addColumn('next_no', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$t->addColumn('counter_key', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('last_issued_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['kind'], 'vbh_numrange_kind');
		}

		if (!$schema->hasTable('vbh_invoices')) {
			$t = $schema->createTable('vbh_invoices');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'rechnung']);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'draft']);
			$t->addColumn('number', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('number_range_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('issue_date', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('delivery_date', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('period_start', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('period_end', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('due_date', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('debtor_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('debtor_snapshot', Types::TEXT, ['notnull' => false]);
			$t->addColumn('tax_mode', Types::STRING, ['notnull' => true, 'length' => 24, 'default' => 'kleinunternehmer']);
			$t->addColumn('exemption_text', Types::TEXT, ['notnull' => false]);
			$t->addColumn('buyer_reference', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('order_reference', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('payment_terms', Types::TEXT, ['notnull' => false]);
			$t->addColumn('note_head', Types::TEXT, ['notnull' => false]);
			$t->addColumn('note_foot', Types::TEXT, ['notnull' => false]);
			$t->addColumn('net_cents', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$t->addColumn('tax_cents', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$t->addColumn('gross_cents', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$t->addColumn('currency', Types::STRING, ['notnull' => true, 'length' => 3, 'default' => 'EUR']);
			$t->addColumn('preceding_invoice_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('open_item_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('updated_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('issued_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('issued_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('sent_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('cancelled_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('cancel_reason', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['number'], 'vbh_invoice_number');
			$t->addIndex(['status'], 'vbh_invoice_status');
			$t->addIndex(['debtor_id'], 'vbh_invoice_debtor');
			$t->addIndex(['open_item_id'], 'vbh_invoice_openitem');
			$t->addIndex(['preceding_invoice_id'], 'vbh_invoice_preced');
		}

		if (!$schema->hasTable('vbh_invoice_lines')) {
			$t = $schema->createTable('vbh_invoice_lines');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('invoice_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('description', Types::TEXT, ['notnull' => false]);
			$t->addColumn('quantity_e4', Types::BIGINT, ['notnull' => true, 'default' => 10000, 'length' => 20]);
			$t->addColumn('unit_code', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'C62']);
			$t->addColumn('unit_price_cents', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$t->addColumn('discount_bp', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('tax_category', Types::STRING, ['notnull' => true, 'length' => 2, 'default' => 'E']);
			$t->addColumn('tax_rate_bp', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('tax_exemption_text', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('line_net_cents', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$t->addColumn('account_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('cost_center_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('delivery_date', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['invoice_id'], 'vbh_invline_invoice');
		}

		if (!$schema->hasTable('vbh_invoice_documents')) {
			$t = $schema->createTable('vbh_invoice_documents');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('invoice_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 24]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('file_name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('sha256', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('profile', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('generated_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['invoice_id'], 'vbh_invdoc_invoice');
		}

		if ($schema->hasTable('vbh_open_items')) {
			$t = $schema->getTable('vbh_open_items');
			if (!$t->hasColumn('invoice_id')) {
				$t->addColumn('invoice_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
				$t->addIndex(['invoice_id'], 'vbh_openitem_invoice');
			}
			if (!$t->hasColumn('reference')) {
				$t->addColumn('reference', Types::STRING, ['notnull' => false, 'length' => 64]);
				$t->addIndex(['reference'], 'vbh_openitem_ref');
			}
			if (!$t->hasColumn('settled_cents')) {
				$t->addColumn('settled_cents', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			}
		}

		return $schema;
	}
}
