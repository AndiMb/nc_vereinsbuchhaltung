<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Prüfsummen der Dateien im Wächter-Ordner für die Dublettensuche – ein
 * reiner Zwischenspeicher, siehe ReceiptHashMapper.
 */
class Version000167Date20261029000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_receipt_hashes')) {
			$t = $schema->createTable('vbh_receipt_hashes');
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('size', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('mtime', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->setPrimaryKey(['file_id']);
		}

		return $schema;
	}
}
