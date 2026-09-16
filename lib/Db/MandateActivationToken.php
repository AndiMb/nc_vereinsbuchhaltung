<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Der signierte E-Mail-Einmal-Link für die elektronische Mandatserteilung
 * (Spec §2.2/§8, Issue #67) – funktioniert bewusst OHNE Login, damit auch ein
 * Mitglied ohne NC-Konto ein Mandat elektronisch erteilen kann.
 *
 * Selector/Validator-Muster (wie bei Passwort-Reset-Tokens): `selector` ist
 * der öffentliche, indizierbare Teil (schnelles Auffinden der Zeile),
 * `validatorHash` der SHA-256-Hash des eigentlichen Geheimnisses. Der
 * Klartext-Validator existiert nur einmal – in der versendeten Mail – und
 * wird nie persistiert. Damit macht ein DB-Zugriff allein (Backup-Leck,
 * Fehlkonfiguration) den Link nicht nutzbar, siehe
 * {@see \OCA\Vereinsbuchhaltung\Service\MandateActivationService}.
 *
 * Zeitlich begrenzt (`expiresAt`) UND einmal verwendbar (`consumedAt`) –
 * beides zusammen, nicht nur eines: eine reine Ablauffrist ließe einen noch
 * gültigen Link mehrfach verwenden (z.B. wenn er versehentlich weitergeleitet
 * wird), eine reine Einmal-Nutzung ohne Frist ließe einen nie geklickten Link
 * unbegrenzt lange als Angriffsfläche stehen.
 *
 * @method int getMandateId()
 * @method void setMandateId(int $mandateId)
 * @method string getSelector()
 * @method void setSelector(string $selector)
 * @method string getValidatorHash()
 * @method void setValidatorHash(string $validatorHash)
 * @method string getEmail()
 * @method void setEmail(string $email)
 * @method int|null getLegalTextVersionId()
 * @method void setLegalTextVersionId(?int $legalTextVersionId)
 * @method string|null getRequestedBy()
 * @method void setRequestedBy(?string $requestedBy)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getExpiresAt()
 * @method void setExpiresAt(string $expiresAt)
 * @method string|null getFirstViewedAt()
 * @method void setFirstViewedAt(?string $firstViewedAt)
 * @method string|null getConsumedAt()
 * @method void setConsumedAt(?string $consumedAt)
 */
class MandateActivationToken extends Entity implements \JsonSerializable {
	protected $mandateId;
	protected $selector;
	protected $validatorHash;
	protected $email;
	protected $legalTextVersionId;
	protected $requestedBy;
	protected $createdAt;
	protected $expiresAt;
	protected $firstViewedAt;
	protected $consumedAt;

	/**
	 * Gültigkeitsdauer des Links (Tage). Bewusst deckungsgleich mit dem
	 * Aufgaben-Namen aus Issue #67 ("Link ≥14 Tage alt"): der Link IST nach
	 * 14 Tagen abgelaufen, die Aufgabe meldet also exakt "dieser Link ist
	 * jetzt nutzlos, jemand muss reagieren" statt einer künstlich zweiten
	 * Frist neben der technischen Gültigkeit.
	 */
	public const VALIDITY_DAYS = 14;

	public function isExpired(\DateTimeImmutable $now): bool {
		return $this->expiresAt < $now->format('Y-m-d H:i:s');
	}

	public function isConsumed(): bool {
		return $this->consumedAt !== null;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'mandateId' => $this->mandateId,
			'email' => $this->email,
			'requestedBy' => $this->requestedBy,
			'createdAt' => $this->createdAt,
			'expiresAt' => $this->expiresAt,
			'firstViewedAt' => $this->firstViewedAt,
			'consumedAt' => $this->consumedAt,
		];
	}
}
