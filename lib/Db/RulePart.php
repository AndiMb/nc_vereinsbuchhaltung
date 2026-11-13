<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Fester Anteil einer Importregel (Issue #163): dieser Betrag geht auf dieses
 * Konto, der Rest des Umsatzes auf das Gegenkonto der Regel.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getRuleId()
 * @method void setRuleId(int $ruleId)
 * @method int getAccountId()
 * @method void setAccountId(int $accountId)
 * @method int getAmountCents()
 * @method void setAmountCents(int $amountCents)
 * @method int|null getCostCenterId()
 * @method void setCostCenterId(?int $costCenterId)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 */
class RulePart extends Entity implements \JsonSerializable {
	protected $userId;
	protected $ruleId;
	protected $accountId;
	protected $amountCents;
	/** Kostenstelle (vbh_booking_cc.id) für den ganzen Anteil, null = keine. */
	protected $costCenterId;
	protected $sortOrder = 0;

	public function __construct() {
		$this->addType('ruleId', 'integer');
		$this->addType('accountId', 'integer');
		$this->addType('amountCents', 'integer');
		$this->addType('costCenterId', 'integer');
		$this->addType('sortOrder', 'integer');
	}

	/** Betrag in Euro, wie ihn die Oberfläche anzeigt und zurückschickt. */
	public function jsonSerialize(): array {
		return [
			'accountId' => (int)$this->accountId,
			'amount' => ((int)$this->amountCents) / 100,
			'costCenterId' => $this->costCenterId === null ? null : (int)$this->costCenterId,
		];
	}
}
