<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Elektronische Mandatserteilung (Spec §2.2 „Mandat"/§3.11 „Mandats-
 * Rechtstext"/§8 Compliance-Anhang, Issue #67): der zweite Aktivierungsweg
 * neben dem Papier-Gate aus #66 – ein bestätigter E-Mail-Einmal-Link, bei
 * dessen Zustimmung sich das Mandat selbst aktiviert.
 *
 * Zwei neue, additive Tabellen:
 *
 * - `vbh_mandate_legal_text_versions`: der versionierte Mandats-Rechtstext
 *   (Pflichtblock + Rahmen, EIN Textkörper – siehe
 *   {@see \OCA\Vereinsbuchhaltung\Db\MandateLegalTextVersion}). Bewusst eine
 *   eigene Tabelle, NICHT geteilt mit Mail-Templates (die sind Code-Fixtexte,
 *   dieser Rechtstext ist DB-versioniert und admin-editierbar) – siehe
 *   Spec §3.11, Korrektur an der ursprünglichen T08-Annahme.
 *
 * - `vbh_mandate_activation_tokens`: der signierte Einmal-Link. Klassisches
 *   Selector/Validator-Muster (wie Passwort-Reset-Tokens vieler Web-Apps):
 *   `selector` ist ein indizierbarer, öffentlicher Bezeichner, `validator_hash`
 *   der SHA-256-Hash des eigentlichen Geheimnisses – nur der Hash liegt in
 *   der DB, das Geheimnis selbst kennt nur die versendete Mail. So bleibt ein
 *   Datenbank-Leck allein kein Weg, ein Mandat zu aktivieren. Der Token selbst
 *   landet in der URL (das ist sein Zweck), niemals die IBAN oder andere
 *   Bankdaten – die liefert erst die serverseitige Auflösung des Tokens.
 *
 * `vbh_mandates` bekommt additiv das Beweispaket aus Spec §2.2/§8
 * (`mandate_text_version`, `consent_at`, `consent_ip`, `consent_user_agent`,
 * `consent_actor`) – alle nullable, weil ein Papier-Mandat (#66) sie nie
 * füllt und bestehende Datensätze nicht rückwirkend betroffen sind ("keine
 * Rückwirkung auf bestehende Mandate", Spec §2.2).
 *
 * Versionsnummer 000142: nächste freie Nummer nach dem #65/#66/#68-Stand
 * dieses gestapelten Branches (siehe Versionsnummern-Kommentar in
 * Version000141).
 */
class Version000142Date20260916140000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_mandate_legal_text_versions')) {
			$table = $schema->createTable('vbh_mandate_legal_text_versions');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 16]);
			// Voller Text (Pflichtblock + Rahmen) inkl. {{creditor_name}}-Platzhalter,
			// siehe MandateLegalTextVersion::render(). Keine Größenbegrenzung, ein
			// Rechtstext mit AGB-artiger Länge muss hineinpassen.
			$table->addColumn('body', Types::TEXT, ['notnull' => true]);
			$table->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->setPrimaryKey(['id']);
		}

		if (!$schema->hasTable('vbh_mandate_activation_tokens')) {
			$table = $schema->createTable('vbh_mandate_activation_tokens');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('mandate_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			// Oeffentlicher, indizierbarer Teil des Tokens (Selector/Validator-Muster).
			$table->addColumn('selector', Types::STRING, ['notnull' => true, 'length' => 32]);
			// SHA-256-Hex-Hash des Validators - NIE der Validator selbst.
			$table->addColumn('validator_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			// Empfaengeradresse dieses Links (NC-Konto- oder Mitglieds-Mailadresse,
			// Spec §2.2) - zugleich das "Identitaets"-Beweiselement bei Zustimmung.
			$table->addColumn('email', Types::STRING, ['notnull' => true, 'length' => 255]);
			// Bei der ERSTEN Anzeige fixiert (Spec §2.2: "Version wird bei Anzeige
			// fixiert, nicht bei signed_at") - siehe MandateActivationService::view().
			$table->addColumn('legal_text_version_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			// uid der/des Mitarbeitenden, die/der den Versand ausgeloest hat; null
			// bei einer Selbstbedienungs-Anfrage ueber den Self-Service-Kanal.
			$table->addColumn('requested_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('expires_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('first_viewed_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			$table->addColumn('consumed_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['selector'], 'vbh_mand_tok_selector');
			$table->addIndex(['mandate_id'], 'vbh_mand_tok_mid');
		}

		if ($schema->hasTable('vbh_mandates')) {
			$table = $schema->getTable('vbh_mandates');
			if (!$table->hasColumn('mandate_text_version')) {
				$table->addColumn('mandate_text_version', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			}
			if (!$table->hasColumn('consent_at')) {
				$table->addColumn('consent_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			}
			if (!$table->hasColumn('consent_ip')) {
				$table->addColumn('consent_ip', Types::STRING, ['notnull' => false, 'length' => 64]);
			}
			if (!$table->hasColumn('consent_user_agent')) {
				$table->addColumn('consent_user_agent', Types::TEXT, ['notnull' => false]);
			}
			// Bewusst die tatsaechlich verwendete Mailadresse, kein Enum: Spec §8
			// verlangt als viertes Beweispaket-Element "Identitaet" - bei einem
			// anonymen Einmal-Link IST die Zustelladresse die einzige belastbare
			// Identitaetsspur (siehe MandateActivationService::consent()).
			if (!$table->hasColumn('consent_actor')) {
				$table->addColumn('consent_actor', Types::STRING, ['notnull' => false, 'length' => 255]);
			}
		}

		return $schema;
	}
}
