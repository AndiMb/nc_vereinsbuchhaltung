<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Geschäftsjahre als eigene Tabelle – Schritt 2 von 3: die Indizes (Issue #8).
 *
 * Der wichtigste ist der Unique-Index über (user_id, period_id, entry_no). Er
 * hielt bisher (user_id, year, entry_no) zusammen und sichert die
 * Buchungsnummer gegen doppelte Vergabe: zwei gleichzeitig gespeicherte
 * Buchungen ermitteln beide dasselbe MAX(entry_no)+1, und ohne den Index
 * bekämen beide dieselbe Nummer. Mit ihm kommt eine durch, die andere
 * wiederholt der TransactionRunner mit frisch ermittelter Nummer.
 *
 * Über die Perioden-ID statt der Jahreszahl kann er jetzt auch das, was mit
 * der Zahl nicht ging: zwei Halbjahre desselben Kalenderjahres bekommen
 * getrennte Nummernkreise, die beide bei 1 anfangen.
 *
 * Setzt Version000133 voraus – vorher stünde period_id überall auf 0, und der
 * Unique-Index ließe sich nicht anlegen. Die alten Spalten fallen erst in
 * Version000135; ein Index auf einer Spalte, die im selben Schritt entfernt
 * wird, führte je nach Datenbank zu unterschiedlichen Ergebnissen.
 */
class Version000134Date20260908010000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('vbh_journal')) {
			$table = $schema->getTable('vbh_journal');
			if ($table->hasIndex('vbh_jrn_user_year_no')) {
				$table->dropIndex('vbh_jrn_user_year_no');
			}
			if (!$table->hasIndex('vbh_jrn_user_period_no')) {
				$table->addUniqueIndex(['user_id', 'period_id', 'entry_no'], 'vbh_jrn_user_period_no');
			}
		}

		if ($schema->hasTable('vbh_budgets')) {
			$table = $schema->getTable('vbh_budgets');
			if ($table->hasIndex('vbh_budget_unique')) {
				$table->dropIndex('vbh_budget_unique');
			}
			if (!$table->hasIndex('vbh_budget_period_uniq')) {
				$table->addUniqueIndex(['user_id', 'account_id', 'period_id'], 'vbh_budget_period_uniq');
			}
		}

		if ($schema->hasTable('vbh_budget_snapshots')) {
			$table = $schema->getTable('vbh_budget_snapshots');
			if ($table->hasIndex('vbh_snap_user_year')) {
				$table->dropIndex('vbh_snap_user_year');
			}
			if (!$table->hasIndex('vbh_snap_user_period')) {
				$table->addIndex(['user_id', 'period_id'], 'vbh_snap_user_period');
			}
		}

		return $schema;
	}
}
