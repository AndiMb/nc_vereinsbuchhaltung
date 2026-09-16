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

	/**
	 * Kandidaten für eine NC-Kontoverknüpfung: alle Mitglieder mit genau
	 * dieser Mailadresse (case-insensitiv, Familienadressen sind Normalfall,
	 * siehe Spec §2.2). Nicht zu verwechseln mit der Verknüpfungssuche selbst
	 * (die sucht andersherum: NC-Konten zu einer Mitglieds-Mailadresse, siehe
	 * MemberService::findLinkSuggestions()).
	 *
	 * @return Member[]
	 */
	public function findByEmail(string $email): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq($qb->func()->lower('email'), $qb->createNamedParameter(mb_strtolower($email))));
		return $this->findEntities($qb);
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

	/**
	 * @throws DoesNotExistException wenn es den Datensatz nicht (mehr) gibt
	 */
	public function findOrNull(int $id): ?Member {
		try {
			return $this->find($id);
		} catch (DoesNotExistException) {
			return null;
		}
	}
}
