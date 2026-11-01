<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Sammelrechnungen (#243, docs/superpowers/specs/2026-11-01-sammelrechnungen-design.md):
 * Rechnungsstapel („Projekte“) und additiv `vbh_invoices.batch_id`, der
 * Verweis einer aus einer Tabelle angelegten Rechnung auf ihren Stapel.
 */
class Version000170Date20261101120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_invoice_batches')) {
			$t = $schema->createTable('vbh_invoice_batches');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('source_filename', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->setPrimaryKey(['id']);
		}

		if ($schema->hasTable('vbh_invoices')) {
			$t = $schema->getTable('vbh_invoices');
			if (!$t->hasColumn('batch_id')) {
				$t->addColumn('batch_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
				$t->addIndex(['batch_id'], 'vbh_invoice_batch');
			}
		}

		return $schema;
	}
}
