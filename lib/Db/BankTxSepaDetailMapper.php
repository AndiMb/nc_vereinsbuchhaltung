<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<BankTxSepaDetail>
 */
class BankTxSepaDetailMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_bank_tx_sepa_details', BankTxSepaDetail::class);
	}

	public function find(int $id): BankTxSepaDetail {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return BankTxSepaDetail[] Reihenfolge wie extrahiert (detail_index) */
	public function findByBankTx(int $bankTxId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('bank_tx_id', $qb->createNamedParameter($bankTxId, IQueryBuilder::PARAM_INT)))
			->orderBy('detail_index', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return BankTxSepaDetail[] noch nicht beurteilte Detail-Zeilen aller Bankumsätze */
	public function findOpen(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(BankTxSepaDetail::STATUS_OPEN)))
			->orderBy('bank_tx_id', 'ASC')
			->addOrderBy('detail_index', 'ASC');
		return $this->findEntities($qb);
	}

	public function findByEndToEndId(string $endToEndId): ?BankTxSepaDetail {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('end_to_end_id', $qb->createNamedParameter($endToEndId)));
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException|\OCP\AppFramework\Db\MultipleObjectsReturnedException) {
			return null;
		}
	}
}
