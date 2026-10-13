<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Kommentar an Buchungen: ein freier Hinweis, etwa für eine spätere Prüfung
 * oder die Kassenprüfung.
 *
 * Der Kommentar gehört nicht zum Buchungssatz und bleibt deshalb auch in
 * einem abgeschlossenen Geschäftsjahr änderbar (siehe
 * {@see \OCA\Vereinsbuchhaltung\Service\JournalService::updateComment()}).
 * NULL heißt „kein Kommentar"; ein leerer Text wird nie gespeichert.
 */
class Version000160Date20261013000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('vbh_journal');
		if ($table->hasColumn('comment')) {
			return null;
		}
		$table->addColumn('comment', Types::TEXT, ['notnull' => false]);

		return $schema;
	}
}
