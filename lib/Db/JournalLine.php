<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getJournalId()
 * @method void setJournalId(int $journalId)
 * @method int getAccountId()
 * @method void setAccountId(int $accountId)
 * @method int getDebitCents()
 * @method void setDebitCents(int $debitCents)
 * @method int getCreditCents()
 * @method void setCreditCents(int $creditCents)
 */
class JournalLine extends Entity implements \JsonSerializable {
	protected $journalId;
	protected $accountId;
	protected $debitCents = 0;
	protected $creditCents = 0;

	public function __construct() {
		$this->addType('journalId', 'integer');
		$this->addType('accountId', 'integer');
		$this->addType('debitCents', 'integer');
		$this->addType('creditCents', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'journalId' => $this->journalId,
			'accountId' => $this->accountId,
			'debitCents' => $this->debitCents,
			'creditCents' => $this->creditCents,
			'debit' => $this->debitCents / 100,
			'credit' => $this->creditCents / 100,
		];
	}
}
