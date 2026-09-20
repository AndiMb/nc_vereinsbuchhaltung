<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * SEPA-Lastschriftmandat, gehört zu genau einem {@see \OCA\Vereinsbuchhaltung\Db\Member}
 * (member_id). Bis Version000138 hieß der Zahler noch member_uid/member_label
 * (Freitext oder Nextcloud-Konto ohne eigene Mitglieder-Entity) – siehe
 * Migration 000137/000138 für den Umbau.
 *
 * @method int getMemberId()
 * @method void setMemberId(int $memberId)
 * @method string getIban()
 * @method void setIban(string $iban)
 * @method string|null getBic()
 * @method void setBic(?string $bic)
 * @method string|null getEmail()
 * @method void setEmail(?string $email)
 * @method string getMandateReference()
 * @method void setMandateReference(string $mandateReference)
 * @method string getMandateType()
 * @method void setMandateType(string $mandateType)
 * @method string getSignedDate()
 * @method void setSignedDate(string $signedDate)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getLastUsedDate()
 * @method void setLastUsedDate(?string $lastUsedDate)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class SepaMandate extends Entity implements \JsonSerializable {
	protected $memberId;
	protected $iban;
	protected $bic;
	protected $email;
	protected $mandateReference;
	protected $mandateType = 'RCUR';
	protected $signedDate;
	protected $status = 'active';
	protected $lastUsedDate;
	protected $createdAt;

	/** RCUR = wiederkehrend, OOFF = einmalig. */
	public const TYPES = ['RCUR', 'OOFF'];
	public const STATUSES = ['active', 'revoked'];

	public function __construct() {
		$this->addType('memberId', 'integer');
	}

	/** Wurde dieses Mandat schon mindestens einmal eingezogen? */
	public function isFirstUse(): bool {
		return $this->lastUsedDate === null;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'memberId' => $this->memberId,
			'iban' => $this->iban,
			'bic' => $this->bic,
			'email' => $this->email,
			'mandateReference' => $this->mandateReference,
			'mandateType' => $this->mandateType,
			'signedDate' => $this->signedDate,
			'status' => $this->status,
			'lastUsedDate' => $this->lastUsedDate,
			'createdAt' => $this->createdAt,
		];
	}
}
