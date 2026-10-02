<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Task;
use OCA\Vereinsbuchhaltung\Db\TaskMapper;

/**
 * Siehe {@see Task} zur Frage, warum es diese kleine Persistenz überhaupt
 * gibt statt einer reinen abgeleiteten Abfrage.
 */
class TaskService {

	public function __construct(
		private TaskMapper $mapper,
	) {
	}

	/** @return Task[] neueste zuerst */
	public function findAll(): array {
		return $this->mapper->findAll();
	}

	public function create(string $severity, string $message, ?string $objectType = null, ?int $objectId = null): Task {
		$task = new Task();
		$task->setSeverity($severity);
		$task->setMessage($message);
		$task->setObjectType($objectType);
		$task->setObjectId($objectId);
		$task->setCreatedAt((new \DateTime())->format('Y-m-d H:i:s'));
		return $this->mapper->insert($task);
	}
}
