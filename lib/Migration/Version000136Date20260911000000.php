<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Wächter-Ordner für Belege: ein Beleg kann auf eine Datei im Nextcloud-
 * Dateibaum verweisen, statt dass die App sie unter berechnetem Pfad ablegt.
 *
 * file_id ist die Nextcloud-Dateikennung – sie überlebt Umbenennen und
 * Verschieben innerhalb des Nutzer-Homes, ein Pfad täte das nicht. file_owner
 * ist der Nutzer, in dessen Home die Datei liegt: nur über sein Home lässt sich
 * die Kennung wieder in einen Knoten auflösen, und die Einstellung „welcher
 * Nutzer" kann sich später ändern, ohne dass alte Verweise brechen sollen.
 *
 * Beide Spalten bleiben leer für Belege, die die App selbst unter
 * <Ablage>/<BuchungsID>/ bzw. in AppData angelegt hat.
 */
class Version000136Date20260911000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_attachments')) {
			return null;
		}

		$table = $schema->getTable('vbh_attachments');
		if ($table->hasColumn('file_id')) {
			return null;
		}

		$table->addColumn('file_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
		$table->addColumn('file_owner', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addIndex(['file_id'], 'vbh_attach_file_idx');

		return $schema;
	}
}
