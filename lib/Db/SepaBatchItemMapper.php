<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<SepaBatchItem>
 */
class SepaBatchItemMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_sepa_batch_items', SepaBatchItem::class);
	}

	/** @return SepaBatchItem[] */
	public function findByBatch(int $batchId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('batch_id', $qb->createNamedParameter($batchId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Offene Posten, die bereits in einem noch nicht zurückgebuchten Einzug
	 * stecken.
	 *
	 * @return int[]
	 */
	public function findPendingOpenItemIds(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('open_item_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('pending')));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return array_map(static fn (array $row): int => (int)$row['open_item_id'], $rows);
	}

	public function findByEndToEndId(string $endToEndId): ?SepaBatchItem {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('end_to_end_id', $qb->createNamedParameter($endToEndId)))
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}
}
