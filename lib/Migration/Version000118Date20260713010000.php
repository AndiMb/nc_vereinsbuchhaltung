<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Offene Posten: schlanke Ad-hoc-Liste unbezahlter Forderungen (z. B.
 * Mitgliedsbeiträge, Rechnungen) mit Fälligkeit. Bewusst KEINE
 * Mitgliederverwaltung – debtor ist Freitext, kein Fremdschlüssel auf eine
 * Mitglieder-Tabelle (die es in dieser App nicht gibt). Kein user_id-Feld:
 * gemeinsamer Bestand wie vbh_year_close/vbh_audit_log.
 */
class Version000118Date20260713010000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_open_items')) {
			$table = $schema->createTable('vbh_open_items');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('debtor', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('description', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('amount_cents', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('due_date', Types::STRING, ['notnull' => false, 'length' => 10]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'open']);
			$table->addColumn('account_id', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('paid_journal_id', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['status'], 'vbh_openitem_status');
			$table->addIndex(['due_date'], 'vbh_openitem_due');
		}

		return $schema;
	}
}
