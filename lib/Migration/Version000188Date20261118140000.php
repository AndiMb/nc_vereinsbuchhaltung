<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Zuwendungsbestätigungen über Geldspenden (Issue #10, Teil B).
 *
 * `vbh_donations` ordnet einer Buchung auf einem Spendenkonto den Spender zu:
 * ein Mitglied (`member_id`) oder einen Debitor als externen Spender
 * (`debtor_id`), jeweils 0 für „nicht gesetzt“. Betrag und Datum der Spende
 * kommen weiter aus der Buchung selbst; je Buchung höchstens ein Spender.
 * `waiver` kennzeichnet den Verzicht auf Erstattung von Aufwendungen
 * (Aufwandsspende).
 *
 * `vbh_donation_receipts` bekommt den Debitor als zweite Art Zuwendender und
 * die Art der Bestätigung (`sammel` oder `einzel`).
 */
class Version000188Date20261118140000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('vbh_donations')) {
			$t = $schema->createTable('vbh_donations');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('journal_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('member_id', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
			$t->addColumn('debtor_id', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
			$t->addColumn('waiver', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('updated_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['journal_id'], 'vbh_donations_journal');
			$t->addIndex(['member_id'], 'vbh_donations_member');
			$t->addIndex(['debtor_id'], 'vbh_donations_debtor');
			$changed = true;
		}

		if ($schema->hasTable('vbh_donation_receipts')) {
			$t = $schema->getTable('vbh_donation_receipts');
			if (!$t->hasColumn('debtor_id')) {
				$t->addColumn('debtor_id', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
				$t->addIndex(['debtor_id'], 'vbh_donrcpt_debtor');
				$changed = true;
			}
			if (!$t->hasColumn('receipt_type')) {
				$t->addColumn('receipt_type', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'sammel']);
				$changed = true;
			}
		}
		return $changed ? $schema : null;
	}
}
