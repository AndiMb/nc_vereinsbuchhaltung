<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Kurzname und Beschreibung für Konten (#201).
 *
 * Der Kurzname steht in den schmalen Auswahllisten statt des langen Namens;
 * die Beschreibung (Markdown) erklärt am Konto, wofür es da ist.
 */
class Version000174Date20261108000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('vbh_accounts')) {
			return null;
		}
		$table = $schema->getTable('vbh_accounts');
		$changed = false;
		if (!$table->hasColumn('short_name')) {
			$table->addColumn('short_name', Types::STRING, ['notnull' => false, 'length' => 64]);
			$changed = true;
		}
		if (!$table->hasColumn('description')) {
			$table->addColumn('description', Types::TEXT, ['notnull' => false]);
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
