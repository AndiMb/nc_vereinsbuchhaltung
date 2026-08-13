<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Hält fest, wann ein Sammeleinzug tatsächlich ausgeführt wurde.
 *
 * Bis hierher endete der Ablauf beim heruntergeladenen XML: die Einzugszeilen
 * blieben für immer `pending`, und die zugehörigen offenen Posten blieben
 * offen, obwohl das Geld längst da war. Wer 80 Mitglieder einzieht, musste 80
 * Posten von Hand auf „bezahlt" setzen – genau die Arbeit, die das Modul
 * abnehmen soll (siehe SepaBatchService::settleBatch()).
 */
class Version000129Date20260812100000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('vbh_sepa_batches') && !$schema->getTable('vbh_sepa_batches')->hasColumn('settled_at')) {
			$schema->getTable('vbh_sepa_batches')->addColumn('settled_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			return $schema;
		}

		return null;
	}
}
