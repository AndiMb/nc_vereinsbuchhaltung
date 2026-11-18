<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<DonationReceipt>
 */
class DonationReceiptMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_donation_receipts', DonationReceipt::class);
	}

	public function find(int $id): DonationReceipt {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return list<DonationReceipt> neueste zuerst */
	public function findByMember(int $memberId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('member_id', $qb->createNamedParameter($memberId, IQueryBuilder::PARAM_INT)))
			->orderBy('receipt_year', 'DESC')
			->addOrderBy('id', 'DESC');
		return array_values($this->findEntities($qb));
	}

	/** @return list<DonationReceipt> neueste zuerst */
	public function findByDebtor(int $debtorId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('debtor_id', $qb->createNamedParameter($debtorId, IQueryBuilder::PARAM_INT)))
			->orderBy('receipt_year', 'DESC')
			->addOrderBy('id', 'DESC');
		return array_values($this->findEntities($qb));
	}

	/** @return list<DonationReceipt> eines Jahres, neueste zuerst */
	public function findByYear(int $year): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('receipt_year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC');
		return array_values($this->findEntities($qb));
	}

	/** @return list<DonationReceipt> */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
		return array_values($this->findEntities($qb));
	}
}
