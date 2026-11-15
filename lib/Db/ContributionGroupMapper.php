<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<ContributionGroup>
 */
class ContributionGroupMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_contribution_groups', ContributionGroup::class);
	}

	/** @return ContributionGroup[] */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('name', 'ASC');
		return $this->findEntities($qb);
	}

	public function find(int $id): ContributionGroup {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** Beitragsgruppen, die diese Kostenstelle als Vorgabe tragen (Issue #333). */
	public function countByBookingCostCenter(int $costCenterId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('booking_cc_id', $qb->createNamedParameter($costCenterId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * Löst die Vorgabe-Kostenstelle aller Gruppen. Gedacht für den Reset des
	 * Buchungsbestands: die Gruppen bleiben, die Kostenstellen verschwinden.
	 */
	public function clearBookingCostCenters(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('booking_cc_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL));
		$qb->executeStatement();
	}
}
