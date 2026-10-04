<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Bankabgleich (Issue #105, Spec §2.2 „Zuordnungs-Vorschlag"/§3.6): ein
 * abgelehnter Zahlungseingangs-Vorschlag („diese Gutschrift passt auf
 * Forderung X") muss abgelehnt BLEIBEN. Die Vorschläge selbst sind eine reine
 * Berechnung ({@see \OCA\Vereinsbuchhaltung\Service\Sepa\IncomingPaymentMatchingService}),
 * ohne Gedächtnis würde jeder Seitenaufruf denselben Vorschlag erneut
 * präsentieren und das „Ablehnen" wäre wirkungslos.
 *
 * Eine Zeile je abgelehntem Paar (Bankumsatz, Forderung). Bewusst keine
 * Fremdschlüssel: die Tabellen der App tragen sie nirgends (Löschen läuft
 * über die Dienste, siehe ResetService) – und ein vergessener Eintrag zu
 * einem längst gelöschten Umsatz ist harmlos, er blendet nie etwas aus, das
 * es noch gibt. Keine personenbezogenen Daten außer der uid der urteilenden
 * Person (wie `decided_by` an den SEPA-Detail-Zeilen).
 *
 * Versionsnummer 000153: dem Ticket #105 vorab zugewiesen (Welle 3, parallele
 * Agenten – #117 → 154, #118 → 155, #120 → 156).
 */
class Version000153Date20261004120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('vbh_incoming_pay_rejects')) {
			return null;
		}

		$table = $schema->createTable('vbh_incoming_pay_rejects');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('bank_tx_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('open_item_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('rejected_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('rejected_at', Types::STRING, ['notnull' => true, 'length' => 32]);
		$table->setPrimaryKey(['id']);
		// Ein Paar wird höchstens einmal abgelehnt; die Abfrage „was ist zu diesem Umsatz abgelehnt" nutzt denselben Index.
		$table->addUniqueIndex(['bank_tx_id', 'open_item_id'], 'vbh_incpayrej_pair');

		return $schema;
	}
}
