<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Attachment>
 */
class AttachmentMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_attachments', Attachment::class);
	}

	/** @return Attachment[] */
	public function findByJournal(int $journalId, string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('journal_id', $qb->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('uploaded_at', 'ASC');
		return $this->findEntities($qb);
	}

	/** @throws DoesNotExistException */
	public function findOne(int $id, string $userId): Attachment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	/**
	 * Anzahl der Anhänge je Journal-ID sowie die ID des ersten Anhangs (für Direktlinks).
	 *
	 * @return array<int, array{count: int, firstId: int}>  journalId => {count, firstId}
	 */
	public function countByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('journal_id')
			->selectAlias($qb->func()->count('id'), 'cnt')
			->selectAlias($qb->func()->min('id'), 'first_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->groupBy('journal_id');
		$res = $qb->executeQuery();
		$out = [];
		while (($row = $res->fetch()) !== false) {
			$out[(int)$row['journal_id']] = [
				'count'   => (int)$row['cnt'],
				'firstId' => (int)$row['first_id'],
			];
		}
		$res->closeCursor();
		return $out;
	}

	/**
	 * Alle Anhänge eines Buchungssatzes (unabhängig vom Nutzer, da journal_id
	 * bereits eindeutig einem Buchungssatz zugeordnet ist).
	 *
	 * @return Attachment[]
	 */
	public function findAllByJournal(int $journalId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('journal_id', $qb->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	public function deleteAllForUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}
}
