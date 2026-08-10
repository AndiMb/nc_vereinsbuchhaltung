<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Merkt sich, wann die SEPA-Vorankündigung für eine Sammeleinzug-Zeile
 * verschickt wurde – ohne dieses Feld schickte jeder Tageslauf des
 * SepaPreNotificationJob dieselbe Mail erneut.
 */
class Version000127Date20260810030000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('vbh_sepa_batch_items') && !$schema->getTable('vbh_sepa_batch_items')->hasColumn('notified_at')) {
			$schema->getTable('vbh_sepa_batch_items')->addColumn('notified_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			return $schema;
		}

		return null;
	}
}
