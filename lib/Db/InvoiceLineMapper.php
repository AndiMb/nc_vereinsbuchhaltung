<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<InvoiceLine>
 */
class InvoiceLineMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_invoice_lines', InvoiceLine::class);
	}

	/** @return InvoiceLine[] in Positionsreihenfolge */
	public function findByInvoice(int $invoiceId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('invoice_id', $qb->createNamedParameter($invoiceId, IQueryBuilder::PARAM_INT)))
			->orderBy('position', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteByInvoice(int $invoiceId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('invoice_id', $qb->createNamedParameter($invoiceId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/** Rechnungszeilen, die diese Kostenstelle tragen (Issue #332) – solange es welche gibt, bleibt sie. */
	public function countByBookingCostCenter(int $costCenterId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('booking_cc_id', $qb->createNamedParameter($costCenterId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/** Löscht alle Zeilen. Gedacht für den Reset des Buchungsbestands. */
	public function deleteAll(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName());
		$qb->executeStatement();
	}
}
