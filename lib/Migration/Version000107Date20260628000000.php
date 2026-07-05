<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Belegablage: Dateianhänge zu Buchungssätzen.
 */
class Version000107Date20260628000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_attachments')) {
			$table = $schema->createTable('vbh_attachments');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('journal_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('file_name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('mime_type', Types::STRING, ['notnull' => true, 'length' => 128, 'default' => 'application/octet-stream']);
			$table->addColumn('file_size', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('uploaded_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['journal_id'], 'vbh_attach_journal_idx');
			$table->addIndex(['user_id'], 'vbh_attach_user_idx');
		}

		return $schema;
	}
}
