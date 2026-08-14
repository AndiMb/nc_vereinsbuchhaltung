<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Entfernt die separate CSV-Import-Historie (vbh_imports).
 *
 * Jeder Import wird seit Langem zusätzlich im Änderungsprotokoll
 * festgehalten (ImportController::commit()/xbucCommit(), mit Dateiname,
 * neu/Dubletten bzw. Jahr/Buchungen) – die eigene Liste unter Einstellungen
 * → Daten zeigte dieselben Angaben nur ein zweites Mal. bank_transactions.
 * import_id blieb dabei ohnehin ungelesen (kein Feature griff darauf zu),
 * daher genügt es, die Spalte auf null stehen zu lassen und nur die Tabelle
 * zu entfernen.
 */
class Version000131Date20260815000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('vbh_imports')) {
			$schema->dropTable('vbh_imports');
			return $schema;
		}

		return null;
	}
}
