<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<BudgetSnapshot>
 */
class BudgetSnapshotMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_budget_snapshots', BudgetSnapshot::class);
	}

	/**
	 * Stände eines Geschäftsjahres, neueste zuerst.
	 *
	 * @return BudgetSnapshot[]
	 */
	public function findByPeriod(string $userId, int $periodId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/** Anzahl Plan-Stände in einem Geschäftsjahr. */
	public function countByPeriod(string $userId, int $periodId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('id'), 'c')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)));
		$res = $qb->executeQuery();
		$count = (int)$res->fetchOne();
		$res->closeCursor();
		return $count;
	}

	/**
	 * Hängt alle Plan-Stände eines Geschäftsjahres an ein anderes um.
	 *
	 * Anders als bei den Planwerten kann es dabei keine Kollision geben:
	 * mehrere Stände je Zeitraum sind ausdrücklich vorgesehen.
	 */
	public function movePeriod(string $userId, int $fromPeriodId, int $toPeriodId): void {
		if ($fromPeriodId === $toPeriodId) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('period_id', $qb->createNamedParameter($toPeriodId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('period_id', $qb->createNamedParameter($fromPeriodId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Ein Stand des Nutzers – oder null, wenn nicht vorhanden/fremd.
	 */
	public function findForUser(string $userId, int $id): ?BudgetSnapshot {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Alle Stände eines Nutzers (zeitraumübergreifend) – für das Aufräumen.
	 *
	 * @return BudgetSnapshot[]
	 */
	public function findAllForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntities($qb);
	}

	public function deleteAllForUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/**
	 * Anzahl Plan-Stände je Geschäftsjahr, in einer Abfrage.
	 *
	 * Die Zeitraum-Liste braucht diese Zahlen für jede Periode; einzeln
	 * abgefragt wären das zwei Abfragen je Zeitraum bei jedem Seitenaufbau.
	 *
	 * @return array<int, int> periodId => Anzahl
	 */
	public function countsByPeriod(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('period_id')
			->selectAlias($qb->func()->count('id'), 'c')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->groupBy('period_id');
		$res = $qb->executeQuery();
		$out = [];
		while (($row = $res->fetch()) !== false) {
			$out[(int)$row['period_id']] = (int)$row['c'];
		}
		$res->closeCursor();
		return $out;
	}
}
