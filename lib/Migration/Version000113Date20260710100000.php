<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Kollaboration: Zeitstempel der letzten Änderung je Buchungssatz für
 * optimistisches Locking (Konflikt statt stillem Überschreiben, wenn zwei
 * Personen dieselbe Buchung bearbeiten).
 *
 * Als String mit Mikrosekunden ('Y-m-d H:i:s.u'), damit der Vergleich nicht
 * an der Sekundenauflösung von DATETIME scheitert. NULL = seit Einführung
 * nie bearbeitet; auch das ist ein gültiger Vergleichswert.
 */
class Version000113Date20260710100000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('vbh_journal')) {
			$table = $schema->getTable('vbh_journal');
			if (!$table->hasColumn('updated_at')) {
				$table->addColumn('updated_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			}
		}

		return $schema;
	}
}
