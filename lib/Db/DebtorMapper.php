<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Debtor>
 */
class DebtorMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_debtors', Debtor::class);
	}

	public function find(int $id): Debtor {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Debitoren, optional gefiltert über Name, Zweitname, Ort, Kundennummer
	 * oder E-Mail (Teiltreffer, ohne Beachtung der Groß-/Kleinschreibung).
	 * Anonymisierte Datensätze erscheinen nicht.
	 *
	 * @return Debtor[]
	 */
	public function search(?string $query, int $limit = 200): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->isNull('redacted_at'))
			->orderBy('name', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults($limit);
		$query = $query !== null ? trim($query) : '';
		if ($query !== '') {
			$like = '%' . $this->db->escapeLikeParameter($query) . '%';
			$or = $qb->expr()->orX();
			foreach (['name', 'name2', 'city', 'customer_no', 'email'] as $column) {
				$or->add($qb->expr()->iLike($column, $qb->createNamedParameter($like)));
			}
			$qb->andWhere($or);
		}
		return $this->findEntities($qb);
	}

	public function findByMember(int $memberId): ?Debtor {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('member_id', $qb->createNamedParameter($memberId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('redacted_at'))
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/** @param list<int> $ids @return array<int, Debtor> */
	public function findByIds(array $ids): array {
		$found = [];
		foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from($this->getTableName())
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($this->findEntities($qb) as $debtor) {
				$found[(int)$debtor->getId()] = $debtor;
			}
		}
		return $found;
	}
}
