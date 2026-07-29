<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Entfernt den Index (user_id, entry_no) aus Version000102.
 *
 * Seit Version000120 gibt es den Unique-Index (user_id, year, entry_no). Der
 * deckt dieselben Abfragen ab – jede Suche über user_id bzw. user_id+entry_no
 * kann seinen führenden Spaltenteil nutzen. Der alte Index kostet daher nur
 * noch Schreibleistung und Plattenplatz bei jeder Buchung.
 */
class Version000121Date20260729000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_journal')) {
			return null;
		}
		$table = $schema->getTable('vbh_journal');
		if ($table->hasIndex('vbh_jrn_user_entry')) {
			$table->dropIndex('vbh_jrn_user_entry');
		}

		return $schema;
	}
}
