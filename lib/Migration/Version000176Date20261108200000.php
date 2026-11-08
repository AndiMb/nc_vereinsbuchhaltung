<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Höchstens ein Einzugsposten je Forderung in einem lebenden Lauf (#301).
 *
 * Bisher prüfte das nur die Auswahl vor der Freigabe; zwei gleichzeitige
 * Freigaben konnten dieselbe Forderung in zwei Läufe aufnehmen. Die Spalte
 * `live_open_item_id` trägt die Forderung, solange der Lauf lebt, und ist
 * beim Verwerfen NULL – NULL zählt für den Unique-Index auf allen
 * unterstützten Datenbanken nicht.
 *
 * Bestehende Posten lebender Läufe übernimmt postSchemaChange(). Gibt es
 * aus der Zeit vor diesem Index schon eine Forderung in zwei lebenden
 * Läufen, trägt nur der älteste Posten den Wert – sonst scheiterte das
 * Update; die Doppelung selbst bleibt sichtbar und ist von Hand zu klären.
 */
class Version000176Date20261108200000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('vbh_debit_items')) {
			return null;
		}
		$table = $schema->getTable('vbh_debit_items');
		$changed = false;
		if (!$table->hasColumn('live_open_item_id')) {
			$table->addColumn('live_open_item_id', Types::BIGINT, ['notnull' => false]);
			$changed = true;
		}
		if (!$table->hasIndex('vbh_debit_items_live_claim')) {
			$table->addUniqueIndex(['live_open_item_id'], 'vbh_debit_items_live_claim');
			$changed = true;
		}
		return $changed ? $schema : null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('vbh_debit_items') || !$schema->hasTable('vbh_debit_batches')) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('i.id', 'i.open_item_id')
			->from('vbh_debit_items', 'i')
			->innerJoin('i', 'vbh_debit_batches', 'b', $qb->expr()->eq('i.batch_id', 'b.id'))
			->where($qb->expr()->neq('b.status', $qb->createNamedParameter('verworfen')))
			->andWhere($qb->expr()->isNull('i.live_open_item_id'))
			->orderBy('i.id');
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		$taken = $this->takenClaims();
		$duplicates = 0;
		$update = $this->db->getQueryBuilder();
		$update->update('vbh_debit_items')
			->set('live_open_item_id', $update->createParameter('claim'))
			->where($update->expr()->eq('id', $update->createParameter('id')));
		foreach ($rows as $row) {
			$claim = (int)$row['open_item_id'];
			if (isset($taken[$claim])) {
				$duplicates++;
				continue;
			}
			$taken[$claim] = true;
			$update->setParameter('claim', $claim, IQueryBuilder::PARAM_INT)
				->setParameter('id', (int)$row['id'], IQueryBuilder::PARAM_INT)
				->executeStatement();
		}
		if ($duplicates > 0) {
			$output->warning(sprintf('%d Einzugsposten stehen mit derselben Forderung in mehr als einem lebenden Lastschriftlauf (#301).', $duplicates));
		}
	}

	/** @return array<int, true> Forderungen, die schon einen Wert tragen (wiederholter Lauf der Migration) */
	private function takenClaims(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('live_open_item_id')
			->from('vbh_debit_items')
			->where($qb->expr()->isNotNull('live_open_item_id'));
		$result = $qb->executeQuery();
		$taken = [];
		while (($row = $result->fetch()) !== false) {
			$taken[(int)$row['live_open_item_id']] = true;
		}
		$result->closeCursor();
		return $taken;
	}
}
