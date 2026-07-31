<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Account>
 */
class AccountMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_accounts', Account::class);
	}

	/**
	 * @return Account[]
	 */
	public function findAll(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('number', 'ASC');
		return $this->findEntities($qb);
	}

	public function find(int $id, string $userId): Account {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	public function findByNumber(string $userId, string $number): ?Account {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('number', $qb->createNamedParameter($number)))
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/** Anzahl direkter Unterkonten – ein Konto mit Unterkonten darf nicht weg. */
	public function countChildren(string $userId, int $parentId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('parent_id', $qb->createNamedParameter($parentId, IQueryBuilder::PARAM_INT)));
		$res = $qb->executeQuery();
		$count = (int)$res->fetchOne();
		$res->closeCursor();
		return $count;
	}

	/**
	 * Löst die Zuordnung aller Konten zu einer Kostenstelle.
	 *
	 * Es gibt keine Fremdschlüssel im Schema: ohne dieses Aufräumen zeigten
	 * die Konten einer gelöschten Kostenstelle auf eine nicht mehr vorhandene
	 * ID und fielen im Bericht stillschweigend unter „ohne Kostenstelle",
	 * ließen sich dort aber nicht mehr neu zuordnen.
	 *
	 * @return int Anzahl betroffener Konten
	 */
	public function clearCostCenter(string $userId, int $costCenterId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('cost_center_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('cost_center_id', $qb->createNamedParameter($costCenterId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	public function deleteAllForUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	public function countForUser(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}
}
