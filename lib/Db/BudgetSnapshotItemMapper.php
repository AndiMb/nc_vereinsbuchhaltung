<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<BudgetSnapshotItem>
 */
class BudgetSnapshotItemMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_budget_snap_items', BudgetSnapshotItem::class);
	}

	/**
	 * Positionen eines Standes, nach Kontonummer sortiert.
	 *
	 * @return BudgetSnapshotItem[]
	 */
	public function findBySnapshot(int $snapshotId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('snapshot_id', $qb->createNamedParameter($snapshotId, IQueryBuilder::PARAM_INT)))
			->orderBy('account_number', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteBySnapshot(int $snapshotId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('snapshot_id', $qb->createNamedParameter($snapshotId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Löscht die Positionen mehrerer Stände auf einmal (Aufräumen bei Reset).
	 *
	 * @param int[] $snapshotIds
	 */
	public function deleteBySnapshotIds(array $snapshotIds): void {
		if ($snapshotIds === []) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->in(
				'snapshot_id',
				$qb->createNamedParameter($snapshotIds, IQueryBuilder::PARAM_INT_ARRAY)
			));
		$qb->executeStatement();
	}
}
