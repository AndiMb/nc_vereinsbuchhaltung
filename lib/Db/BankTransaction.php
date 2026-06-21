<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int|null getImportId()
 * @method void setImportId(?int $importId)
 * @method string getBookingDate()
 * @method void setBookingDate(string $bookingDate)
 * @method string|null getValueDate()
 * @method void setValueDate(?string $valueDate)
 * @method int getAmountCents()
 * @method void setAmountCents(int $amountCents)
 * @method string getCurrency()
 * @method void setCurrency(string $currency)
 * @method string|null getBookingText()
 * @method void setBookingText(?string $bookingText)
 * @method string|null getPurpose()
 * @method void setPurpose(?string $purpose)
 * @method string|null getCounterparty()
 * @method void setCounterparty(?string $counterparty)
 * @method string|null getCounterpartyIban()
 * @method void setCounterpartyIban(?string $counterpartyIban)
 * @method string|null getCounterpartyBic()
 * @method void setCounterpartyBic(?string $counterpartyBic)
 * @method string|null getOwnAccount()
 * @method void setOwnAccount(?string $ownAccount)
 * @method string getHash()
 * @method void setHash(string $hash)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int|null getContraAccountId()
 * @method void setContraAccountId(?int $contraAccountId)
 * @method int|null getJournalId()
 * @method void setJournalId(?int $journalId)
 */
class BankTransaction extends Entity implements \JsonSerializable {
	protected $userId;
	protected $importId;
	protected $bookingDate;
	protected $valueDate;
	protected $amountCents = 0;
	protected $currency = 'EUR';
	protected $bookingText;
	protected $purpose;
	protected $counterparty;
	protected $counterpartyIban;
	protected $counterpartyBic;
	protected $ownAccount;
	protected $hash;
	protected $status = 'unassigned';
	protected $contraAccountId;
	protected $journalId;

	public function __construct() {
		$this->addType('importId', 'integer');
		$this->addType('amountCents', 'integer');
		$this->addType('contraAccountId', 'integer');
		$this->addType('journalId', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'importId' => $this->importId,
			'bookingDate' => $this->bookingDate,
			'valueDate' => $this->valueDate,
			'amountCents' => $this->amountCents,
			'amount' => $this->amountCents / 100,
			'currency' => $this->currency,
			'bookingText' => $this->bookingText,
			'purpose' => $this->purpose,
			'counterparty' => $this->counterparty,
			'counterpartyIban' => $this->counterpartyIban,
			'counterpartyBic' => $this->counterpartyBic,
			'ownAccount' => $this->ownAccount,
			'status' => $this->status,
			'contraAccountId' => $this->contraAccountId,
			'journalId' => $this->journalId,
		];
	}
}
