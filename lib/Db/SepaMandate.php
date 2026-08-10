<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * SEPA-Lastschriftmandat. Genau eines von member_uid (Nextcloud-Konto) und
 * member_label (Freitext-Zahler, z. B. für Verbände ohne Mitglieder-Konten)
 * ist gesetzt – siehe Migration 000124 und SepaMandateService::create().
 *
 * @method string|null getMemberUid()
 * @method void setMemberUid(?string $memberUid)
 * @method string|null getMemberLabel()
 * @method void setMemberLabel(?string $memberLabel)
 * @method string getIban()
 * @method void setIban(string $iban)
 * @method string|null getBic()
 * @method void setBic(?string $bic)
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
	protected $memberUid;
	protected $memberLabel;
	protected $iban;
	protected $bic;
	protected $mandateReference;
	protected $mandateType = 'RCUR';
	protected $signedDate;
	protected $status = 'active';
	protected $lastUsedDate;
	protected $createdAt;

	/** RCUR = wiederkehrend, OOFF = einmalig. */
	public const TYPES = ['RCUR', 'OOFF'];
	public const STATUSES = ['active', 'revoked'];

	/** Wurde dieses Mandat schon mindestens einmal eingezogen? */
	public function isFirstUse(): bool {
		return $this->lastUsedDate === null;
	}

	public function displayName(): string {
		return $this->memberLabel ?? ($this->memberUid ?? '');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'memberUid' => $this->memberUid,
			'memberLabel' => $this->memberLabel,
			'iban' => $this->iban,
			'bic' => $this->bic,
			'mandateReference' => $this->mandateReference,
			'mandateType' => $this->mandateType,
			'signedDate' => $this->signedDate,
			'status' => $this->status,
			'lastUsedDate' => $this->lastUsedDate,
			'createdAt' => $this->createdAt,
		];
	}
}
