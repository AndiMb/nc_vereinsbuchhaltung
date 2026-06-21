<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<JournalLine>
 */
class JournalLineMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_journal_line', JournalLine::class);
	}

	/**
	 * @return JournalLine[]
	 */
	public function findByJournal(int $journalId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('journal_id', $qb->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	public function deleteByJournal(int $journalId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('journal_id', $qb->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Summen Soll/Haben je Konto für die Saldenliste.
	 *
	 * @return array<int, array{debit:int, credit:int}>
	 */
	public function sumByAccount(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('l.account_id')
			->selectAlias($qb->func()->sum('l.debit_cents'), 'debit')
			->selectAlias($qb->func()->sum('l.credit_cents'), 'credit')
			->from($this->getTableName(), 'l')
			->innerJoin('l', 'vbh_journal', 'j', $qb->expr()->eq('l.journal_id', 'j.id'))
			->where($qb->expr()->eq('j.user_id', $qb->createNamedParameter($userId)))
			->groupBy('l.account_id');
		$result = $qb->executeQuery();
		$out = [];
		while (($row = $result->fetch()) !== false) {
			$out[(int)$row['account_id']] = [
				'debit' => (int)$row['debit'],
				'credit' => (int)$row['credit'],
			];
		}
		$result->closeCursor();
		return $out;
	}
}
