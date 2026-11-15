<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Feste Anteile der Importregeln (Issue #163).
 *
 * @extends QBMapper<RulePart>
 */
class RulePartMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_rule_parts', RulePart::class);
	}

	/**
	 * @param list<int> $ruleIds
	 * @return array<int, list<RulePart>> Regel-ID => Anteile in ihrer Reihenfolge
	 */
	public function findByRules(string $userId, array $ruleIds): array {
		if ($ruleIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('rule_id', $qb->createNamedParameter($ruleIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('rule_id', 'ASC')
			->addOrderBy('sort_order', 'ASC')
			->addOrderBy('id', 'ASC');
		$grouped = [];
		foreach ($this->findEntities($qb) as $part) {
			$grouped[(int)$part->getRuleId()][] = $part;
		}
		return $grouped;
	}

	/**
	 * Ersetzt die Anteile einer Regel. Der Aufrufer hält die Transaktion.
	 *
	 * @param list<RulePart> $parts
	 * @return list<RulePart> die gespeicherten Anteile
	 */
	public function replaceForRule(string $userId, int $ruleId, array $parts): array {
		$this->deleteByRule($userId, $ruleId);
		$saved = [];
		foreach (array_values($parts) as $i => $part) {
			$part->setUserId($userId);
			$part->setRuleId($ruleId);
			$part->setSortOrder($i);
			$saved[] = $this->insert($part);
		}
		return $saved;
	}

	public function deleteByRule(string $userId, int $ruleId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteAllForUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/** @return list<int> Regeln, von denen ein Anteil auf dieses Konto zeigt */
	public function ruleIdsByAccount(string $userId, int $accountId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('rule_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$ids = [];
		while (($id = $result->fetchOne()) !== false) {
			$ids[] = (int)$id;
		}
		$result->closeCursor();
		return $ids;
	}

	public function countByCostCenter(int $costCenterId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('cost_center_id', $qb->createNamedParameter($costCenterId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}
}
