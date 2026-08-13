<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * E-Mail-Adresse am Mandat.
 *
 * Die SEPA-Vorankündigung ist Pflicht, ging bisher aber nur an Zahler mit
 * Nextcloud-Konto *und* dort hinterlegter Adresse. In einem Chor oder einem
 * Verein mit 200 Mitgliedern hat kaum jemand ein Konto auf dem Server – für
 * die überwiegende Mehrheit konnte also nie angekündigt werden. Die Adresse
 * am Mandat schließt diese Lücke; das Nextcloud-Konto bleibt der Rückfall
 * (siehe SepaNotificationService::resolveRecipient()).
 */
class Version000130Date20260812110000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('vbh_sepa_mandates') && !$schema->getTable('vbh_sepa_mandates')->hasColumn('email')) {
			$schema->getTable('vbh_sepa_mandates')->addColumn('email', Types::STRING, ['notnull' => false, 'length' => 255]);
			return $schema;
		}

		return null;
	}
}
