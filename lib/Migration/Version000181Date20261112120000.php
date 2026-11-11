<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Vorgabe-Kostenstelle je Beitragsgruppe (Issue #333).
 *
 * Neue nullable Spalte `booking_cc_id` (zeigt auf `vbh_booking_cc`): Beiträge
 * dieser Gruppe werden beim Verbuchen des Einzugs dieser Kostenstelle
 * zugeordnet.
 */
class Version000181Date20261112120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('vbh_contribution_groups')) {
			return null;
		}
		$table = $schema->getTable('vbh_contribution_groups');
		if ($table->hasColumn('booking_cc_id')) {
			return null;
		}
		$table->addColumn('booking_cc_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
		return $schema;
	}
}
