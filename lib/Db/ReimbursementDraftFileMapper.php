<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Belegdateien von Entwürfen (Issue #356).
 *
 * @extends QBMapper<ReimbursementDraftFile>
 */
class ReimbursementDraftFileMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_reimb_draft_files', ReimbursementDraftFile::class);
	}

	/** @return ReimbursementDraftFile[] in der Reihenfolge des Hochladens */
	public function findByDraft(int $draftId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('draft_id', $qb->createNamedParameter($draftId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * @param list<int> $draftIds
	 * @return ReimbursementDraftFile[]
	 */
	public function findByDrafts(array $draftIds): array {
		if ($draftIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('draft_id', $qb->createNamedParameter($draftIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @throws \OCP\AppFramework\Db\DoesNotExistException */
	public function findForDraft(int $draftId, int $fileId): ReimbursementDraftFile {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('draft_id', $qb->createNamedParameter($draftId, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}
}
