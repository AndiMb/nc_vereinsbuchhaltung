<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\BackgroundJob\IJobList;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Cutover des Beiträge/SEPA-Moduls (Issue #107, Spec §12 „Umbaupfad", §1.2):
 * räumt das flache Alt-Modul der Expand-Phase aus der Datenbank.
 *
 * **Tabellen.** `vbh_sepa_batch_items`, `vbh_sepa_batches`,
 * `vbh_membership_fees` und `vbh_sepa_mandates` (angelegt von Version000124 bis
 * Version000130, `member_uid`/`member_label` von Version000137 bis 000139 auf
 * `member_id` umgestellt) hatten laut Spec §1.2 keine Produktivnutzer und
 * werden hart entfernt – samt ihrem Inhalt, es gibt keine Datenübernahme in
 * das neue Modell (`vbh_mandates`, `vbh_assignments`, `vbh_debit_batches` …).
 * Die Mitglieder, die Version000138 aus den Alt-Zahlern angelegt hat, bleiben
 * als `vbh_members` bestehen. Die Alt-Migrationen selbst bleiben unverändert,
 * Nextcloud führt sie bei einer Neuinstallation noch aus: dort entstehen die
 * Tabellen und verschwinden hier sofort wieder.
 *
 * Bewusst **nicht** angefasst: `vbh_open_items` (geteilte Tabelle der
 * Kern-Buchhaltung, Spec §1.2 erlaubt dort nur additive Migrationen) behält
 * die Spalte `mandate_id` des Alt-Moduls; sie wird nur nicht mehr geschrieben.
 * Ebenso unberührt bleiben `vbh_bank_tx` und der Import-Hash.
 *
 * **Hintergrundjobs.** Nextcloud trägt Jobs aus `appinfo/info.xml` beim
 * Installieren und Aktualisieren ein (`IJobList::add()`), nimmt einen daraus
 * entfernten Job aber nie wieder heraus. Fehlt die Klasse, behandelt
 * `OC\BackgroundJob\JobList::buildJob()` die Zeile zwar selbst (Warnung
 * „failed to create instance of background job" im Log, danach löscht sie die
 * Zeile) – das soll das Log aber gar nicht erst sehen, deshalb trägt dieser
 * Schritt die beiden Alt-Jobs ausdrücklich aus. Die Klassennamen stehen als
 * Zeichenketten da: die Klassen gibt es nicht mehr.
 *
 * **Idempotenz.** Jede Tabelle wird nur gelöscht, wenn sie existiert, und
 * `IJobList::remove()` ist ohne Eintrag ein leeres DELETE: der Schritt läuft
 * auf einer Neuinstallation, auf einer 0.34.x-Instanz mit Alt-Tabellen (mit und
 * ohne Daten) und nach einem abgebrochenen Versuch ohne Fehler durch.
 *
 * Versionsnummer 000157: dem Ticket #107 vorab zugewiesen (parallele Agenten,
 * siehe Version000153).
 */
class Version000157Date20261004130000 extends SimpleMigrationStep {

	/** Die Alt-Tabellen, Kind-Tabelle zuerst (Fremdschlüssel gibt es nicht, die Reihenfolge ist nur Ordnung). */
	public const LEGACY_TABLES = [
		'vbh_sepa_batch_items',
		'vbh_sepa_batches',
		'vbh_membership_fees',
		'vbh_sepa_mandates',
	];

	/** Die beiden aus `appinfo/info.xml` entfernten Jobs, deren Klassen es nicht mehr gibt. */
	public const LEGACY_JOBS = [
		'OCA\\Vereinsbuchhaltung\\BackgroundJob\\MembershipFeeDueJob',
		'OCA\\Vereinsbuchhaltung\\BackgroundJob\\SepaPreNotificationJob',
	];

	public function __construct(
		private IJobList $jobList,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$dropped = [];
		foreach (self::LEGACY_TABLES as $table) {
			if ($schema->hasTable($table)) {
				$schema->dropTable($table);
				$dropped[] = $table;
			}
		}
		if ($dropped === []) {
			return null;
		}

		$output->info('Vereinsbuchhaltung: Alt-Tabellen des flachen Beiträge/SEPA-Moduls entfernt: ' . implode(', ', $dropped));
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		foreach (self::LEGACY_JOBS as $job) {
			$this->jobList->remove($job);
		}
	}
}
