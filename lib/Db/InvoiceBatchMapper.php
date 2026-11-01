<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<InvoiceBatch>
 */
class InvoiceBatchMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_invoice_batches', InvoiceBatch::class);
	}

	public function find(int $id): InvoiceBatch {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return InvoiceBatch[] neueste zuerst */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	public function deleteAll(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName());
		$qb->executeStatement();
	}
}
