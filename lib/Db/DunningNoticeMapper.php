<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<DunningNotice>
 */
class DunningNoticeMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_dunning_notices', DunningNotice::class);
	}

	/** @return DunningNotice[] alle bisher versendeten Stufen einer Forderung, aufsteigend. */
	public function findByOpenItem(int $openItemId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('open_item_id', $qb->createNamedParameter($openItemId, IQueryBuilder::PARAM_INT)))
			->orderBy('stage', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Für den Idempotenz-Guard vor dem Versand einer Stufe (Spec: „eine Zeile
	 * je (Forderung, Stufe)" – ein zweiter Versand derselben Stufe darf nicht
	 * passieren, weder bei einem doppelten Cron-Lauf noch bei einem erneuten
	 * ereignisgetriebenen Trigger).
	 */
	public function findByOpenItemAndStage(int $openItemId, int $stage): ?DunningNotice {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('open_item_id', $qb->createNamedParameter($openItemId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('stage', $qb->createNamedParameter($stage, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return null;
		}
	}

	/** @return DunningNotice[] alle Zeilen einer Stufe – Grundlage der Eskalationsprüfung in DunningLadderService/DunningTaskService. */
	public function findByStage(int $stage): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('stage', $qb->createNamedParameter($stage, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}
}
