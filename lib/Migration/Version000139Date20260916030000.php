<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Mitglied-Entity – Schritt 3 von 3: aufräumen. Entfernt die alten Spalten
 * `member_uid`/`member_label` samt ihrer Indizes aus `vbh_sepa_mandates`/
 * `vbh_membership_fees` (Version000137 hat `member_id` ergänzt,
 * Version000138 hat ihn befüllt) – dasselbe Dreischritt-Vorgehen wie bei den
 * Geschäftsjahren (Version000133/134/135).
 *
 * `member_id` bleibt bewusst nullable auf DB-Ebene, obwohl Anwendungscode
 * (SepaMandateService::create()/MembershipFeeService::create()) es immer
 * setzt – dieselbe schlanke Konvention wie bei den bestehenden `mandate_id`/
 * `account_id`-Spalten in dieser App, ohne dass sich SQLite/MySQL/PostgreSQL
 * beim nachträglichen Verschärfen einer NOT-NULL-Regel unterschiedlich
 * verhalten könnten.
 */
class Version000139Date20260916030000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('vbh_sepa_mandates')) {
			$table = $schema->getTable('vbh_sepa_mandates');
			if ($table->hasIndex('vbh_sepa_mand_uid')) {
				$table->dropIndex('vbh_sepa_mand_uid');
			}
			foreach (['member_uid', 'member_label'] as $column) {
				if ($table->hasColumn($column)) {
					$table->dropColumn($column);
				}
			}
		}

		if ($schema->hasTable('vbh_membership_fees')) {
			$table = $schema->getTable('vbh_membership_fees');
			if ($table->hasIndex('vbh_fee_uid')) {
				$table->dropIndex('vbh_fee_uid');
			}
			foreach (['member_uid', 'member_label'] as $column) {
				if ($table->hasColumn($column)) {
					$table->dropColumn($column);
				}
			}
		}

		return $schema;
	}
}
