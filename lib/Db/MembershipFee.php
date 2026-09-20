<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Wiederkehrender Mitgliedsbeitrag, gehört zu genau einem
 * {@see \OCA\Vereinsbuchhaltung\Db\Member} (member_id, siehe Migration
 * 000137/000138 für den Umbau von member_uid/member_label). mandate_id ist
 * optional: ein Beitrag kann rein informativ offene Posten erzeugen, ohne je
 * per SEPA eingezogen zu werden.
 *
 * Die erlaubten Werte für `frequency` stehen in
 * {@see \OCA\Vereinsbuchhaltung\Service\BillingPeriod::FREQUENCY_MONTHS} –
 * dort, wo auch damit gerechnet wird.
 *
 * @method int getMemberId()
 * @method void setMemberId(int $memberId)
 * @method int getAmountCents()
 * @method void setAmountCents(int $amountCents)
 * @method string getFrequency()
 * @method void setFrequency(string $frequency)
 * @method string getStartDate()
 * @method void setStartDate(string $startDate)
 * @method string getNextDueDate()
 * @method void setNextDueDate(string $nextDueDate)
 * @method int|null getAccountId()
 * @method void setAccountId(?int $accountId)
 * @method int|null getMandateId()
 * @method void setMandateId(?int $mandateId)
 * @method bool getActive()
 * @method void setActive(bool $active)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class MembershipFee extends Entity implements \JsonSerializable {
	protected $memberId;
	protected $amountCents = 0;
	protected $frequency = 'monthly';
	protected $startDate;
	protected $nextDueDate;
	protected $accountId;
	protected $mandateId;
	protected $active = true;
	protected $createdAt;

	public function __construct() {
		$this->addType('memberId', 'integer');
		$this->addType('amountCents', 'integer');
		$this->addType('accountId', 'integer');
		$this->addType('mandateId', 'integer');
		$this->addType('active', 'boolean');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'memberId' => $this->memberId,
			'amountCents' => $this->amountCents,
			'amount' => $this->amountCents / 100,
			'frequency' => $this->frequency,
			'startDate' => $this->startDate,
			'nextDueDate' => $this->nextDueDate,
			'accountId' => $this->accountId,
			'mandateId' => $this->mandateId,
			'active' => $this->active,
			'createdAt' => $this->createdAt,
		];
	}
}
