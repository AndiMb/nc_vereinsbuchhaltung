<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Member>
 */
class MemberMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_members', Member::class);
	}

	/** @return Member[] */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('last_name', 'ASC')
			->addOrderBy('organization_name', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function find(int $id): Member {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	public function findByNcUserId(string $ncUserId): ?Member {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('nc_user_id', $qb->createNamedParameter($ncUserId)))
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	public function findByMemberNumber(string $memberNumber): ?Member {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('member_number', $qb->createNamedParameter($memberNumber)))
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	public function count(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName());
		$res = $qb->executeQuery();
		$count = (int)$res->fetchOne();
		$res->closeCursor();
		return $count;
	}

	public function findOrNull(int $id): ?Member {
		try {
			return $this->find($id);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Anzeigename eines Mitglieds oder ein Fallback-Text, falls die member_id
	 * leer ist oder das Mitglied inzwischen gelöscht wurde. Zentraler Helfer
	 * für SepaMandateService/MembershipFeeService/SepaBatchService und ihre
	 * Controller, die alle denselben Namen zu einem Mandat/Beitrag anzeigen –
	 * vorher fand sich an sieben Stellen dieselbe find()/catch-Konstruktion.
	 */
	public function displayNameOr(?int $memberId, string $fallback): string {
		if ($memberId === null) {
			return $fallback;
		}
		return $this->findOrNull($memberId)?->displayName() ?? $fallback;
	}
}
