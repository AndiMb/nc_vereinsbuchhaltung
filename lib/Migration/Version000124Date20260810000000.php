<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * SEPA-Lastschriftmandate für Mitgliedsbeiträge (optionales Zusatzmodul).
 *
 * Es gibt bewusst keine eigene Mitglieder-Tabelle (siehe Version000118...):
 * ein Mandat gehört entweder zu einem Nextcloud-Konto dieser Instanz
 * (member_uid) oder zu einem frei benannten Zahler (member_label), z. B. für
 * Verbände, die nur Beitragsanteile von Untergliederungen durchreichen und
 * keine oder nicht ausschließlich Mitglieder mit eigenem Nextcloud-Konto
 * haben. Beide Felder sind nullable; genau eines ist gesetzt (siehe
 * SepaMandateService).
 */
class Version000124Date20260810000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_sepa_mandates')) {
			$table = $schema->createTable('vbh_sepa_mandates');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('member_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('member_label', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('iban', Types::STRING, ['notnull' => true, 'length' => 34]);
			$table->addColumn('bic', Types::STRING, ['notnull' => false, 'length' => 11]);
			$table->addColumn('mandate_reference', Types::STRING, ['notnull' => true, 'length' => 35]);
			$table->addColumn('mandate_type', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'RCUR']);
			$table->addColumn('signed_date', Types::STRING, ['notnull' => true, 'length' => 10]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'active']);
			$table->addColumn('last_used_date', Types::STRING, ['notnull' => false, 'length' => 10]);
			$table->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['mandate_reference'], 'vbh_sepa_mand_ref');
			$table->addIndex(['member_uid'], 'vbh_sepa_mand_uid');
			$table->addIndex(['status'], 'vbh_sepa_mand_status');
		}

		return $schema;
	}
}
