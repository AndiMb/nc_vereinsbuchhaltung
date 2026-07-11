<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Jahresabschluss: abgeschlossene Geschäftsjahre sind festgeschrieben, ihre
 * Buchungen (inkl. Belege und Zuordnungen) können nicht mehr geändert werden.
 * Kein user_id-Feld: die Buchhaltung ist ein gemeinsamer Bestand (Application::BOOK),
 * der Abschluss gilt daher global je Jahr.
 */
class Version000114Date20260711000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_year_close')) {
			$table = $schema->createTable('vbh_year_close');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('year', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('closed_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('closed_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['year'], 'vbh_yearclose_year');
		}

		return $schema;
	}
}
