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
	 * @return array<int, array{count: int, firstId: int}> journalId => {count, firstId}
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
				'count' => (int)$row['cnt'],
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

	/**
	 * Anhänge mehrerer Buchungssätze auf einmal – ersetzt im ZIP-Export das
	 * Nachladen je Buchung (N+1).
	 *
	 * @param int[] $journalIds
	 * @return array<int, Attachment[]> journalId => Anhänge
	 */
	public function findByJournals(array $journalIds): array {
		if ($journalIds === []) {
			return [];
		}
		$out = [];
		foreach (array_chunk(array_values(array_unique($journalIds)), 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->in('journal_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->orderBy('id', 'ASC');
			foreach ($this->findEntities($qb) as $attachment) {
				$out[$attachment->getJournalId()][] = $attachment;
			}
		}
		return $out;
	}

	/**
	 * Alle Anhänge des Bestands – gebraucht vom Zurücksetzen, um genau die
	 * bekannten Beleg-Dateien zu entfernen (statt den Ablageordner als Ganzes
	 * zu löschen, in dem auch fremde Dateien liegen könnten).
	 *
	 * @return Attachment[]
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
}
