<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<DebitBatch>
 */
class DebitBatchMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_debit_batches', DebitBatch::class);
	}

	public function find(int $id): DebitBatch {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return DebitBatch[] neueste Fälligkeit zuerst */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('due_date', 'DESC')
			->addOrderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Noch lebende (nicht terminale) Läufe zu einem Fälligkeitstag – Grundlage
	 * dafür, ob {@see \OCA\Vereinsbuchhaltung\Service\DebitBatchService::rescheduleDueDate()}
	 * auf ein Datum verschieben dürfte, das bereits einen eigenen Lauf hat.
	 * Absichtlich KEINE Sperre gegen eine zweite Freigabe für denselben Tag:
	 * die eigentliche Invariante („keine Forderung landet zweimal in einem
	 * lebenden Lauf") sichert bereits
	 * {@see DebitItemMapper::findOpenItemIdsInLiveBatches()} auf Ebene der
	 * einzelnen Forderung – siehe DebitBatchService::release()-Klassendoc.
	 *
	 * @return DebitBatch[]
	 */
	public function findLiveByDueDate(string $dueDate): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('due_date', $qb->createNamedParameter($dueDate)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(DebitBatch::STATUS_RELEASED)));
		return $this->findEntities($qb);
	}

	/**
	 * Freigegeben, aber noch nicht eingereicht – das Freigabe→Einreichung-
	 * Fenster (Spec §3.5), Grundlage für Abweichungs-Warnungen und die
	 * „Einreichung überfällig"-Aufgabe (Spec §7).
	 *
	 * @return DebitBatch[]
	 */
	public function findReleasedNotSubmitted(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(DebitBatch::STATUS_RELEASED)))
			->orderBy('due_date', 'ASC');
		return $this->findEntities($qb);
	}
}
