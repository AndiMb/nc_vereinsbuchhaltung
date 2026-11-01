<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<ReimbursementFile>
 */
class ReimbursementFileMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_reimbursement_files', ReimbursementFile::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException wenn es die Datei nicht gibt
	 */
	public function find(int $id): ReimbursementFile {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return ReimbursementFile[] */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		return $this->findEntities($qb);
	}

	/**
	 * @return ReimbursementFile[] nur die nicht entfernten, in Hochlade-Reihenfolge
	 */
	public function findActive(int $reimbursementId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('reimbursement_id', $qb->createNamedParameter($reimbursementId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('removed_at'))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return int Anzahl der nicht entfernten Dateien eines Antrags */
	public function countActive(int $reimbursementId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('reimbursement_id', $qb->createNamedParameter($reimbursementId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('removed_at'));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * Andere Anträge mit derselben Datei (Inhalts-Hash) – Grundlage des
	 * Dubletten-Hinweises. Entfernte Dateien zählen nicht.
	 *
	 * @return ReimbursementFile[]
	 */
	public function findOtherByContentHash(string $hash, int $exceptReimbursementId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('content_hash', $qb->createNamedParameter($hash)))
			->andWhere($qb->expr()->neq('reimbursement_id', $qb->createNamedParameter($exceptReimbursementId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('removed_at'));
		return $this->findEntities($qb);
	}

	/**
	 * Welche dieser Nextcloud-Dateien gehören zu einem Antrag? Der Posteingang
	 * „Zuzuordnen“ blendet sie aus.
	 *
	 * @param list<int> $fileIds
	 * @return array<int,int> Dateikennung => Antrags-ID
	 */
	public function reimbursementIdsByFileIds(array $fileIds): array {
		$found = [];
		foreach (array_chunk(array_values(array_unique($fileIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('file_id', 'reimbursement_id')
				->from($this->getTableName())
				->where($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->isNull('removed_at'));
			$result = $qb->executeQuery();
			while (($row = $result->fetch()) !== false) {
				$found[(int)$row['file_id']] = (int)$row['reimbursement_id'];
			}
			$result->closeCursor();
		}
		return $found;
	}

	/**
	 * Löscht alle Zeilen. Nur für den Reset der Buchhaltung; im Normalbetrieb
	 * gibt es keinen Weg, die Historie eines Antrags zu entfernen.
	 */
	public function deleteAll(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())->executeStatement();
	}
}
