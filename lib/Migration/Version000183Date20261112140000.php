<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Jahresbeiträge (Issue #270).
 *
 * Eine Beitragsgruppe gibt ihre Beträge „pro Monat“ (wie bisher) oder „pro
 * Jahr“ an (`amount_basis`) und legt fest, ob ein angebrochenes Beitragsjahr
 * anteilig berechnet wird (`prorate`). Der Jahresbetrag steht in eigenen,
 * nullable Spalten an Gruppe und Zuweisung; sie sind genau bei Jahresgruppen
 * gefüllt. Die bisherigen Monatsspalten bleiben dort als gerundeter Hilfswert
 * bestehen. Vorhandene Gruppen und Zuweisungen bleiben unverändert
 * (`amount_basis = 'month'`).
 */
class Version000183Date20261112140000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('vbh_contribution_groups')) {
			$groups = $schema->getTable('vbh_contribution_groups');
			if (!$groups->hasColumn('amount_basis')) {
				$groups->addColumn('amount_basis', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'month']);
				$groups->addColumn('prorate', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
				$groups->addColumn('min_annual_amount_cents', Types::BIGINT, ['notnull' => false, 'length' => 20]);
				$groups->addColumn('default_annual_amount_cents', Types::BIGINT, ['notnull' => false, 'length' => 20]);
				$changed = true;
			}
		}

		if ($schema->hasTable('vbh_assignments')) {
			$assignments = $schema->getTable('vbh_assignments');
			if (!$assignments->hasColumn('annual_amount_cents')) {
				$assignments->addColumn('annual_amount_cents', Types::BIGINT, ['notnull' => false, 'length' => 20]);
				$assignments->addColumn('min_annual_override_cents', Types::BIGINT, ['notnull' => false, 'length' => 20]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
