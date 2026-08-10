<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<SepaBatchItem>
 */
class SepaBatchItemMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_sepa_batch_items', SepaBatchItem::class);
	}

	/** @return SepaBatchItem[] */
	public function findByBatch(int $batchId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('batch_id', $qb->createNamedParameter($batchId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Offene Posten, die bereits in einem noch nicht zurückgebuchten Einzug
	 * stecken.
	 *
	 * @return int[]
	 */
	public function findPendingOpenItemIds(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('open_item_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('pending')));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return array_map(static fn (array $row): int => (int)$row['open_item_id'], $rows);
	}

	/**
	 * Sammeleinzug-Zeilen, deren Fälligkeit (Batch-execution_date) genau auf
	 * $targetDate fällt und die noch keine Vorankündigung bekommen haben.
	 * Genutzt vom {@see \OCA\Vereinsbuchhaltung\BackgroundJob\SepaPreNotificationJob}.
	 *
	 * @return SepaBatchItem[]
	 */
	public function findDueForNotification(string $targetDate): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('i.*')
			->from($this->getTableName(), 'i')
			->innerJoin('i', 'vbh_sepa_batches', 'b', $qb->expr()->eq('i.batch_id', 'b.id'))
			->where($qb->expr()->eq('i.status', $qb->createNamedParameter('pending')))
			->andWhere($qb->expr()->isNull('i.notified_at'))
			->andWhere($qb->expr()->eq('b.execution_date', $qb->createNamedParameter($targetDate)));
		return $this->findEntities($qb);
	}

	/** @return SepaBatchItem[] noch offene (nicht zurückgebuchte) Zeilen eines Mandats */
	public function findPendingByMandate(int $mandateId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('mandate_id', $qb->createNamedParameter($mandateId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')));
		return $this->findEntities($qb);
	}

	/**
	 * Noch offene Zeilen mit genau diesem Betrag – Fallback-Zuordnung für die
	 * Rücklastschrift-Erkennung, wenn im Verwendungszweck keine Mandats-
	 * oder End-to-End-Referenz zu finden war.
	 *
	 * @return SepaBatchItem[]
	 */
	public function findPendingByAmount(int $amountCents): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('amount_cents', $qb->createNamedParameter($amountCents, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')));
		return $this->findEntities($qb);
	}

	public function findByEndToEndId(string $endToEndId): ?SepaBatchItem {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('end_to_end_id', $qb->createNamedParameter($endToEndId)))
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}
}
