<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Budget>
 */
class BudgetMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_budgets', Budget::class);
	}

	/**
	 * Planwerte (Cent) eines Geschäftsjahres je Konto.
	 *
	 * @return array<int, int> accountId => amountCents
	 */
	public function findByYear(string $userId, int $year): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('account_id', 'amount_cents')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)));
		$res = $qb->executeQuery();
		$out = [];
		while (($row = $res->fetch()) !== false) {
			$out[(int)$row['account_id']] = (int)$row['amount_cents'];
		}
		$res->closeCursor();
		return $out;
	}

	/**
	 * Setzt (oder aktualisiert) einen Planwert. Bei 0 wird der Eintrag entfernt.
	 */
	public function upsert(string $userId, int $accountId, int $year, int $amountCents): void {
		$existing = $this->findOne($userId, $accountId, $year);
		if ($amountCents === 0) {
			if ($existing !== null) {
				$this->delete($existing);
			}
			return;
		}
		if ($existing !== null) {
			$existing->setAmountCents($amountCents);
			$this->update($existing);
			return;
		}
		$budget = new Budget();
		$budget->setUserId($userId);
		$budget->setAccountId($accountId);
		$budget->setYear($year);
		$budget->setAmountCents($amountCents);
		$this->insert($budget);
	}

	private function findOne(string $userId, int $accountId, int $year): ?Budget {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Geschäftsjahre, für die Planwerte existieren – absteigend sortiert.
	 *
	 * @return int[]
	 */
	public function distinctYears(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('year')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$res = $qb->executeQuery();
		$years = [];
		while (($row = $res->fetch()) !== false) {
			$years[] = (int)$row['year'];
		}
		$res->closeCursor();
		rsort($years);
		return $years;
	}

	public function deleteAllForUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}
}
