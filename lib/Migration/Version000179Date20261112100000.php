<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Kennzeichen „Kostenstelle verpflichtend“ am Konto (Issue #334).
 *
 * Nullable mit Vorgabe false: bestehende Konten bleiben ohne Kennzeichen,
 * und NULL gilt wie false (siehe Account::isCostCenterRequired()). Das
 * Kennzeichen sperrt nichts – es lässt die Buchungsdialoge warnen und den
 * Kostenstellen-Bericht nicht zugeordnete Beträge auflisten.
 */
class Version000179Date20261112100000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('vbh_accounts')) {
			return null;
		}
		$table = $schema->getTable('vbh_accounts');
		if ($table->hasColumn('cost_center_required')) {
			return null;
		}
		$table->addColumn('cost_center_required', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		return $schema;
	}
}
