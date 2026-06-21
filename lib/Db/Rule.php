<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getMatchField()
 * @method void setMatchField(string $matchField)
 * @method string getMatchValue()
 * @method void setMatchValue(string $matchValue)
 * @method int getContraAccountId()
 * @method void setContraAccountId(int $contraAccountId)
 * @method int getPriority()
 * @method void setPriority(int $priority)
 */
class Rule extends Entity implements \JsonSerializable {
	protected $userId;
	protected $matchField;
	protected $matchValue;
	protected $contraAccountId;
	protected $priority = 0;

	public function __construct() {
		$this->addType('contraAccountId', 'integer');
		$this->addType('priority', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'matchField' => $this->matchField,
			'matchValue' => $this->matchValue,
			'contraAccountId' => $this->contraAccountId,
			'priority' => $this->priority,
		];
	}
}
