<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Zuwendungsbestätigungen über Mitgliedsbeiträge (Issue #10, Teil A).
 *
 * Je Mitglied und Kalenderjahr eine Sammelbestätigung. Die Zeile ist das
 * Doppel nach § 50 Abs. 7 EStDV: Nummer, Betrag, Zeitraum, die bestätigten
 * Zahlungen (`items`, JSON) und das erzeugte PDF (`file_id`, 0 = app-interne
 * Ablage, wie bei Rechnungsdokumenten) samt SHA-256. Eine fehlerhafte
 * Bestätigung wird nicht gelöscht, sondern storniert (`voided_*`).
 */
class Version000187Date20261118090000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('vbh_donation_receipts')) {
			return null;
		}
		$t = $schema->createTable('vbh_donation_receipts');
		$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$t->addColumn('member_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$t->addColumn('receipt_year', Types::INTEGER, ['notnull' => true]);
		$t->addColumn('number', Types::STRING, ['notnull' => true, 'length' => 64]);
		$t->addColumn('amount_cents', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$t->addColumn('period_from', Types::STRING, ['notnull' => true, 'length' => 10]);
		$t->addColumn('period_to', Types::STRING, ['notnull' => true, 'length' => 10]);
		$t->addColumn('items', Types::TEXT, ['notnull' => true]);
		$t->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
		$t->addColumn('file_name', Types::STRING, ['notnull' => true, 'length' => 255]);
		$t->addColumn('sha256', Types::STRING, ['notnull' => true, 'length' => 64]);
		$t->addColumn('issued_at', Types::STRING, ['notnull' => true, 'length' => 32]);
		$t->addColumn('issued_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		$t->addColumn('voided_at', Types::STRING, ['notnull' => false, 'length' => 32]);
		$t->addColumn('voided_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		$t->addColumn('void_reason', Types::STRING, ['notnull' => false, 'length' => 500]);
		$t->setPrimaryKey(['id']);
		$t->addIndex(['member_id', 'receipt_year'], 'vbh_donrcpt_member_year');
		$t->addUniqueIndex(['number'], 'vbh_donrcpt_number');
		return $schema;
	}
}
