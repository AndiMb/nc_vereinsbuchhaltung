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
 * @method int|null getCostCenterId()
 * @method void setCostCenterId(?int $costCenterId)
 */
class Rule extends Entity implements \JsonSerializable {
	protected $userId;
	protected $matchField;
	protected $matchValue;
	protected $contraAccountId;
	protected $priority = 0;
	/**
	 * Vorgabe-Kostenstelle (vbh_booking_cc.id, Issue #331): greift die Regel
	 * beim Import, wird der ganze Betrag ihr zugeordnet. null = keine.
	 */
	protected $costCenterId;

	public function __construct() {
		$this->addType('contraAccountId', 'integer');
		$this->addType('priority', 'integer');
		$this->addType('costCenterId', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'matchField' => $this->matchField,
			'matchValue' => $this->matchValue,
			'contraAccountId' => $this->contraAccountId,
			'priority' => $this->priority,
			'costCenterId' => $this->costCenterId,
		];
	}
}
