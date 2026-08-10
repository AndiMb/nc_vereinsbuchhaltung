<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Mitgliedsbeiträge mit Zahlungsfrequenz (optionales Zusatzmodul, siehe
 * Version000124 für die Begründung der Zahler-Modellierung). mandate_id ist
 * nullable: ein Beitrag kann rein informativ offene Posten erzeugen (z. B.
 * Überweisung erwartet), ohne je per SEPA eingezogen zu werden.
 */
class Version000125Date20260810010000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_membership_fees')) {
			$table = $schema->createTable('vbh_membership_fees');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('member_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('member_label', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('amount_cents', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('frequency', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'monthly']);
			$table->addColumn('start_date', Types::STRING, ['notnull' => true, 'length' => 10]);
			$table->addColumn('next_due_date', Types::STRING, ['notnull' => true, 'length' => 10]);
			$table->addColumn('account_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$table->addColumn('mandate_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$table->addColumn('active', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
			$table->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['member_uid'], 'vbh_fee_uid');
			$table->addIndex(['active', 'next_due_date'], 'vbh_fee_due');
		}

		return $schema;
	}
}
