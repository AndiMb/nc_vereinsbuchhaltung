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
 * trägt die Hintergrundjobs des flachen Alt-Moduls der Expand-Phase aus.
 *
 * **Tabellen bleiben stehen.** Dieser Schritt räumte ursprünglich
 * `vbh_sepa_batch_items`, `vbh_sepa_batches`, `vbh_membership_fees` und
 * `vbh_sepa_mandates` samt Inhalt ab (Spec §1.2: „keine Produktivnutzer").
 * Der Alt-Stand war aber ab 0.22 veröffentlicht, und ein Update soll weder
 * Mandate noch Beiträge vernichten. Deshalb wird hier nichts mehr gelöscht:
 * {@see Version000158Date20261010000000} verschiebt Mandate und Beiträge in das
 * neue Modell und entfernt die Alt-Tabellen nur, wenn sie leer sind; die
 * Sammeleinzüge des alten Moduls haben im neuen Einzugsmodell keine
 * Entsprechung und bleiben liegen. Auch die Mitglieder, die Version000138 aus
 * den Alt-Zahlern angelegt hat, bleiben bestehen. Die Alt-Migrationen selbst
 * bleiben unverändert.
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
 * **Idempotenz.** `IJobList::remove()` ist ohne Eintrag ein leeres DELETE: der
 * Schritt läuft auf einer Neuinstallation, auf einer 0.34.x-Instanz mit
 * Alt-Tabellen (mit und ohne Daten) und nach einem abgebrochenen Versuch ohne
 * Fehler durch.
 *
 * Versionsnummer 000157: dem Ticket #107 vorab zugewiesen (parallele Agenten,
 * siehe Version000153).
 */
class Version000157Date20261004130000 extends SimpleMigrationStep {

	/** Die Alt-Tabellen des flachen Moduls; Version000158 verschiebt ihren Inhalt und entfernt leere. */
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
		// Kein Schema-Eingriff: die Alt-Tabellen bleiben, siehe Klassenkommentar.
		return null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		foreach (self::LEGACY_JOBS as $job) {
			$this->jobList->remove($job);
		}
	}
}
