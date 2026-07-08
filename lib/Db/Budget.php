<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Finanzplan-Wert (Budget) eines Kontos für ein Geschäftsjahr.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getAccountId()
 * @method void setAccountId(int $accountId)
 * @method int getYear()
 * @method void setYear(int $year)
 * @method int getAmountCents()
 * @method void setAmountCents(int $amountCents)
 * @method ?string getNote()
 * @method void setNote(?string $note)
 */
class Budget extends Entity implements \JsonSerializable {

	protected $userId;
	protected $accountId;
	protected $year;
	protected $amountCents;
	protected $note;

	public function __construct() {
		$this->addType('accountId', 'integer');
		$this->addType('year', 'integer');
		$this->addType('amountCents', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'accountId' => $this->accountId,
			'year' => $this->year,
			'amountCents' => $this->amountCents,
			'amount' => ($this->amountCents ?? 0) / 100,
			'note' => (string)($this->note ?? ''),
		];
	}
}
