<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Steuerliche Sphäre je Konto (ideell/vermoegensverwaltung/zweckbetrieb/
 * wirtschaftlich) – NULL bedeutet „nicht zugeordnet". Nur für Einnahmen-/
 * Ausgaben-Konten relevant, siehe Account::isResultRelevant().
 */
class Version000116Date20260712000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('vbh_accounts')) {
			$table = $schema->getTable('vbh_accounts');
			if (!$table->hasColumn('sphere')) {
				$table->addColumn('sphere', Types::STRING, ['notnull' => false, 'length' => 32]);
			}
		}

		return $schema;
	}
}
