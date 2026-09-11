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
	 * Planwerte (Cent) und Notizen eines Geschäftsjahres je Konto.
	 *
	 * @return array<int, array{amount: int, note: string}> accountId => Planwert
	 */
	public function findByPeriod(string $userId, int $periodId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('account_id', 'amount_cents', 'note')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)));
		$res = $qb->executeQuery();
		$out = [];
		while (($row = $res->fetch()) !== false) {
			$out[(int)$row['account_id']] = [
				'amount' => (int)$row['amount_cents'],
				'note' => (string)($row['note'] ?? ''),
			];
		}
		$res->closeCursor();
		return $out;
	}

	/**
	 * Setzt (oder aktualisiert) einen Planwert samt Notiz. Sind Betrag UND Notiz
	 * leer, wird der Eintrag entfernt (eine Notiz allein hält ihn am Leben,
	 * z. B. „bewusst 0 geplant, weil …").
	 */
	public function upsert(string $userId, int $accountId, int $periodId, int $amountCents, string $note = ''): void {
		$existing = $this->findOne($userId, $accountId, $periodId);
		if ($amountCents === 0 && $note === '') {
			if ($existing !== null) {
				$this->delete($existing);
			}
			return;
		}
		if ($existing !== null) {
			$existing->setAmountCents($amountCents);
			$existing->setNote($note);
			$this->update($existing);
			return;
		}
		$budget = new Budget();
		$budget->setUserId($userId);
		$budget->setAccountId($accountId);
		$budget->setPeriodId($periodId);
		$budget->setAmountCents($amountCents);
		$budget->setNote($note);
		$this->insert($budget);
	}

	private function findOne(string $userId, int $accountId, int $periodId): ?Budget {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** Anzahl Planwerte in einem Geschäftsjahr. */
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
	 * Konten, für die in einem Geschäftsjahr ein Planwert existiert.
	 *
	 * Der PeriodService braucht das beim Umstellen der Regel: verschmelzen
	 * zwei Zeiträume, kann ein Konto nur einen der beiden Planwerte behalten.
	 *
	 * @return int[]
	 */
	public function findAccountIdsForPeriod(string $userId, int $periodId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('account_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)));
		$res = $qb->executeQuery();
		$ids = [];
		while (($row = $res->fetch()) !== false) {
			$ids[] = (int)$row['account_id'];
		}
		$res->closeCursor();
		return $ids;
	}

	/** Hängt alle Planwerte eines Geschäftsjahres an ein anderes um. */
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
	 * Entfernt die Planwerte bestimmter Konten in einem Geschäftsjahr.
	 * Gebraucht beim Verschmelzen zweier Zeiträume, siehe movePeriod().
	 *
	 * @param int[] $accountIds
	 */
	public function deleteByPeriodAndAccounts(string $userId, int $periodId, array $accountIds): void {
		if ($accountIds === []) {
			return;
		}
		foreach (array_chunk(array_values(array_unique($accountIds)), 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->in('account_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
	}

	public function deleteAllForUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/** Entfernt die Planwerte eines gelöschten Kontos (sonst verwaiste Zeilen). */
	public function deleteByAccount(string $userId, int $accountId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Anzahl Planwerte je Geschäftsjahr, in einer Abfrage.
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
