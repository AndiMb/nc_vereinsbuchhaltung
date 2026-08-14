<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<MembershipFee>
 */
class MembershipFeeMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_membership_fees', MembershipFee::class);
	}

	/** @return MembershipFee[] */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('active', 'DESC')
			->addOrderBy('next_due_date', 'ASC');
		return $this->findEntities($qb);
	}

	public function find(int $id): MembershipFee {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Beiträge, die auf ein Mandat verweisen – gebraucht, um zu entscheiden,
	 * ob sich ein Mandat noch löschen lässt (SepaMandateService::delete()).
	 *
	 * @return MembershipFee[]
	 */
	public function findByMandate(int $mandateId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('mandate_id', $qb->createNamedParameter($mandateId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/**
	 * Aktive Beiträge desselben Zahlers.
	 *
	 * Gebraucht vom Mitglieder-Import: wird dieselbe Liste ein zweites Mal
	 * eingelesen – der klassische „hat es geklappt?"-Reflex –, bekäme sonst
	 * jeder Zahler ohne IBAN einen zweiten Beitrag. Bei einer IBAN fällt das
	 * über das Mandat auf, ohne IBAN gäbe es keinerlei Anhaltspunkt, und der
	 * Verein forderte fortan doppelt.
	 *
	 * @return MembershipFee[]
	 */
	public function findActiveByMember(?string $memberUid, ?string $memberLabel): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		$qb->andWhere($memberUid !== null
			? $qb->expr()->eq('member_uid', $qb->createNamedParameter($memberUid))
			: $qb->expr()->eq('member_label', $qb->createNamedParameter((string)$memberLabel)));
		return $this->findEntities($qb);
	}

	/**
	 * Aktive Beiträge, deren nächste Fälligkeit erreicht oder überschritten ist.
	 * Genutzt vom {@see \OCA\Vereinsbuchhaltung\BackgroundJob\MembershipFeeDueJob}.
	 *
	 * @return MembershipFee[]
	 */
	public function findDueBy(string $date): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->lte('next_due_date', $qb->createNamedParameter($date)));
		return $this->findEntities($qb);
	}

	/** Anzahl aller Beitraege – siehe {@see SepaMandateMapper::count()}. */
	public function count(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName());
		$res = $qb->executeQuery();
		$count = (int)$res->fetchOne();
		$res->closeCursor();
		return $count;
	}
}
