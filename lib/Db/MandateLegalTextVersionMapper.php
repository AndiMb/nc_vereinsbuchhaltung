<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<MandateLegalTextVersion>
 */
class MandateLegalTextVersionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_mandate_legal_text_versions', MandateLegalTextVersion::class);
	}

	public function find(int $id): MandateLegalTextVersion {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	public function findOrNull(int $id): ?MandateLegalTextVersion {
		try {
			return $this->find($id);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Die zuletzt angelegte Version - "aktuell" im Sinne von Spec §2.2. Neue
	 * Anzeigen (Einmal-Link, Formular-PDF ohne bereits fixierte Version)
	 * bekommen immer diese; einmal fixierte Mandate bleiben unabhängig davon
	 * auf ihrer eigenen Version stehen (keine Rückwirkung).
	 */
	public function findLatest(): ?MandateLegalTextVersion {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/** @return MandateLegalTextVersion[] neueste zuerst - Verlauf für die Admin-Ansicht. */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}
}
