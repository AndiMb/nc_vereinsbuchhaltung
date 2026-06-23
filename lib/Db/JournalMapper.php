<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Journal>
 */
class JournalMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_journal', Journal::class);
	}

	public function find(int $id, string $userId): Journal {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	public const OPENING_REF = 'EB';

	public function deleteAllForUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/**
	 * Nächste fortlaufende Buchungsnummer für den Nutzer.
	 */
	public function getNextEntryNo(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->max('entry_no'), 'm')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$res = $qb->executeQuery();
		$max = $res->fetchOne();
		$res->closeCursor();
		return (int)$max + 1;
	}

	/**
	 * Findet die Eröffnungsbuchung (document_ref = 'EB'), die das angegebene
	 * Konto berührt – falls vorhanden.
	 */
	public function findOpeningForAccount(string $userId, int $accountId): ?Journal {
		$qb = $this->db->getQueryBuilder();
		$qb->select('j.*')
			->from($this->getTableName(), 'j')
			->innerJoin('j', 'vbh_journal_line', 'l', $qb->expr()->eq('l.journal_id', 'j.id'))
			->where($qb->expr()->eq('j.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('j.document_ref', $qb->createNamedParameter(self::OPENING_REF)))
			->andWhere($qb->expr()->eq('l.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/**
	 * @return Journal[]
	 */
	public function findAll(string $userId, int $limit = 500, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('date', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		return $this->findEntities($qb);
	}
}
