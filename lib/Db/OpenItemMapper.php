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
	 * Offene Posten mit verknüpftem SEPA-Mandat – die Auswahlmenge für den
	 * SEPA-Export (siehe SepaBatchService). Alle anderen offenen Posten
	 * bleiben davon unberührt (mandate_id ist NULL, siehe OpenItem-Docblock).
	 *
	 * @return OpenItem[]
	 */
	public function findOpenWithMandate(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('open')))
			->andWhere($qb->expr()->isNotNull('mandate_id'))
			->orderBy('due_date', 'ASC');
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
