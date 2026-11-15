<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Kostenstellen an der Buchungszeile (Issue #7).
 *
 * Zwei neue Tabellen neben den Buchungszeilen; keine bestehende Tabelle und
 * keine bestehende Zeile wird angefasst. `vbh_booking_cc` trägt die
 * Stammdaten, `vbh_line_alloc` verteilt den Betrag einer Buchungszeile auf
 * eine oder mehrere davon. Ohne Einträge in `vbh_line_alloc` verhält sich
 * die App wie zuvor.
 *
 * Nicht zu verwechseln mit `vbh_costcenters`: das sind die
 * Auswertungsgruppen, die ganze Konten zusammenfassen.
 */
class Version000177Date20261111120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_booking_cc')) {
			$table = $schema->createTable('vbh_booking_cc');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('code', Types::STRING, ['notnull' => true, 'length' => 8]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id', 'code'], 'vbh_bcc_user_code');
		}

		if (!$schema->hasTable('vbh_line_alloc')) {
			$table = $schema->createTable('vbh_line_alloc');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('line_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('cost_center_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('amount_cents', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['line_id', 'cost_center_id'], 'vbh_la_line_cc');
			$table->addIndex(['cost_center_id'], 'vbh_la_cc');
		}

		return $schema;
	}
}
