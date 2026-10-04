<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<OpenItem>
 */
class OpenItemMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_open_items', OpenItem::class);
	}

	/** @return OpenItem[] */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('due_date', 'ASC')
			->addOrderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	public function find(int $id): OpenItem {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Mehrere offene Posten in einer Abfrage (Bankabgleich, Issue #105).
	 *
	 * @param list<int> $ids
	 * @return array<int,OpenItem> Posten-ID => Posten (fehlende IDs fehlen auch hier)
	 */
	public function findByIds(array $ids): array {
		$found = [];
		foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($this->findEntities($qb) as $item) {
				$found[(int)$item->getId()] = $item;
			}
		}
		return $found;
	}

	/**
	 * Löscht alle offenen Posten. Anders als die übrigen Tabellen trägt
	 * `vbh_open_items` keine `user_id` – die Liste gehört wie der restliche
	 * Datenbestand dem gemeinsamen Vereins-Nutzer, daher ohne Filter.
	 */
	public function deleteAll(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName());
		$qb->executeStatement();
	}

	/**
	 * Alle Forderungen im Sinne von Issue #68 (memberId+type gesetzt), für die
	 * „Offene-Posten-Sicht" (Spec §3.9, revisor+). Bewusst getrennt von
	 * findAll(): die alten Freitext-Posten (OpenItemService) sollen dort nicht
	 * mit auftauchen, und umgekehrt.
	 *
	 * @return OpenItem[]
	 */
	public function findClaims(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('member_id'))
			->andWhere($qb->expr()->isNotNull('type'))
			->orderBy('due_date', 'ASC')
			->addOrderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/** @return OpenItem[] */
	public function findByMember(int $memberId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('member_id', $qb->createNamedParameter($memberId, IQueryBuilder::PARAM_INT)))
			->orderBy('due_date', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return OpenItem[] */
	public function findByAssignment(int $assignmentId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('assignment_id', $qb->createNamedParameter($assignmentId, IQueryBuilder::PARAM_INT)))
			->orderBy('period_start', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Forderungen, deren Vorabinfo noch aussteht: offen, nicht storniert, mit
	 * Fälligkeitsdatum, `prenotified_at` noch NULL, fällig bis spätestens
	 * `$until` (Issue #70, D−14-Vorlauf). Die Prüfung, ob die Forderung
	 * überhaupt lastschriftfähig ist (Zahlungsart, Mandat), macht bewusst
	 * nicht diese Abfrage, sondern
	 * {@see \OCA\Vereinsbuchhaltung\Service\DirectDebitEligibilityResolver} -
	 * das braucht Zuweisung/Mandat, die diese Tabelle nicht kennt.
	 *
	 * @return OpenItem[]
	 */
	public function findClaimsAwaitingPrenotification(string $until): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('member_id'))
			->andWhere($qb->expr()->isNotNull('type'))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open')))
			->andWhere($qb->expr()->isNull('cancelled_at'))
			->andWhere($qb->expr()->isNull('prenotified_at'))
			->andWhere($qb->expr()->isNotNull('due_date'))
			->andWhere($qb->expr()->lte('due_date', $qb->createNamedParameter($until)))
			->orderBy('due_date', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Alle (noch offenen, nicht stornierten) Forderungen mit Fälligkeit genau
	 * `$dueDate` – die „rein als Abfrage" gebündelte Sicht auf einen Lauf
	 * (Issue #70/Spec §3.5: „Ein Lauf bündelt Forderungen aller Turnusse …
	 * plus manuelle Einzelforderungen und Prorata-Erstforderungen mit diesem
	 * Termin"). Weil {@see \OCA\Vereinsbuchhaltung\Service\ClaimGenerationService}
	 * jede Forderung – Turnus, manuell oder Prorata-Erstforderung – gleich als
	 * Zeile mit `due_date` anlegt, ist das Bündeln hier nichts weiter als
	 * dieser einfache Datumsfilter.
	 *
	 * @return OpenItem[]
	 */
	public function findClaimsDueOn(string $dueDate): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('member_id'))
			->andWhere($qb->expr()->isNotNull('type'))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open')))
			->andWhere($qb->expr()->isNull('cancelled_at'))
			->andWhere($qb->expr()->eq('due_date', $qb->createNamedParameter($dueDate)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** Anzahl überfälliger offener Posten (für die Dashboard-KPI). */
	public function countOverdue(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('open')))
			->andWhere($qb->expr()->isNotNull('due_date'))
			->andWhere($qb->expr()->lt('due_date', $qb->createNamedParameter(date('Y-m-d'))));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}
}
