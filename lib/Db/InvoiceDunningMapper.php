<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<InvoiceDunning>
 */
class InvoiceDunningMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_invoice_dunnings', InvoiceDunning::class);
	}

	public function find(int $id): InvoiceDunning {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return InvoiceDunning[] */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('sent_at', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return InvoiceDunning[] */
	public function findByInvoice(int $invoiceId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('invoice_id', $qb->createNamedParameter($invoiceId, IQueryBuilder::PARAM_INT)))
			->orderBy('level', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Höchste gemahnte Stufe je Rechnung.
	 *
	 * @param list<int> $invoiceIds
	 * @return array<int, array{level: int, sentAt: string}> Rechnungs-ID => letzte Mahnung
	 */
	public function lastByInvoices(array $invoiceIds): array {
		if ($invoiceIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('invoice_id', $qb->createNamedParameter($invoiceIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('id', 'ASC');
		$out = [];
		foreach ($this->findEntities($qb) as $row) {
			$id = $row->getInvoiceId();
			if (!isset($out[$id]) || $row->getLevel() >= $out[$id]['level']) {
				$out[$id] = ['level' => $row->getLevel(), 'sentAt' => $row->getSentAt()];
			}
		}
		return $out;
	}

	public function deleteAll(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName());
		$qb->executeStatement();
	}
}
