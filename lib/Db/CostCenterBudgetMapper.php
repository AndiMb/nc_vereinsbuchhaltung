<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Budget je Kostenstelle (Issue #335). Die Tabelle trägt keinen Bestand:
 * Kostenstelle und Geschäftsjahr gehören beide schon zu einem.
 *
 * @extends QBMapper<CostCenterBudget>
 */
class CostCenterBudgetMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_cc_budgets', CostCenterBudget::class);
	}

	/**
	 * Planwerte eines Geschäftsjahres.
	 *
	 * @return array<int, array{incomeCents:int, expenseCents:int}> Kostenstelle => Planwerte
	 */
	public function findByPeriod(int $periodId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('cost_center_id', 'income_cents', 'expense_cents')
			->from($this->getTableName())
			->where($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$out = [];
		while (($row = $result->fetch()) !== false) {
			$out[(int)$row['cost_center_id']] = [
				'incomeCents' => (int)$row['income_cents'],
				'expenseCents' => (int)$row['expense_cents'],
			];
		}
		$result->closeCursor();
		return $out;
	}

	/** Setzt die Planwerte; sind beide 0, gibt es keinen Plan und die Zeile verschwindet. */
	public function upsert(int $costCenterId, int $periodId, int $incomeCents, int $expenseCents): void {
		$existing = $this->findOne($costCenterId, $periodId);
		if ($incomeCents === 0 && $expenseCents === 0) {
			if ($existing !== null) {
				$this->delete($existing);
			}
			return;
		}
		if ($existing !== null) {
			$existing->setIncomeCents($incomeCents);
			$existing->setExpenseCents($expenseCents);
			$this->update($existing);
			return;
		}
		$budget = new CostCenterBudget();
		$budget->setCostCenterId($costCenterId);
		$budget->setPeriodId($periodId);
		$budget->setIncomeCents($incomeCents);
		$budget->setExpenseCents($expenseCents);
		$this->insert($budget);
	}

	private function findOne(int $costCenterId, int $periodId): ?CostCenterBudget {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('cost_center_id', $qb->createNamedParameter($costCenterId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** Mit der Kostenstelle gehen ihre Planwerte. */
	public function deleteByCostCenter(int $costCenterId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('cost_center_id', $qb->createNamedParameter($costCenterId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/** Beim Zurücksetzen des Buchungsbestands (siehe ResetService): Kostenstellen und Geschäftsjahre sind weg. */
	public function deleteAll(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName());
		$qb->executeStatement();
	}
}
