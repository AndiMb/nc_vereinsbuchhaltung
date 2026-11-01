<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Auslagenantrag nach dem Vorbild des Papierformulars: Rechnungsnummer und
 * Lieferant / Shop je Antrag sowie die vollständige Kontoverbindung mit
 * Kontoinhaber. Mehrere Rechnungen reicht das Mitglied als getrennte
 * Anträge ein, jeder mit eigener Entscheidung und Auszahlung.
 *
 * Alle Spalten sind optional; bestehende Anträge bleiben unverändert.
 */
class Version000171Date20261101130000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('vbh_reimbursements')) {
			return null;
		}

		$table = $schema->getTable('vbh_reimbursements');
		$changed = false;
		$columns = [
			'invoice_number' => 100,
			'supplier' => 255,
			'account_holder' => 255,
		];
		foreach ($columns as $name => $length) {
			if (!$table->hasColumn($name)) {
				$table->addColumn($name, Types::STRING, ['notnull' => false, 'length' => $length]);
				$changed = true;
			}
		}
		return $changed ? $schema : null;
	}
}
