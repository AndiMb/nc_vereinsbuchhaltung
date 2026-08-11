<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Wiederkehrender Mitgliedsbeitrag. Genau eines von member_uid und
 * member_label ist gesetzt (siehe Migration 000125 und SepaMandate für die
 * gleiche Modellierung). mandate_id ist optional: ein Beitrag kann rein
 * informativ offene Posten erzeugen, ohne je per SEPA eingezogen zu werden.
 *
 * Die erlaubten Werte für `frequency` stehen in
 * {@see \OCA\Vereinsbuchhaltung\Service\BillingPeriod::FREQUENCY_MONTHS} –
 * dort, wo auch damit gerechnet wird.
 *
 * @method string|null getMemberUid()
 * @method void setMemberUid(?string $memberUid)
 * @method string|null getMemberLabel()
 * @method void setMemberLabel(?string $memberLabel)
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
	protected $memberUid;
	protected $memberLabel;
	protected $amountCents = 0;
	protected $frequency = 'monthly';
	protected $startDate;
	protected $nextDueDate;
	protected $accountId;
	protected $mandateId;
	protected $active = true;
	protected $createdAt;

	public function __construct() {
		$this->addType('amountCents', 'integer');
		$this->addType('accountId', 'integer');
		$this->addType('mandateId', 'integer');
		$this->addType('active', 'boolean');
	}

	/** Audit-taugliche Kurzbezeichnung – siehe SepaMandate::displayName() für dieselbe Idee. */
	public function displayName(): string {
		return $this->memberLabel ?? ($this->memberUid ?? '');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'memberUid' => $this->memberUid,
			'memberLabel' => $this->memberLabel,
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
