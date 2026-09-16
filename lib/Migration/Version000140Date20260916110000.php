<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Mandats-Lifecycle-Kern (Spec §2.2 „Mandat (Mandate)"/§3.2, Issue #66): ein
 * bewusst neues, additives Tabellenpaar – `vbh_mandates`,
 * `vbh_mandate_amendments`, `vbh_mandate_events` – statt einer weiteren
 * Migration des bestehenden `vbh_sepa_mandates` (siehe
 * {@see \OCA\Vereinsbuchhaltung\Db\SepaMandate}), das Version000137-000139
 * bereits additiv auf `member_id` umgestellt haben (Mitglied-Entity, Issue #65).
 *
 * Grund: `vbh_sepa_mandates` hängt am alten, flachen Einzugszyklus
 * (`SepaBatchService`, `SepaNotificationService`, `MembershipFeeService`) mit
 * eigenem, deutlich einfacherem Zustandsmodell (nur `active`/`revoked`). Der
 * volle Lifecycle aus diesem Ticket (Entwurf/Aktivierung/Sperre/Verfall,
 * Amendments, Historie) auf dieselbe Tabelle zu pfropfen hätte den gesamten
 * bestehenden Einzugszyklus in diesem Ticket mit umbauen müssen – das ist
 * laut Scoping-Issue #63 der separate Einzugszyklus-Ticket (T10). Laut Spec
 * §1.2 dürfen die Alt-Tabellen ohnehin „hart ersetzt" werden (keine
 * Produktivnutzer) – das passiert dann dort in einem Zug, statt hier einen
 * Zwischenstand mit zwei nur teilweise kompatiblen Zustandsmodellen auf einer
 * Tabelle zu erzeugen.
 *
 * `UNIQUE (member_id) WHERE status <> 'erloschen'` aus der Spec ist absichtlich
 * *kein* DB-Constraint: ein partieller/gefilterter Unique-Index lässt sich
 * nicht portabel über MySQL/MariaDB, PostgreSQL und SQLite hinweg mit der
 * Doctrine-Schema-Abstraktion ausdrücken. Durchgesetzt wird die Invariante
 * stattdessen auf Anwendungsebene, siehe
 * {@see \OCA\Vereinsbuchhaltung\Db\MandateMapper::findLiveByMember()}.
 */
class Version000140Date20260916110000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_mandates')) {
			$table = $schema->createTable('vbh_mandates');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('member_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('mandate_reference', Types::STRING, ['notnull' => true, 'length' => 64]);
			// nullable: DSGVO-Löschkonzept (Spec §3.8) räumt iban/bic später leer,
			// das Mandat selbst bleibt als Nachweis stehen.
			$table->addColumn('iban', Types::STRING, ['notnull' => false, 'length' => 34]);
			$table->addColumn('bic', Types::STRING, ['notnull' => false, 'length' => 11]);
			$table->addColumn('account_holder', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('signature_type', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'papier']);
			$table->addColumn('signed_at', Types::STRING, ['notnull' => false, 'length' => 10]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'entwurf']);
			$table->addColumn('activated_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			$table->addColumn('ended_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			$table->addColumn('end_reason', Types::STRING, ['notnull' => false, 'length' => 16]);
			$table->addColumn('suspended_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			$table->addColumn('suspended_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('suspension_origin', Types::STRING, ['notnull' => false, 'length' => 16]);
			$table->addColumn('suspension_note', Types::TEXT, ['notnull' => false]);
			$table->addColumn('last_presented_due_date', Types::STRING, ['notnull' => false, 'length' => 10]);
			$table->addColumn('document_file_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			// Vorgriff auf #73 (Rücklastschrift-Fachlogik): noch keine Tabelle,
			// deshalb keine FK, nur die Spalte fuer den spaeteren Verweis.
			$table->addColumn('returned_debit_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$table->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['mandate_reference'], 'vbh_mandate_ref');
			$table->addIndex(['member_id'], 'vbh_mandate_mid');
			$table->addIndex(['status'], 'vbh_mandate_status');
		}

		if (!$schema->hasTable('vbh_mandate_amendments')) {
			$table = $schema->createTable('vbh_mandate_amendments');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('mandate_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('old_iban', Types::STRING, ['notnull' => false, 'length' => 34]);
			$table->addColumn('old_bic', Types::STRING, ['notnull' => false, 'length' => 11]);
			$table->addColumn('old_account_holder', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'open']);
			// FK auf den transportierenden Einzugsposten (Spec §2.2) - dessen
			// Tabelle (DebitItem/vbh_sepa_batch_items-Nachfolger) kommt erst mit
			// dem Einzugszyklus-Ticket, deshalb vorerst nur die Spalte.
			$table->addColumn('debit_item_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$table->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['mandate_id'], 'vbh_mandate_amd_mid');
		}

		if (!$schema->hasTable('vbh_mandate_events')) {
			$table = $schema->createTable('vbh_mandate_events');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('mandate_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('actor_type', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('actor_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('on_behalf_note', Types::TEXT, ['notnull' => false]);
			$table->addColumn('message', Types::TEXT, ['notnull' => true]);
			$table->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['mandate_id'], 'vbh_mandate_evt_mid');
		}

		return $schema;
	}
}
