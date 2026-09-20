<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Freigabe & Einreichung des Einzugszyklus (Spec §2.2 „Lastschriftlauf
 * (Debit Batch)"/„Einzugsposten (Debit Item)", §3.5, §8 Compliance-Anhang,
 * Issue #71): ein bewusst neues, additives Tabellenpaar –
 * `vbh_debit_batches`, `vbh_debit_items` – statt einer weiteren Migration
 * des alten `vbh_sepa_batches`/`vbh_sepa_batch_items` (siehe
 * {@see \OCA\Vereinsbuchhaltung\Db\SepaBatch}), das am alten,
 * `vbh_membership_fees`-basierten Einzugszyklus hängt und aus denselben
 * Gründen wie `vbh_mandates` (siehe Version000140) nicht angefasst wird.
 *
 * „Vor der Freigabe existiert kein Lauf-Datensatz – davor ist der Lauf eine
 * Abfrage" ({@see \OCA\Vereinsbuchhaltung\Service\DebitRunQueryService},
 * Issue #70): die Zeile in `vbh_debit_batches` entsteht deshalb erst bei der
 * Freigabe selbst, mit `released_at` bereits gesetzt – anders als bei
 * `vbh_mandates` gibt es hier kein separates `created_at`.
 *
 * `vbh_debit_items` ist der bei der Freigabe eingefrorene Schnappschuss
 * (Betrag/IBAN/BIC/Kontoinhaber/Mandatsreferenz/Unterschriftsdatum) – dieselbe
 * Wahrheit wie die pain.008-Zeile, unabhängig davon, was sich am Mandat
 * *danach* noch ändert (Amendment, Kontoinhaberwechsel, künftige
 * DSGVO-Löschung der Bankdaten laut Spec §3.8). Ohne eigene Kopie wäre die
 * geforderte „byte-identische Nachrenderbarkeit" (§3.5) nicht haltbar, sobald
 * sich das Mandat nach der Freigabe weiterentwickelt. Kein eigener Status:
 * „ableitbar aus Lauf-Status + Rücklastschrift" (§2.2) – Rücklastschriften
 * kommen erst mit #73.
 *
 * Spaltenlängen bewusst identisch zum alten `vbh_sepa_batches`/
 * `vbh_sepa_batch_items`-Paar (Version000126) übernommen: dieselben
 * SEPA-Feldgrenzen (IBAN 34, BIC 11, Mandatsreferenz/EndToEndId/MsgId je 35).
 */
class Version000143Date20260917000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_debit_batches')) {
			$table = $schema->createTable('vbh_debit_batches');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			// Nur nach hinten verschiebbar (DebitBatchStateMachine::assertCanReschedule()).
			$table->addColumn('due_date', Types::STRING, ['notnull' => true, 'length' => 10]);
			// Werte: freigegeben|eingereicht|verworfen (DebitBatch::STATUS_*) -
			// deutsch, konsistent mit dem Mandate::STATUS_*-Muster (Spec §13.1).
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'freigegeben']);
			$table->addColumn('released_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('released_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('submitted_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('submitted_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			$table->addColumn('discarded_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('discarded_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			$table->addColumn('discard_reason', Types::TEXT, ['notnull' => false]);
			// Bei Freigabe eingefroren, nie mehr neu erzeugt - Grundlage der
			// byte-identischen Nachrenderbarkeit (Spec §3.5).
			$table->addColumn('msg_id', Types::STRING, ['notnull' => true, 'length' => 35]);
			$table->addColumn('creation_date_time', Types::STRING, ['notnull' => true, 'length' => 32]);
			// Gläubigerangaben zum Zeitpunkt der Freigabe eingefroren - dieselbe
			// Begründung wie beim Einzugsposten-Schnappschuss: eine spätere
			// Änderung an den App-Einstellungen (Vereinsname, Gläubiger-ID, IBAN
			// des einziehenden Kontos) darf eine bereits freigegebene Datei nicht
			// nachträglich verändern.
			$table->addColumn('creditor_id', Types::STRING, ['notnull' => true, 'length' => 35]);
			$table->addColumn('creditor_name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('creditor_iban', Types::STRING, ['notnull' => true, 'length' => 34]);
			$table->addColumn('creditor_bic', Types::STRING, ['notnull' => false, 'length' => 11]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['due_date'], 'vbh_debitbatch_due');
			$table->addIndex(['status'], 'vbh_debitbatch_status');
		}

		if (!$schema->hasTable('vbh_debit_items')) {
			$table = $schema->createTable('vbh_debit_items');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('batch_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			// Die Forderung (Claim, vbh_open_items) hinter diesem Posten - "Posten
			// und XML-Zeile sind dieselbe Wahrheit" (Spec §2.2).
			$table->addColumn('open_item_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('mandate_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('amount_cents', Types::INTEGER, ['notnull' => true]);
			// Schnappschuss der Mandatsdaten zum Freigabezeitpunkt (siehe Klassendoc).
			$table->addColumn('iban', Types::STRING, ['notnull' => true, 'length' => 34]);
			$table->addColumn('bic', Types::STRING, ['notnull' => false, 'length' => 11]);
			$table->addColumn('account_holder', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('mandate_reference', Types::STRING, ['notnull' => true, 'length' => 35]);
			$table->addColumn('signed_date', Types::STRING, ['notnull' => true, 'length' => 10]);
			// Immer RCUR (Mandate::SEQUENCE_TYPE, Compliance-Anhang Spec §8) - eigene
			// Spalte trotzdem, damit die XML-Zeile ohne Zusatzwissen nachrenderbar
			// bleibt, falls die Konstante sich je aendern sollte.
			$table->addColumn('sequence_type', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'RCUR']);
			$table->addColumn('end_to_end_id', Types::STRING, ['notnull' => true, 'length' => 35]);
			$table->addColumn('remittance_info', Types::STRING, ['notnull' => true, 'length' => 140]);
			// AmdmntInd/OrgnlDbtrAcct=SMNDA bei Kontowechsel (Compliance-Anhang
			// Spec §8: "DK empfiehlt SMNDA fuer jeden Kontowechsel").
			$table->addColumn('amendment_indicator', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('original_debtor_account', Types::STRING, ['notnull' => false, 'length' => 8]);
			$table->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['batch_id'], 'vbh_debititem_batch');
			$table->addIndex(['open_item_id'], 'vbh_debititem_openitem');
			$table->addIndex(['mandate_id'], 'vbh_debititem_mandate');
			// EndToEndIds werden nie wiederverwendet (Spec §3.5) - der Unique-Index
			// macht eine versehentliche Wiederverwendung sofort sichtbar, statt sie
			// erst bei der Bank auffallen zu lassen.
			$table->addUniqueIndex(['end_to_end_id'], 'vbh_debititem_e2e');
		}

		return $schema;
	}
}
