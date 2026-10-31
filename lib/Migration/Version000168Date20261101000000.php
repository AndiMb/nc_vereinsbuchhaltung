<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Index der Belegdateien im Wächter-Ordner für die Belegvorschläge – ein
 * reiner Zwischenspeicher (abgeleitete Merkmale, kein Volltext), siehe
 * ReceiptIndexMapper.
 */
class Version000168Date20261101000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_receipt_index')) {
			$t = $schema->createTable('vbh_receipt_index');
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('size', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('mtime', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('parser_version', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('refs', Types::TEXT, ['notnull' => false]);
			$t->addColumn('name_refs', Types::TEXT, ['notnull' => false]);
			$t->addColumn('amounts', Types::TEXT, ['notnull' => false]);
			$t->addColumn('dates', Types::TEXT, ['notnull' => false]);
			$t->addColumn('invoice_no', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('total_cents', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('issue_date', Types::DATE, ['notnull' => false]);
			$t->addColumn('vendor', Types::STRING, ['notnull' => false, 'length' => 120]);
			$t->addColumn('indexed_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['file_id']);
		}

		return $schema;
	}
}
