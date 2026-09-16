<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<DebitItem>
 */
class DebitItemMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_debit_items', DebitItem::class);
	}

	public function find(int $id): DebitItem {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return DebitItem[] Einfügereihenfolge, damit Vorschau/pain.008 deterministisch bleiben */
	public function findByBatch(int $batchId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('batch_id', $qb->createNamedParameter($batchId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return DebitItem[] */
	public function findByMandate(int $mandateId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('mandate_id', $qb->createNamedParameter($mandateId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Die `open_item_id`s aller Forderungen, die gerade in einem lebenden
	 * (`freigegeben`/`eingereicht`, also nicht `verworfen`) Lauf stecken – eine
	 * Forderung hat laut Spec §2.2 „höchstens einen Einzugsposten", diese
	 * Abfrage ist die Durchsetzung davon:
	 * {@see \OCA\Vereinsbuchhaltung\Service\DebitBatchService::release()}
	 * schließt sie von einem neuen Lauf aus, ein `verworfen`er Lauf gibt seine
	 * Forderungen automatisch wieder frei, einfach weil sein Status hier nicht
	 * mehr mitzählt (keine Änderung an `vbh_open_items` nötig).
	 *
	 * @return list<int>
	 */
	public function findOpenItemIdsInLiveBatches(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('i.open_item_id')
			->from($this->getTableName(), 'i')
			->innerJoin('i', 'vbh_debit_batches', 'b', $qb->expr()->eq('i.batch_id', 'b.id'))
			->where($qb->expr()->neq('b.status', $qb->createNamedParameter(DebitBatch::STATUS_DISCARDED)));
		$result = $qb->executeQuery();
		$ids = [];
		while (($row = $result->fetch()) !== false) {
			$ids[] = (int)$row['open_item_id'];
		}
		$result->closeCursor();
		return $ids;
	}
}
