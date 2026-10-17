<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Vorlagen des Gliederungsberichts: Name und Definition (Abschnitte, Ebenen,
 * Spalten, Berichtsgruppen).
 *
 * Die Definition steht als JSON in einer Spalte. Sie ist ein kleiner Baum, wird
 * immer als Ganzes gelesen und geschrieben und nie per SQL durchsucht; geprüft
 * wird sie von {@see \OCA\Vereinsbuchhaltung\Service\Report\ReportDefinition}.
 * Die mitgelieferten Vorlagen stehen nicht hier, sondern im Code.
 */
class Version000161Date20261017000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('vbh_report_templates')) {
			return null;
		}
		$table = $schema->createTable('vbh_report_templates');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('definition', Types::TEXT, ['notnull' => true]);
		$table->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['user_id'], 'vbh_rt_user');

		return $schema;
	}
}
