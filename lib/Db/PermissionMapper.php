<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Permission>
 */
class PermissionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_permissions', Permission::class);
	}

	public function find(int $id): Permission {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return Permission[]
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('principal_type', 'ASC')->addOrderBy('principal_id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Rollen-Zuweisungen, die auf den Nutzer (direkt) oder seine Gruppen passen.
	 *
	 * @param string[] $groupIds
	 * @return Permission[]
	 */
	public function findMatching(string $uid, array $groupIds): array {
		$qb = $this->db->getQueryBuilder();
		$or = $qb->expr()->orX(
			$qb->expr()->andX(
				$qb->expr()->eq('principal_type', $qb->createNamedParameter('user')),
				$qb->expr()->eq('principal_id', $qb->createNamedParameter($uid)),
			),
		);
		if (count($groupIds) > 0) {
			$or->add($qb->expr()->andX(
				$qb->expr()->eq('principal_type', $qb->createNamedParameter('group')),
				$qb->expr()->in('principal_id', $qb->createNamedParameter($groupIds, IQueryBuilder::PARAM_STR_ARRAY)),
			));
		}
		$qb->select('*')->from($this->getTableName())->where($or);
		return $this->findEntities($qb);
	}

	public function upsert(string $type, string $id, string $role): Permission {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('principal_type', $qb->createNamedParameter($type)))
			->andWhere($qb->expr()->eq('principal_id', $qb->createNamedParameter($id)))
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		if (count($rows) > 0) {
			$p = $rows[0];
			$p->setRole($role);
			return $this->update($p);
		}
		$p = new Permission();
		$p->setPrincipalType($type);
		$p->setPrincipalId($id);
		$p->setRole($role);
		return $this->insert($p);
	}
}
