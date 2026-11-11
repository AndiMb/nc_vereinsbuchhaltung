<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Importregeln können eine Kostenstelle vorgeben (Issue #331).
 *
 * Eine nullable Spalte an `vbh_rules`; bestehende Regeln bleiben ohne
 * Vorgabe und verhalten sich wie zuvor. Der Wert zeigt auf `vbh_booking_cc`
 * (Kostenstellen an der Buchungszeile), nicht auf die Auswertungsgruppen.
 */
class Version000178Date20261112090000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('vbh_rules')) {
			return null;
		}
		$table = $schema->getTable('vbh_rules');
		if ($table->hasColumn('cost_center_id')) {
			return null;
		}
		$table->addColumn('cost_center_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
		return $schema;
	}
}
