<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<BankTransaction>
 */
class BankTransactionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_bank_tx', BankTransaction::class);
	}

	public function find(int $id, string $userId): BankTransaction {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	/**
	 * @param string[] $hashes
	 * @return string[] vorhandene Hashes
	 */
	public function findExistingHashes(string $userId, array $hashes): array {
		if (count($hashes) === 0) {
			return [];
		}
		$found = [];
		// in Blöcken abfragen, um Parameter-Limits zu vermeiden
		foreach (array_chunk($hashes, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('hash')
				->from($this->getTableName())
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->in('hash', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
			$result = $qb->executeQuery();
			while (($row = $result->fetch()) !== false) {
				$found[] = $row['hash'];
			}
			$result->closeCursor();
		}
		return $found;
	}

	/**
	 * @return BankTransaction[]
	 */
	public function findFiltered(string $userId, ?string $status = null, int $limit = 500, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		if ($status !== null && $status !== '') {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}
		$qb->orderBy('booking_date', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		return $this->findEntities($qb);
	}
}
