<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Mitglied – unabhängig von einem Nextcloud-Konto, siehe Spec §2.2 (Member)
 * unter docs/beitraege-sepa-modul-spec.md. Löst die bisherige Modellierung
 * über member_uid/member_label an vbh_sepa_mandates/vbh_membership_fees ab
 * (siehe Migration 000137/000138): ein Mitglied existiert jetzt als eigener
 * Datensatz, ein SEPA-Mandat oder Beitrag verweist per member_id darauf.
 *
 * Enum-Sprachentscheidung (siehe Spec §13.1, dort als offener Klärungsbedarf
 * markiert): `member_type` folgt dem Bestandsmuster „deutsch wo Fachbegriff" –
 * `person` ist in beiden Sprachen gleich geschrieben, `organisation` in der
 * deutschen statt der englischen Schreibweise (`organization`), analog zu den
 * bereits eingedeutschten Rollen `revisor`/`buchhalter`/`verwalter`.
 *
 * @method string getMemberType()
 * @method void setMemberType(string $memberType)
 * @method string|null getFirstName()
 * @method void setFirstName(?string $firstName)
 * @method string|null getLastName()
 * @method void setLastName(?string $lastName)
 * @method string|null getOrganizationName()
 * @method void setOrganizationName(?string $organizationName)
 * @method string|null getEmail()
 * @method void setEmail(?string $email)
 * @method string|null getPhone()
 * @method void setPhone(?string $phone)
 * @method string|null getStreet()
 * @method void setStreet(?string $street)
 * @method string|null getPostalCode()
 * @method void setPostalCode(?string $postalCode)
 * @method string|null getCity()
 * @method void setCity(?string $city)
 * @method string|null getCountry()
 * @method void setCountry(?string $country)
 * @method string|null getMemberNumber()
 * @method void setMemberNumber(?string $memberNumber)
 * @method string getJoinedAt()
 * @method void setJoinedAt(string $joinedAt)
 * @method string|null getLeftAt()
 * @method void setLeftAt(?string $leftAt)
 * @method string|null getNcUserId()
 * @method void setNcUserId(?string $ncUserId)
 * @method string|null getInternalNote()
 * @method void setInternalNote(?string $internalNote)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string|null getRedactedAt()
 * @method void setRedactedAt(?string $redactedAt)
 */
class Member extends Entity implements \JsonSerializable {
	protected $memberType = self::TYPE_PERSON;
	protected $firstName;
	protected $lastName;
	protected $organizationName;
	protected $email;
	protected $phone;
	protected $street;
	protected $postalCode;
	protected $city;
	protected $country;
	protected $memberNumber;
	protected $joinedAt;
	protected $leftAt;
	protected $ncUserId;
	protected $internalNote;
	protected $createdAt;
	// DSGVO-Anonymisierung (Spec §3.8, Issue #78) - siehe MemberAnonymizationService.
	protected $redactedAt;

	public const TYPE_PERSON = 'person';
	public const TYPE_ORGANIZATION = 'organisation';
	public const TYPES = [self::TYPE_PERSON, self::TYPE_ORGANIZATION];

	/** Anzeigename eines anonymisierten Mitglieds (Spec §3.8) - Name/Kontakt sind geschwärzt, die Mitglieds-ID bleibt der einzige Wiedererkennungswert. */
	public const REDACTED_DISPLAY_NAME = 'Anonymisiertes Mitglied';

	public function isRedacted(): bool {
		return $this->redactedAt !== null;
	}

	/**
	 * Anzeigename je nach Mitgliedstyp: bei einer Person Vor- und Nachname,
	 * bei einer Organisation deren Name. Bewusst nicht über getName() – die
	 * Fachlogik unterscheidet die beiden Fälle ausdrücklich (siehe
	 * Feldkatalog Spec §2.2).
	 *
	 * Nach einer DSGVO-Anonymisierung (Spec §3.8) sind Name/Kontakt geschwärzt
	 * (null) - ohne diese Ausnahme läge hier ein irreführender Leerstring,
	 * überall dort, wo displayName() auch weiterhin auftaucht (z.B. eingefroren
	 * in {@see \OCA\Vereinsbuchhaltung\Db\OpenItem::getDebtor()} vor der
	 * Anonymisierung selbst schon, oder in Listen, die trotzdem eine Zeile
	 * brauchen).
	 */
	public function displayName(): string {
		if ($this->isRedacted()) {
			return self::REDACTED_DISPLAY_NAME;
		}
		if ($this->memberType === self::TYPE_ORGANIZATION) {
			return (string)($this->organizationName ?? '');
		}
		return trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));
	}

	/**
	 * „der Zeitraum ist der Status" (Spec §2.2/§3.1): kein Archivfeld, ein
	 * left_at in der Vergangenheit *ist* der Status „ausgetreten". Am
	 * Austrittstag selbst und davor ist das Mitglied noch aktiv – „in der
	 * Vergangenheit" beginnt erst am Tag danach, analog zu einer Mitgliedschaft
	 * „bis einschließlich" dem genannten Datum. Ein left_at in der Zukunft ist
	 * bereits angekündigt, aber noch aktiv.
	 */
	public function isActive(?string $today = null): bool {
		if ($this->leftAt === null) {
			return true;
		}
		$today ??= (new \DateTime())->format('Y-m-d');
		return $this->leftAt >= $today;
	}

	/**
	 * Ob eine für den Postversand ausreichende Anschrift hinterlegt ist
	 * (Straße, PLZ, Ort) – `country` bleibt außen vor, viele Vereine lassen es
	 * für inländische Mitglieder leer. Grundlage für den Hinweis-Banner
	 * „Adresse jetzt hinterlegen" der Beitragsbestätigung (Spec §3.7, Issue
	 * #77) statt einer Admin-Aufgabe.
	 */
	public function hasAddress(): bool {
		return trim((string)$this->street) !== ''
			&& trim((string)$this->postalCode) !== ''
			&& trim((string)$this->city) !== '';
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'memberType' => $this->memberType,
			'firstName' => $this->firstName,
			'lastName' => $this->lastName,
			'organizationName' => $this->organizationName,
			'displayName' => $this->displayName(),
			'email' => $this->email,
			'phone' => $this->phone,
			'street' => $this->street,
			'postalCode' => $this->postalCode,
			'city' => $this->city,
			'country' => $this->country,
			'memberNumber' => $this->memberNumber,
			'joinedAt' => $this->joinedAt,
			'leftAt' => $this->leftAt,
			'active' => $this->isActive(),
			'ncUserId' => $this->ncUserId,
			'internalNote' => $this->internalNote,
			'createdAt' => $this->createdAt,
			'redactedAt' => $this->redactedAt,
		];
	}
}
