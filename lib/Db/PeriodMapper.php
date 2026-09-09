<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Period>
 */
class PeriodMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_periods', Period::class);
	}

	/**
	 * Alle Perioden eines Buchs, neueste zuerst – die Reihenfolge des
	 * Auswahlfelds in der Kopfzeile.
	 *
	 * @return Period[]
	 */
	public function findAll(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('start_date', 'DESC');
		return $this->findEntities($qb);
	}

	/** @throws DoesNotExistException */
	public function find(string $userId, int $id): Period {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	/**
	 * Die Periode, die $date enthält – oder null, wenn (noch) keine passt.
	 *
	 * Der Vergleich läuft über die ISO-Strings; er ist damit identisch mit dem
	 * chronologischen und auf allen unterstützten Datenbanken gleich.
	 */
	public function findByDate(string $userId, string $date): ?Period {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter($date)))
			->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($date)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** Die zeitlich erste Periode, oder null bei leerem Buch. */
	public function findFirst(string $userId): ?Period {
		return $this->findOneOrdered($userId, 'ASC');
	}

	/** Die zeitlich letzte Periode, oder null bei leerem Buch. */
	public function findLast(string $userId): ?Period {
		return $this->findOneOrdered($userId, 'DESC');
	}

	/** Die Periode unmittelbar vor $startDate, oder null. */
	public function findBefore(string $userId, string $startDate): ?Period {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->lt('start_date', $qb->createNamedParameter($startDate)))
			->orderBy('start_date', 'DESC')
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/** Die Periode unmittelbar nach $startDate, oder null. */
	public function findAfter(string $userId, string $startDate): ?Period {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->gt('start_date', $qb->createNamedParameter($startDate)))
			->orderBy('start_date', 'ASC')
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/**
	 * IDs aller festgeschriebenen Perioden.
	 *
	 * @return int[]
	 */
	public function findClosedIds(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNotNull('closed_at'));
		$res = $qb->executeQuery();
		$ids = [];
		while (($row = $res->fetch()) !== false) {
			$ids[] = (int)$row['id'];
		}
		$res->closeCursor();
		return $ids;
	}

	/** Ist die Bezeichnung schon vergeben? $exceptId schließt die eigene Zeile aus. */
	public function labelExists(string $userId, string $label, ?int $exceptId = null): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('label', $qb->createNamedParameter($label)))
			->setMaxResults(1);
		if ($exceptId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($exceptId, IQueryBuilder::PARAM_INT)));
		}
		$res = $qb->executeQuery();
		$row = $res->fetch();
		$res->closeCursor();
		return $row !== false;
	}

	public function deleteAllForUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	private function findOneOrdered(string $userId, string $direction): ?Period {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('start_date', $direction)
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}
}
