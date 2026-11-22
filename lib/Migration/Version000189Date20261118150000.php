<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * PayPal-Adresse(n) am Geldkonto (Issue #375).
 *
 * Ein PayPal-Konto hat keine IBAN; der CSV-Export nennt nur die E-Mail-
 * Adresse. Sie steht – bei mehreren Adressen kommagetrennt – am Geldkonto
 * und ordnet die PayPal-Umsätze diesem Konto zu. Nullable: ohne Eintrag
 * ist das Konto kein PayPal-Konto.
 */
class Version000189Date20261118150000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('vbh_accounts')) {
			return null;
		}
		$table = $schema->getTable('vbh_accounts');
		if ($table->hasColumn('paypal_email')) {
			return null;
		}
		$table->addColumn('paypal_email', Types::STRING, ['notnull' => false, 'length' => 512]);
		return $schema;
	}
}
