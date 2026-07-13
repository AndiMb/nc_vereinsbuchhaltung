<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Rücklagen-Art je Konto (frei/zweckgebunden/wiederbeschaffung, § 62 AO) –
 * NULL bedeutet „keine Rücklage". Nur für Eigenkapital-Konten relevant
 * (type === 'equity'); Zuweisungen sind normale Buchungen im Experten-Modus
 * (Eigenkapital-zu-Eigenkapital-Umbuchung), kein neuer Buchungsmechanismus.
 */
class Version000117Date20260713000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('vbh_accounts')) {
			$table = $schema->getTable('vbh_accounts');
			if (!$table->hasColumn('reserve_kind')) {
				$table->addColumn('reserve_kind', Types::STRING, ['notnull' => false, 'length' => 32]);
			}
		}

		return $schema;
	}
}
