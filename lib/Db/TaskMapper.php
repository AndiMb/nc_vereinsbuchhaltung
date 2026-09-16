<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Task>
 */
class TaskMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_tasks', Task::class);
	}

	/** @return Task[] neueste zuerst */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC');
		return $this->findEntities($qb);
	}
}
