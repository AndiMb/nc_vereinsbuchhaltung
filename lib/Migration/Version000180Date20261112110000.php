<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Rechnungszeilen tragen eine Kostenstelle (Issue #332).
 *
 * Neue nullable Spalte `booking_cc_id` (zeigt auf `vbh_booking_cc`). Die
 * ältere Spalte `cost_center_id` derselben Tabelle meint eine
 * Auswertungsgruppe, wurde nie ausgewertet und bleibt unangetastet.
 */
class Version000180Date20261112110000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('vbh_invoice_lines')) {
			return null;
		}
		$table = $schema->getTable('vbh_invoice_lines');
		if ($table->hasColumn('booking_cc_id')) {
			return null;
		}
		$table->addColumn('booking_cc_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
		return $schema;
	}
}
