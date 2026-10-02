<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Mitglied-Entity (Spec §2.2, docs/beitraege-sepa-modul-spec.md) und Aufgaben-
 * Grundlage: legt `vbh_members` und `vbh_tasks` neu an und ergänzt
 * `vbh_sepa_mandates`/`vbh_membership_fees` um die künftige `member_id`-Spalte
 * – vorerst additiv und nullable, die alten Spalten `member_uid`/
 * `member_label` bleiben unangetastet stehen.
 *
 * Bewusste Dreiteilung über drei Migrationen, im Geiste des Vorgehens bei den
 * Geschäftsjahren (Version000133/134/135) — dort lagen Schema+Datenübernahme
 * zusammen in Schritt 1 und Schritt 2 war für einen späteren Unique-Index
 * reserviert; hier gibt es keinen Unique-Index auf `member_id` umzuziehen,
 * deshalb liegt die Datenübernahme stattdessen ganz in Version000138 und
 * diese Migration hier legt nur an, ohne bestehende Daten anzufassen.
 * Version000139 entfernt danach die alten Spalten `member_uid`/
 * `member_label` – Migrationen laufen sequenziell, ein Zwischenstand mit
 * doppelt gepflegten Spalten wäre hier nur unnötiges Risiko.
 *
 * `member_number` und `nc_user_id` sind laut Spec „unique wenn gesetzt": ein
 * regulärer addUniqueIndex() auf einer nullable Spalte erfüllt das bereits,
 * weil SQL mehrere NULL-Werte in einem Unique-Index nicht als Duplikat
 * behandelt (MySQL/MariaDB, PostgreSQL und SQLite – alle drei von dieser App
 * unterstützten Datenbanken – verhalten sich hier gleich).
 */
class Version000137Date20260916000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_members')) {
			$table = $schema->createTable('vbh_members');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('member_type', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'person']);
			$table->addColumn('first_name', Types::STRING, ['notnull' => false, 'length' => 128]);
			$table->addColumn('last_name', Types::STRING, ['notnull' => false, 'length' => 128]);
			$table->addColumn('organization_name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('email', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('phone', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('street', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('postal_code', Types::STRING, ['notnull' => false, 'length' => 16]);
			$table->addColumn('city', Types::STRING, ['notnull' => false, 'length' => 128]);
			$table->addColumn('country', Types::STRING, ['notnull' => false, 'length' => 2]);
			$table->addColumn('member_number', Types::STRING, ['notnull' => false, 'length' => 32]);
			$table->addColumn('joined_at', Types::STRING, ['notnull' => true, 'length' => 10]);
			$table->addColumn('left_at', Types::STRING, ['notnull' => false, 'length' => 10]);
			$table->addColumn('nc_user_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('internal_note', Types::TEXT, ['notnull' => false]);
			$table->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['member_number'], 'vbh_member_number');
			$table->addUniqueIndex(['nc_user_id'], 'vbh_member_ncuid');
			$table->addIndex(['email'], 'vbh_member_email');
		}

		// Minimale Aufgaben-/Störfall-Grundlage (Spec §7): es gibt noch kein
		// Aufgabenkonzept im Bestand (siehe PR-Beschreibung). Die meisten
		// künftigen Aufgaben sind laut Spec eine abgeleitete Abfrage ohne
		// eigene Tabelle - diese hier bildet nur die zwei ereignisgetriebenen
		// Fälle ab, die dieses Ticket selbst auslöst (Migrationsergebnis,
		// NC-Konto-Löschung), und dient künftigen Tickets als Andockpunkt.
		if (!$schema->hasTable('vbh_tasks')) {
			$table = $schema->createTable('vbh_tasks');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('severity', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('message', Types::TEXT, ['notnull' => true]);
			$table->addColumn('object_type', Types::STRING, ['notnull' => false, 'length' => 32]);
			$table->addColumn('object_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$table->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['created_at'], 'vbh_task_created');
		}

		if ($schema->hasTable('vbh_sepa_mandates') && !$schema->getTable('vbh_sepa_mandates')->hasColumn('member_id')) {
			$table = $schema->getTable('vbh_sepa_mandates');
			$table->addColumn('member_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$table->addIndex(['member_id'], 'vbh_sepa_mand_mid');
		}

		if ($schema->hasTable('vbh_membership_fees') && !$schema->getTable('vbh_membership_fees')->hasColumn('member_id')) {
			$table = $schema->getTable('vbh_membership_fees');
			$table->addColumn('member_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$table->addIndex(['member_id'], 'vbh_fee_mid');
		}

		return $schema;
	}
}
