<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Ein Eintrag im Änderungsprotokoll.
 *
 * @method string getTs()
 * @method void setTs(string $ts)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getAction()
 * @method void setAction(string $action)
 * @method string|null getObjectType()
 * @method void setObjectType(?string $objectType)
 * @method int|null getObjectId()
 * @method void setObjectId(?int $objectId)
 * @method string|null getDetails()
 * @method void setDetails(?string $details)
 */
class AuditEntry extends Entity implements \JsonSerializable {
	protected $ts;
	protected $userId;
	protected $action;
	protected $objectType;
	protected $objectId;
	protected $details;

	public function __construct() {
		$this->addType('objectId', 'integer');
	}

	public function jsonSerialize(): array {
		$details = null;
		if ($this->details !== null && $this->details !== '') {
			$decoded = json_decode($this->details, true);
			$details = is_array($decoded) ? $decoded : null;
		}
		return [
			'id' => $this->id,
			'ts' => $this->ts,
			'userId' => $this->userId,
			'action' => $this->action,
			'objectType' => $this->objectType,
			'objectId' => $this->objectId,
			'details' => $details,
		];
	}
}
