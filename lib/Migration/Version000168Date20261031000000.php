<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Erneute Bestätigung des Mandatstexts: der Einmal-Link bekommt einen Zweck.
 * Bisher gab es nur die Aktivierung eines Entwurfs; ein Link, mit dem ein
 * bereits aktives Mandat den geänderten Rechtstext bestätigt, braucht dieselbe
 * Tabelle (Selector/Validator, Ablauf, Einmal-Verwendung), muss sich aber bei
 * der Zustimmung anders verhalten. Bestehende Links bleiben Aktivierungslinks.
 */
class Version000168Date20261031000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_mandate_activation_tokens')) {
			return null;
		}
		$table = $schema->getTable('vbh_mandate_activation_tokens');
		if ($table->hasColumn('purpose')) {
			return null;
		}
		$table->addColumn('purpose', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'aktivierung']);
		return $schema;
	}
}
