<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Finanzplan-Wert (Budget) eines Kontos für ein Geschäftsjahr.
 *
 * Das Geschäftsjahr steht seit 0.33.0 als Verweis auf {@see Period} da, nicht
 * mehr als Jahreszahl: es muss weder dem Kalenderjahr entsprechen noch zwölf
 * Monate lang sein (Issue #8).
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getAccountId()
 * @method void setAccountId(int $accountId)
 * @method int getPeriodId()
 * @method void setPeriodId(int $periodId)
 * @method int getAmountCents()
 * @method void setAmountCents(int $amountCents)
 * @method ?string getNote()
 * @method void setNote(?string $note)
 */
class Budget extends Entity implements \JsonSerializable {

	protected $userId;
	protected $accountId;
	protected $periodId;
	protected $amountCents;
	protected $note;

	public function __construct() {
		$this->addType('accountId', 'integer');
		$this->addType('periodId', 'integer');
		$this->addType('amountCents', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'accountId' => $this->accountId,
			'periodId' => $this->periodId,
			'amountCents' => $this->amountCents,
			'amount' => ($this->amountCents ?? 0) / 100,
			'note' => (string)($this->note ?? ''),
		];
	}
}
