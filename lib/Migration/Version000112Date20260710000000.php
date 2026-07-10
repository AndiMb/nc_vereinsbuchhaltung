<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Entfernt das kurzlebige Konto-Flag "transitory" (v0.10.34) wieder.
 *
 * Neues Modell: ueber Jahresgrenzen kumulieren ausschliesslich Geldkonten
 * (Bank/Kasse, is_bank) – siehe Account::isStockAccount(). Damit sind
 * Durchlauf-/Verrechnungs-/Uebertragskonten automatisch jahresbezogen und
 * brauchen weder ein eigenes Flag noch eine Namensheuristik.
 */
class Version000112Date20260710000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('vbh_accounts')) {
			$table = $schema->getTable('vbh_accounts');
			if ($table->hasColumn('transitory')) {
				$table->dropColumn('transitory');
			}
		}

		return $schema;
	}
}
