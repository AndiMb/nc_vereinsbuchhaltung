<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Unique-Index auf die Buchungsnummer je Geschäftsjahr.
 *
 * Damit kann die Nummer nicht mehr doppelt vergeben werden: bisher ermittelten
 * zwei gleichzeitig gespeicherte Buchungen beide dasselbe MAX(entry_no)+1 und
 * bekamen dieselbe Nummer – für eine fortlaufende Buchungsnummerierung ein
 * echter Mangel, den der Kassenbericht zwar meldete, den man aber nicht mehr
 * beheben konnte. Der Index lässt jetzt nur noch eine der beiden Buchungen
 * durch; die andere wiederholt der TransactionRunner automatisch mit frisch
 * ermittelter Nummer.
 *
 * Als zusätzlicher Index (user_id, year) dient er außerdem der Jahresliste und
 * der Nummernvergabe selbst.
 *
 * Setzt Version000119 voraus: dort werden die Jahresspalte gefüllt und
 * vorhandene Doppelungen aufgelöst – vorher ließe sich der Index nicht anlegen.
 */
class Version000120Date20260728010000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_journal')) {
			return null;
		}
		$table = $schema->getTable('vbh_journal');
		if (!$table->hasIndex('vbh_jrn_user_year_no')) {
			$table->addUniqueIndex(['user_id', 'year', 'entry_no'], 'vbh_jrn_user_year_no');
		}

		return $schema;
	}
}
