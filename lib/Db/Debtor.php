<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Rechnungsempfänger (Debitor) des Rechnungsmoduls. Eigene Stammdaten statt
 * Freitext, damit Adresse, Leitweg-ID und Kundennummer wiederverwendbar sind.
 * Optional mit einem Mitglied verknüpft ({@see getMemberId()}); die Adresse
 * wird beim Festschreiben einer Rechnung als Schnappschuss in die Rechnung
 * kopiert, spätere Änderungen am Debitor verändern sie nicht.
 *
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getName2()
 * @method void setName2(?string $name2)
 * @method string|null getStreet()
 * @method void setStreet(?string $street)
 * @method string|null getPostalCode()
 * @method void setPostalCode(?string $postalCode)
 * @method string|null getCity()
 * @method void setCity(?string $city)
 * @method string getCountry()
 * @method void setCountry(string $country)
 * @method string|null getEmail()
 * @method void setEmail(?string $email)
 * @method string|null getVatId()
 * @method void setVatId(?string $vatId)
 * @method string|null getLeitwegId()
 * @method void setLeitwegId(?string $leitwegId)
 * @method string|null getCustomerNo()
 * @method void setCustomerNo(?string $customerNo)
 * @method int|null getMemberId()
 * @method void setMemberId(?int $memberId)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string|null getRedactedAt()
 * @method void setRedactedAt(?string $redactedAt)
 */
class Debtor extends Entity implements \JsonSerializable {
	public const KIND_PERSON = 'person';
	public const KIND_ORGANISATION = 'organisation';
	public const KIND_AUTHORITY = 'behoerde';
	public const KINDS = [self::KIND_PERSON, self::KIND_ORGANISATION, self::KIND_AUTHORITY];

	protected $kind = self::KIND_ORGANISATION;
	protected $name;
	protected $name2;
	protected $street;
	protected $postalCode;
	protected $city;
	protected $country = 'DE';
	protected $email;
	protected $vatId;
	protected $leitwegId;
	protected $customerNo;
	protected $memberId;
	protected $note;
	protected $createdAt;
	protected $redactedAt;

	public function __construct() {
		$this->addType('memberId', 'integer');
	}

	/**
	 * Adresse und Kennungen, wie sie in eine festgeschriebene Rechnung kopiert
	 * werden (Schnappschuss).
	 *
	 * @return array<string, mixed>
	 */
	public function toSnapshot(): array {
		return [
			'id' => $this->id,
			'kind' => $this->kind,
			'name' => $this->name,
			'name2' => $this->name2,
			'street' => $this->street,
			'postalCode' => $this->postalCode,
			'city' => $this->city,
			'country' => $this->country,
			'email' => $this->email,
			'vatId' => $this->vatId,
			'leitwegId' => $this->leitwegId,
			'customerNo' => $this->customerNo,
		];
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'kind' => $this->kind,
			'name' => $this->name,
			'name2' => $this->name2,
			'street' => $this->street,
			'postalCode' => $this->postalCode,
			'city' => $this->city,
			'country' => $this->country,
			'email' => $this->email,
			'vatId' => $this->vatId,
			'leitwegId' => $this->leitwegId,
			'customerNo' => $this->customerNo,
			'memberId' => $this->memberId,
			'note' => $this->note,
			'createdAt' => $this->createdAt,
			'redactedAt' => $this->redactedAt,
		];
	}
}
