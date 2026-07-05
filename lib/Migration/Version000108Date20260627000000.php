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
 * Ändert uploaded_at von VARCHAR zu DATETIME für korrekte QBMapper-Typkonvertierung.
 */
class Version000108Date20260627000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('vbh_attachments')) {
			$table = $schema->getTable('vbh_attachments');
			if ($table->hasColumn('uploaded_at')) {
				$table->changeColumn('uploaded_at', [
					'type'    => Type::getType(Types::DATETIME),
					'notnull' => true,
					'length'  => null,
				]);
			}
		}

		return $schema;
	}
}
