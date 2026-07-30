<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Vorbereitung für weitere Umsatzquellen neben der CSV.
 *
 * - vbh_imports.source: aus welchem Format ein Import stammt (csv, camt, mt940
 *   und später fints). Bisher ließ sich das nur am Dateinamen raten; bei einem
 *   automatischen Abruf gibt es gar keinen.
 * - vbh_accounts.iban: ordnet ein Geldkonto seiner IBAN zu. Wer Umsätze
 *   mehrerer Konten einliest, braucht diese Zuordnung – sonst ist nicht
 *   entscheidbar, auf welches Geldkonto eine Bankbuchung gehört.
 *
 * Bestehende Zeilen bleiben unangetastet; insbesondere werden KEINE Dedup-
 * Hashes neu berechnet. Ein neu berechneter Hash ließe jeden bereits
 * importierten Umsatz wieder als neu gelten.
 */
class Version000122Date20260730000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('vbh_imports')) {
			$table = $schema->getTable('vbh_imports');
			if (!$table->hasColumn('source')) {
				$table->addColumn('source', 'string', [
					'notnull' => false,
					'length' => 16,
					// Alles, was vor dieser Migration importiert wurde, kam über
					// den CSV-Upload – das ist die einzige Quelle, die es gab.
					'default' => 'csv',
				]);
				$changed = true;
			}
		}

		if ($schema->hasTable('vbh_accounts')) {
			$table = $schema->getTable('vbh_accounts');
			if (!$table->hasColumn('iban')) {
				$table->addColumn('iban', 'string', [
					'notnull' => false,
					'length' => 40,
				]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
