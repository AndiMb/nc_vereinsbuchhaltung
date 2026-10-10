<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Bankimport-Härtung & Einzugs-/Rücklastschrift-Verbuchung (Spec §2.2
 * „Rücklastschrift"/§3.6/§3.10, §5 Integrations-/Quellenschicht, Issue #72).
 *
 * `vbh_bank_tx_sepa_details`: additive 1:n-Nebentabelle zu `vbh_bank_tx`
 * (**diese Tabelle selbst bleibt unangetastet** – `RowNormalizer::computeHash()`
 * bleibt stabil, siehe dortige Klassendoc). Eine Zeile je `TxDtls` (camt) bzw.
 * je referenztragender Buchung (MT940/CSV, dort 1:1 zur Buchung). Trägt den
 * vollständigen Feldkatalog aus Spec §5 sowie den Stand des Bestätigungs-
 * vorgangs je Detail-Zeile („Sammler: ein Bestätigungsvorgang je Bankumsatz,
 * Einzelurteil je Detail-Zeile").
 *
 * `vbh_returned_debits`: höchstens eine je {@see \OCA\Vereinsbuchhaltung\Db\DebitItem}
 * (Spec §2.2 „Rücklastschrift (Returned Debit)") – der Unique-Index auf
 * `debit_item_id` ist der Posten-Guard aus Spec §3.6/§5 („Dubletten verpuffen
 * still"), durchgesetzt auf Datenbankebene statt nur in der Anwendungsschicht.
 */
class Version000144Date20260917010000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_bank_tx_sepa_details')) {
			$table = $schema->createTable('vbh_bank_tx_sepa_details');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('bank_tx_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			// Reihenfolge innerhalb eines Bankumsatzes (mehrere TxDtls je Ntry bei
			// einer camt-Sammelbuchung) - deterministisch fuer die UI-Liste.
			$table->addColumn('detail_index', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			// Feldkatalog Spec §5-Tabelle.
			$table->addColumn('end_to_end_id', Types::STRING, ['notnull' => false, 'length' => 35]);
			$table->addColumn('mandate_reference', Types::STRING, ['notnull' => false, 'length' => 35]);
			// Nur DK-Kernwerte 901-918 uebersetzt, nie geraten - siehe
			// DkReturnReasonCodes; leer bleibt leer statt eines geratenen Werts.
			$table->addColumn('return_reason_code', Types::STRING, ['notnull' => false, 'length' => 4]);
			$table->addColumn('return_reason_text', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('original_amount_cents', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$table->addColumn('charges_cents', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$table->addColumn('gvc', Types::STRING, ['notnull' => false, 'length' => 16]);
			$table->addColumn('batch_reference', Types::STRING, ['notnull' => false, 'length' => 35]);
			// Anteiliger Betrag dieser Detail-Zeile (bei mehreren TxDtls je Ntry
			// muss die Summe aller Detail-Zeilen den Betrag des Bankumsatzes
			// ergeben; bei MT940/CSV 1:1 identisch zu vbh_bank_tx.amount_cents).
			$table->addColumn('amount_cents', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('is_return', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			// 'strukturiert'|'text_heuristik' - dokumentiert, ob die Erkennung aus
			// strukturierten Feldern kam oder aus dem Text-Heuristik-Fallback fuer
			// referenzlose Formate (Spec §5).
			$table->addColumn('detection_source', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'strukturiert']);
			// 'offen'|'zugeordnet'|'abgelehnt'|'nicht_zuordenbar' - das
			// Einzelurteil je Detail-Zeile (Spec §5 "Sammler").
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'offen']);
			$table->addColumn('debit_item_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			// Fuer den Zuordnungs-Vorschlag bei Zahlungseingaengen (importierte
			// Gutschrift passt auf offene Forderung) ohne Einzugsposten.
			$table->addColumn('open_item_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$table->addColumn('decided_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			$table->addColumn('decided_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['bank_tx_id'], 'vbh_sepadetail_tx');
			$table->addIndex(['debit_item_id'], 'vbh_sepadetail_debititem');
			$table->addIndex(['end_to_end_id'], 'vbh_sepadetail_e2e');
			$table->addIndex(['mandate_reference'], 'vbh_sepadetail_mref');
			$table->addIndex(['status'], 'vbh_sepadetail_status');
		}

		if (!$schema->hasTable('vbh_returned_debits')) {
			$table = $schema->createTable('vbh_returned_debits');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('debit_item_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('bank_tx_sepa_detail_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$table->addColumn('reason_code', Types::STRING, ['notnull' => false, 'length' => 4]);
			// Freitext, Pflicht bei "unbekanntem" Grund (Spec §2.2), sonst optional.
			$table->addColumn('reason_text', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('received_at', Types::STRING, ['notnull' => true, 'length' => 10]);
			// 'import'|'manual' - Rücklastschrift-Quelle ist seit T12/T26 zweiwertig.
			$table->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 10, 'default' => 'import']);
			$table->addColumn('charges_cents', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			// Opt-in Gebühren-Weiterbelastung (Default aus, verwalter-Einstellung) -
			// einfacher Ja/Nein-Schalter, die volle Ursache-Klassifikation folgt #73.
			$table->addColumn('fee_recharge_triggered', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('fee_open_item_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			// Die Buchung, die OAMT zurück aufs Erlöskonto und COAM aufs
			// Rücklastschriftgebühren-Konto verbucht hat (Spec §3.10).
			$table->addColumn('journal_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$table->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->setPrimaryKey(['id']);
			// Posten-Guard (Spec §3.6/§5): "max. 1 Rücklastschrift je Einzugsposten,
			// Dubletten verpuffen still" - auf Datenbankebene erzwungen.
			$table->addUniqueIndex(['debit_item_id'], 'vbh_returneddebit_item');
		}

		return $schema;
	}
}
