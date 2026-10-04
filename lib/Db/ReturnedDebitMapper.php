<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<ReturnedDebit>
 */
class ReturnedDebitMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_returned_debits', ReturnedDebit::class);
	}

	public function find(int $id): ReturnedDebit {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Für den Posten-Guard (Spec §3.6/§5 „max. 1 Rücklastschrift je
	 * Einzugsposten") – vor dem Anlegen prüfen, damit eine Dublette still
	 * verpuffen kann, statt gegen den Unique-Index zu laufen.
	 */
	public function findByDebitItem(int $debitItemId): ?ReturnedDebit {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('debit_item_id', $qb->createNamedParameter($debitItemId, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return null;
		}
	}

	/**
	 * Die Rücklastschriften eines Mitglieds (Self-Service „Mein Beitrag", Issue
	 * #122): maßgeblich ist, wem die zurückgegebene Forderung gehört –
	 * Rücklastschrift → Einzugsposten → Forderung (`vbh_open_items.member_id`).
	 * Der innere Join auf die Forderung ist Absicht: eine Rücklastschrift, deren
	 * Forderung es nicht mehr gibt, lässt sich keinem Mitglied zuordnen und
	 * bleibt deshalb draußen.
	 *
	 * @return list<ReturnedDebit> in der Reihenfolge der Datenbank; die Sortierung
	 *                             (neueste zuerst) übernimmt
	 *                             {@see \OCA\Vereinsbuchhaltung\Service\SelfReturnedDebitService}
	 */
	public function findByMember(int $memberId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('r.*')
			->from($this->getTableName(), 'r')
			->innerJoin('r', 'vbh_debit_items', 'i', $qb->expr()->eq('r.debit_item_id', 'i.id'))
			->innerJoin('i', 'vbh_open_items', 'o', $qb->expr()->eq('i.open_item_id', 'o.id'))
			->where($qb->expr()->eq('o.member_id', $qb->createNamedParameter($memberId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/**
	 * Eingangsdatum der Rücklastschriften aller Posten eines Laufs, je
	 * Einzugsposten: `debit_item_id => received_at`. Eine Abfrage statt einer
	 * je Zeile, weil das Lauf-Detail (Issue #102) den abgeleiteten
	 * Forderungszustand für jede Zeile braucht
	 * ({@see \OCA\Vereinsbuchhaltung\Service\ClaimStateResolver::resolveForDebitItem()}).
	 *
	 * @return array<int,string>
	 */
	public function findReceivedAtByBatch(int $batchId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('r.debit_item_id', 'r.received_at')
			->from($this->getTableName(), 'r')
			->innerJoin('r', 'vbh_debit_items', 'i', $qb->expr()->eq('r.debit_item_id', 'i.id'))
			->where($qb->expr()->eq('i.batch_id', $qb->createNamedParameter($batchId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$received = [];
		while (($row = $result->fetch()) !== false) {
			$received[(int)$row['debit_item_id']] = (string)$row['received_at'];
		}
		$result->closeCursor();
		return $received;
	}

	/**
	 * Alle Rücklastschriften, je Einzugsposten: `debit_item_id => Rücklastschrift`.
	 * Anders als {@see findReceivedAtByBatch()} mit Grund und Gebühr – die
	 * lesende Forderungsübersicht (Issue #104) zeigt die Ursache in Klartext,
	 * für Buchhalter zusätzlich den Code. Eine Abfrage für alle Forderungen
	 * statt einer je Zeile; Rücklastschriften sind die Ausnahme, die Menge
	 * bleibt klein.
	 *
	 * @return array<int,ReturnedDebit>
	 */
	public function findAllByDebitItem(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		$returned = [];
		foreach ($this->findEntities($qb) as $entity) {
			$returned[$entity->getDebitItemId()] = $entity;
		}
		return $returned;
	}
}
