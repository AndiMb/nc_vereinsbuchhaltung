<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use Doctrine\DBAL\Types\Type;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * DSGVO-Anonymisierung (Spec §3.8, Issue #78, T30): fügt `redacted_at` an
 * `vbh_members` und `vbh_mandates` hinzu – „nie hart löschen, nur
 * ended/redacted_at" (Compliance-Anhang Spec §8, Zeile „Aufbewahrung"). Beide
 * Spalten sind additiv und nullable: ungesetzt heißt „noch nicht
 * anonymisiert", das ist der weit überwiegende Regelfall.
 *
 * Zusätzlich wird `vbh_debit_items.iban` nachträglich nullable – derselbe
 * Grund wie bei `vbh_mandates.iban` (Version000140): der Einzugsposten ist
 * laut Version000143-Klassendoc ausdrücklich als „künftige DSGVO-Löschung der
 * Bankdaten laut Spec §3.8" vorgesehen. `account_holder` bleibt dagegen NOT
 * NULL (wie beim Mandat) und bekommt bei der Anonymisierung stattdessen einen
 * festen Platzhaltertext (siehe {@see \OCA\Vereinsbuchhaltung\Service\MemberAnonymizationService}) –
 * eine weitere Schema-Änderung dafür lohnt sich nicht.
 */
class Version000146Date20260917030000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('vbh_members')) {
			$table = $schema->getTable('vbh_members');
			if (!$table->hasColumn('redacted_at')) {
				$table->addColumn('redacted_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			}
		}

		if ($schema->hasTable('vbh_mandates')) {
			$table = $schema->getTable('vbh_mandates');
			if (!$table->hasColumn('redacted_at')) {
				$table->addColumn('redacted_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			}
		}

		if ($schema->hasTable('vbh_debit_items')) {
			$table = $schema->getTable('vbh_debit_items');
			if ($table->hasColumn('iban')) {
				$table->changeColumn('iban', [
					'type' => Type::getType(Types::STRING),
					'notnull' => false,
					'length' => 34,
				]);
			}
		}

		return $schema;
	}
}
