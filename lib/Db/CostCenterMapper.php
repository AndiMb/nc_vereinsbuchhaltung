<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<CostCenter>
 */
class CostCenterMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_costcenters', CostCenter::class);
	}

	/**
	 * @return CostCenter[]
	 */
	public function findAll(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('code', 'ASC');
		return $this->findEntities($qb);
	}

	public function findByCode(string $userId, string $code): ?CostCenter {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('code', $qb->createNamedParameter($code)))
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	public function upsert(string $userId, string $code, string $name): CostCenter {
		$existing = $this->findByCode($userId, $code);
		if ($existing !== null) {
			$existing->setName(mb_substr($name, 0, 255));
			return $this->update($existing);
		}
		$cc = new CostCenter();
		$cc->setUserId($userId);
		$cc->setCode(mb_substr($code, 0, 8));
		$cc->setName(mb_substr($name, 0, 255));
		return $this->insert($cc);
	}

	public function deleteAllForUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}
}
