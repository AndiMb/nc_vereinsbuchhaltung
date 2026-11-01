<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Append-only: dieser Mapper bietet bewusst nur Lesen und Anfügen. `update()`
 * und `delete()` des Basis-Mappers sind gesperrt, damit auch ein späterer
 * Aufrufer die Historie nicht versehentlich umschreibt.
 *
 * @extends QBMapper<ReimbursementEvent>
 */
class ReimbursementEventMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_reimbursement_events', ReimbursementEvent::class);
	}

	/** @return ReimbursementEvent[] in der Reihenfolge, in der sie geschehen sind */
	public function findByReimbursement(int $reimbursementId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('reimbursement_id', $qb->createNamedParameter($reimbursementId, IQueryBuilder::PARAM_INT)))
			->orderBy('seq', 'ASC');
		return $this->findEntities($qb);
	}

	public function findLast(int $reimbursementId): ?ReimbursementEvent {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('reimbursement_id', $qb->createNamedParameter($reimbursementId, IQueryBuilder::PARAM_INT)))
			->orderBy('seq', 'DESC')
			->setMaxResults(1);
		$found = $this->findEntities($qb);
		return $found[0] ?? null;
	}

	/**
	 * Wer hat diesen Antrag schon freigegeben? Jeder Verwalter zählt
	 * höchstens einmal.
	 *
	 * @return list<string> Nutzerkennungen
	 */
	public function approverUids(int $reimbursementId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('actor_uid')
			->from($this->getTableName())
			->where($qb->expr()->eq('reimbursement_id', $qb->createNamedParameter($reimbursementId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('action', $qb->createNamedParameter(ReimbursementEvent::ACTION_APPROVED)));
		$result = $qb->executeQuery();
		$uids = [];
		while (($uid = $result->fetchOne()) !== false) {
			$uids[] = (string)$uid;
		}
		$result->closeCursor();
		return array_values(array_unique($uids));
	}

	/**
	 * Wer hat diese Anträge freigegeben? Eine Abfrage für eine ganze Liste.
	 *
	 * @param list<int> $reimbursementIds
	 * @return array<int, list<string>> Antrags-ID => Nutzerkennungen (jede einmal)
	 */
	public function approverUidsFor(array $reimbursementIds): array {
		$found = [];
		foreach (array_chunk(array_values(array_unique($reimbursementIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('reimbursement_id', 'actor_uid')
				->from($this->getTableName())
				->where($qb->expr()->in('reimbursement_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('action', $qb->createNamedParameter(ReimbursementEvent::ACTION_APPROVED)));
			$result = $qb->executeQuery();
			while (($row = $result->fetch()) !== false) {
				$found[(int)$row['reimbursement_id']][(string)$row['actor_uid']] = true;
			}
			$result->closeCursor();
		}
		return array_map(static fn (array $uids): array => array_keys($uids), $found);
	}

	public function update(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity {
		throw new \LogicException('Die Historie eines Auslagenantrags wird nicht geändert.');
	}

	public function delete(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity {
		throw new \LogicException('Die Historie eines Auslagenantrags wird nicht gelöscht.');
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
