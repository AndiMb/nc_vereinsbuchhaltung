<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Eine eingefrorene Position eines Finanzplan-Standes: der Planwert eines
 * Kontos zum Zeitpunkt des Standes. Kontonummer/-name/-typ werden mit
 * eingefroren, damit der Stand auch nach Kontenänderungen lesbar bleibt.
 *
 * @method int getSnapshotId()
 * @method void setSnapshotId(int $snapshotId)
 * @method int getAccountId()
 * @method void setAccountId(int $accountId)
 * @method string|null getAccountNumber()
 * @method void setAccountNumber(?string $accountNumber)
 * @method string getAccountName()
 * @method void setAccountName(string $accountName)
 * @method string getAccountType()
 * @method void setAccountType(string $accountType)
 * @method int getAmountCents()
 * @method void setAmountCents(int $amountCents)
 */
class BudgetSnapshotItem extends Entity implements \JsonSerializable {

	protected $snapshotId;
	protected $accountId;
	protected $accountNumber;
	protected $accountName;
	protected $accountType;
	protected $amountCents;

	public function __construct() {
		$this->addType('snapshotId', 'integer');
		$this->addType('accountId', 'integer');
		$this->addType('amountCents', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'accountId' => $this->accountId,
			'number' => $this->accountNumber,
			'name' => $this->accountName,
			'type' => $this->accountType,
			'amountCents' => $this->amountCents,
			'amount' => ($this->amountCents ?? 0) / 100,
		];
	}
}
