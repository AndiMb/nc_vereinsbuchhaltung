<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Mahnwesen (Spec §2.2 „Mahnversand (Dunning Notice)"/§3.6/§5, Issue #73):
 * `vbh_dunning_notices` ist die EINZIGE Mahnwesen-Persistenz – „eine Zeile je
 * (Forderung, Stufe) mit Versandzeitpunkt + Batch-Referenz der Mail". Alles
 * andere (Rückgabe-Klasse, "aktuell gestundet", welche Stufe als nächstes
 * fällig ist) ist abgeleitete Abfrage, siehe
 * {@see \OCA\Vereinsbuchhaltung\Service\DunningLadderService}.
 *
 * Der Unique-Index auf (`open_item_id`,`stage`) ist der Idempotenz-Guard:
 * dieselbe Forderung darf dieselbe Mahnstufe nie zweimal bekommen, egal ob
 * durch einen doppelten Cron-Lauf oder einen erneuten ereignisgetriebenen
 * Trigger (Rücklastschrift + Widerruf könnten sonst beide dieselbe Stufe 0
 * auslösen wollen).
 */
class Version000145Date20260917020000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_dunning_notices')) {
			$table = $schema->createTable('vbh_dunning_notices');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('open_item_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			// 0 Zahlungsaufforderung | 1 Zahlungserinnerung | 2 Mahnung (DunningNotice::STAGE_*).
			$table->addColumn('stage', Types::SMALLINT, ['notnull' => true]);
			$table->addColumn('sent_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			// Gruppiert alle Zeilen derselben gebuendelten Mail (Spec "gebuendelt
			// je Mitglied") - kein FK, nur ein gemeinsamer Korrelationswert.
			$table->addColumn('mail_batch_reference', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['open_item_id'], 'vbh_dunning_openitem');
			// Idempotenz-Guard (Klassendoc): dieselbe Forderung bekommt dieselbe
			// Mahnstufe nie zweimal.
			$table->addUniqueIndex(['open_item_id', 'stage'], 'vbh_dunning_item_stage');
		}

		return $schema;
	}
}
