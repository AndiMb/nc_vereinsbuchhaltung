<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Assignment>
 */
class AssignmentMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_assignments', Assignment::class);
	}

	/** @return Assignment[] */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('valid_from', 'DESC')
			->addOrderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	public function find(int $id): Assignment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return Assignment[] */
	public function findByMember(int $memberId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('member_id', $qb->createNamedParameter($memberId, IQueryBuilder::PARAM_INT)))
			->orderBy('valid_from', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Zuweisungen desselben Mitglieds zur selben Gruppe – Grundlage für die
	 * Überlappungsprüfung (AssignmentService::assertNoOverlap()).
	 *
	 * @return Assignment[]
	 */
	public function findByMemberAndGroup(int $memberId, int $groupId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('member_id', $qb->createNamedParameter($memberId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/**
	 * Zuweisungen eines Mitglieds, die über $asOf hinaus laufen (validTo NULL
	 * oder erst danach) – für den Austritts-Hook
	 * (AssignmentService::onMemberLeft()): auch eine Zuweisung mit bereits
	 * gesetztem, aber späterem validTo muss auf das frühere Austrittsdatum
	 * vorgezogen werden.
	 *
	 * @return Assignment[]
	 */
	public function findOpenAsOf(int $memberId, string $asOf): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('member_id', $qb->createNamedParameter($memberId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('valid_to'),
				$qb->expr()->gt('valid_to', $qb->createNamedParameter($asOf)),
			));
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

	/** Zuweisungen einer Gruppe, für die Untergrenzen-Vorschau/-Anhebung. @return Assignment[] */
	public function findByGroup(int $groupId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}
}
