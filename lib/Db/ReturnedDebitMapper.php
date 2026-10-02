<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<ReturnedDebit>
 */
class ReturnedDebitMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_returned_debits', ReturnedDebit::class);
	}

	public function find(int $id): ReturnedDebit {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Für den Posten-Guard (Spec §3.6/§5 „max. 1 Rücklastschrift je
	 * Einzugsposten") – vor dem Anlegen prüfen, damit eine Dublette still
	 * verpuffen kann, statt gegen den Unique-Index zu laufen.
	 */
	public function findByDebitItem(int $debitItemId): ?ReturnedDebit {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('debit_item_id', $qb->createNamedParameter($debitItemId, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return null;
		}
	}
}
