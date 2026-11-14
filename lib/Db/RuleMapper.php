<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCA\Vereinsbuchhaltung\Service\FieldCipher;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends EncryptingMapper<Rule>
 */
class RuleMapper extends EncryptingMapper {
	public function __construct(
		IDBConnection $db,
		FieldCipher $cipher,
		private RulePartMapper $parts,
	) {
		parent::__construct($db, 'vbh_rules', Rule::class, $cipher);
	}

	/**
	 * Hängt jeder Regel ihre festen Anteile an (Issue #163).
	 *
	 * @param Rule[] $rules
	 * @return Rule[]
	 */
	private function withParts(string $userId, array $rules): array {
		$ids = array_map(static fn (Rule $r): int => (int)$r->getId(), $rules);
		$byRule = $this->parts->findByRules($userId, array_values($ids));
		foreach ($rules as $rule) {
			$rule->setPartsList($byRule[(int)$rule->getId()] ?? []);
		}
		return $rules;
	}

	/**
	 * Der Suchwert jeder Regel, nicht nur von IBAN-Regeln: Namen sind ebenso
	 * personenbezogen, und hinge die Verschlüsselung am Suchfeld, bliebe eine
	 * nachträglich zur IBAN-Regel gemachte im Klartext stehen.
	 */
	protected function encryptedProperties(): array {
		return ['matchValue'];
	}

	public function find(int $id, string $userId): Rule {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->withParts($userId, [$this->findEntity($qb)])[0];
	}

	public function deleteAllForUser(string $userId): void {
		$this->parts->deleteAllForUser($userId);
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/**
	 * Entfernt alle Regeln, die auf ein Konto zeigen – als Gegenkonto oder in
	 * einem festen Anteil (Issue #163) – samt ihren Anteilen. Wird beim
	 * Löschen des Kontos aufgerufen: eine Regel mit totem Konto würde beim
	 * nächsten Import stillschweigend ins Leere laufen, eine Regel ohne einen
	 * ihrer Anteile still falsch aufteilen.
	 */
	public function deleteByAccount(string $userId, int $accountId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('contra_account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$ids = $this->parts->ruleIdsByAccount($userId, $accountId);
		while (($id = $result->fetchOne()) !== false) {
			$ids[] = (int)$id;
		}
		$result->closeCursor();
		$ids = array_values(array_unique($ids));
		if ($ids === []) {
			return;
		}
		foreach ($ids as $id) {
			$this->parts->deleteByRule($userId, $id);
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		$qb->executeStatement();
	}

	/** Regeln und Regel-Anteile, die diese Kostenstelle vorgeben (Issue #331, #163) – solange es welche gibt, bleibt sie. */
	public function countByCostCenter(int $costCenterId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('cost_center_id', $qb->createNamedParameter($costCenterId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		// Auch ein fester Anteil (Issue #163) hält die Kostenstelle.
		return $count + $this->parts->countByCostCenter($costCenterId);
	}

	/**
	 * @return Rule[]
	 */
	public function findAll(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('priority', 'DESC')
			->addOrderBy('id', 'ASC');
		return $this->withParts($userId, $this->findEntities($qb));
	}
}
