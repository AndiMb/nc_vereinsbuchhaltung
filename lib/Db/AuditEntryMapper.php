<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<AuditEntry>
 */
class AuditEntryMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_audit_log', AuditEntry::class);
	}

	/** Neueste zuerst. @return AuditEntry[] */
	public function findLatest(int $limit = 100, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('id', 'DESC')
			->setMaxResults(min(max($limit, 1), 500))
			->setFirstResult(max($offset, 0));
		return $this->findEntities($qb);
	}
}
