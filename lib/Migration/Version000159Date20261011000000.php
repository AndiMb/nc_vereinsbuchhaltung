<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Räumt die gespeicherten Werte der entfallenen Einstellung „Standard-Beitrag"
 * (Betrag und Frequenz, Nextcloud-Einstellungen → Beiträge & SEPA) ab.
 *
 * Betrag und Turnus eines Mitglieds kommen seit dem Beitragsgruppen-Modell von
 * der Beitragsgruppe (`default_monthly_amount_cents`, `default_interval`); die
 * vereinsweite Vorbelegung daneben war überholt und ist entfallen. Gelesen
 * werden die beiden Werte nirgends mehr – sie lägen sonst als tote Einträge in
 * `oc_appconfig`.
 *
 * Nur Konfiguration, kein Schema: {@see changeSchema()} ändert nichts. Bei einer
 * Neuinstallation läuft ohnehin nur der Schema-Teil der Migrationen
 * (`migrateSchemaOnly`), `postSchemaChange()` entfällt dort – es gibt dann
 * nichts abzuräumen.
 *
 * **Idempotenz.** `IConfig::deleteAppValue()` ist ohne vorhandenen Eintrag ein
 * leeres DELETE: der Schritt läuft auf einer Instanz mit gesetztem Standard-Beitrag,
 * auf einer ohne und nach einem abgebrochenen Versuch ohne Fehler durch.
 *
 * Versionsnummer 000159: dem Ticket vorab zugewiesen (parallele Agenten, siehe
 * Version000153).
 */
class Version000159Date20261011000000 extends SimpleMigrationStep {

	/** Die beiden App-Config-Schlüssel der entfallenen Einstellung „Standard-Beitrag". */
	public const LEGACY_KEYS = [
		'default_fee_amount_cents',
		'default_fee_frequency',
	];

	public function __construct(
		private IConfig $config,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		// Kein Schema-Eingriff, siehe Klassenkommentar.
		return null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		foreach (self::LEGACY_KEYS as $key) {
			$this->config->deleteAppValue(Application::APP_ID, $key);
		}
	}
}
