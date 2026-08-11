<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Hält fest, *warum* eine Sammeleinzug-Zeile als erledigt gilt: verschickt
 * oder mangels E-Mail-Adresse übersprungen. Version000127 kannte nur
 * `notified_at`, damit dieselbe Mail nicht täglich erneut rausging – ein
 * Zahler ohne Adresse bekam dabei nie einen Vermerk und wurde in jedem
 * Tageslauf erneut geprüft, ohne dass irgendwo sichtbar wurde, dass hier
 * niemand angekündigt werden kann (siehe SepaNotificationService).
 */
class Version000128Date20260812000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('vbh_sepa_batch_items') && !$schema->getTable('vbh_sepa_batch_items')->hasColumn('notified_state')) {
			$schema->getTable('vbh_sepa_batch_items')->addColumn('notified_state', Types::STRING, ['notnull' => false, 'length' => 16]);
			return $schema;
		}

		return null;
	}
}
