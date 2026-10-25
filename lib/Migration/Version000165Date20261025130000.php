<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Rechnungsmodul (Epic #168): Zahlungen zu Rechnungen.
 *
 * Jede Zahlung ist eine Zeile mit Betrag, verbuchtem Buchungssatz und der
 * Aufteilung auf die Erlöskonten (`parts`, JSON `{Konto-ID: Cent}`). Nur so
 * lässt sich eine Teilzahlung exakt zurücknehmen, wenn die Zuordnung des
 * Bankumsatzes gelöst oder der Buchungssatz gelöscht wird, und nur so verteilt
 * die nächste Teilzahlung den Rest richtig auf die Konten.
 */
class Version000165Date20261025130000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_invoice_payments')) {
			$t = $schema->createTable('vbh_invoice_payments');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('invoice_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('open_item_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('bank_tx_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('journal_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('amount_cents', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('parts', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['invoice_id'], 'vbh_invpay_invoice');
			$t->addIndex(['journal_id'], 'vbh_invpay_journal');
			$t->addIndex(['bank_tx_id'], 'vbh_invpay_banktx');
		}

		return $schema;
	}
}
