<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * SEPA-Lastschriftmandat, voller Lebenszyklus – siehe Spec §2.2 „Mandat
 * (Mandate)" und §3.2 „Mandats-Lifecycle" unter docs/beitraege-sepa-modul-spec.md,
 * Issue #66. Löste das frühere, flache `vbh_sepa_mandates` ab – ein bewusst
 * neues Tabellenpaar (`vbh_mandates`, `vbh_mandate_amendments`,
 * `vbh_mandate_events`) statt eines Umbaus der alten Tabelle, die erst mit
 * dem Cutover (Issue #107, Migration 000157) wegfiel.
 *
 * Enum-Sprache (siehe Spec §13.1 und Entscheidung Florian 2026-09-16): die
 * dort *ausdrücklich genannten* Übersetzungen `draft/entwurf/aktiv/
 * ausgesetzt/erloschen` werden übernommen; nach demselben Muster (deutsch wo
 * Fachbegriff) auch `signature_type` (`papier`/`elektronisch`, `qes` bleibt
 * die international übliche Abkürzung) und `suspension_origin`
 * (`manuell`/`ruecklastschrift` – „Rücklastschrift" ist bereits der
 * durchgängige deutsche Fachbegriff dieser Spec). `end_reason` folgt
 * `status` konsistent: `widerrufen`/`ersetzt`/`verfallen`/`beendet`, seit
 * Issue #118 dazu `verworfen` (ein Entwurf, der nie wirksam wurde).
 * `MandateAmendment::TYPE_*`/`::STATUS_*` und `MandateEvent::ACTOR_*` bleiben
 * dagegen englisch, wie im Issue-Feldkatalog selbst angegeben (dort nicht als
 * klärungsbedürftig markiert) – siehe {@see MandateAmendment}, {@see MandateEvent}.
 *
 * Beweispaket der elektronischen Erteilung (Spec §2.2/§8, Issue #67):
 * `mandate_text_version`/`consent_at`/`consent_ip`/`consent_user_agent`/
 * `consent_actor`, gefüllt von
 * {@see \OCA\Vereinsbuchhaltung\Service\MandateActivationService::consent()}.
 * `consent_actor` ist bewusst KEIN Enum, sondern die tatsächlich verwendete
 * Mailadresse: bei einem anonymen Einmal-Link (kein NC-Konto nötig) ist die
 * Zustelladresse des Links die einzige belastbare Identitätsspur, die Spec §8
 * als viertes Beweispaket-Element ("Identität") verlangt.
 *
 * @method int getMemberId()
 * @method void setMemberId(int $memberId)
 * @method string getMandateReference()
 * @method void setMandateReference(string $mandateReference)
 * @method string|null getIban()
 * @method void setIban(?string $iban)
 * @method string|null getBic()
 * @method void setBic(?string $bic)
 * @method string getAccountHolder()
 * @method void setAccountHolder(string $accountHolder)
 * @method string getSignatureType()
 * @method void setSignatureType(string $signatureType)
 * @method string|null getSignedAt()
 * @method void setSignedAt(?string $signedAt)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getActivatedAt()
 * @method void setActivatedAt(?string $activatedAt)
 * @method string|null getEndedAt()
 * @method void setEndedAt(?string $endedAt)
 * @method string|null getEndReason()
 * @method void setEndReason(?string $endReason)
 * @method string|null getSuspendedAt()
 * @method void setSuspendedAt(?string $suspendedAt)
 * @method string|null getSuspendedBy()
 * @method void setSuspendedBy(?string $suspendedBy)
 * @method string|null getSuspensionOrigin()
 * @method void setSuspensionOrigin(?string $suspensionOrigin)
 * @method string|null getSuspensionNote()
 * @method void setSuspensionNote(?string $suspensionNote)
 * @method string|null getLastPresentedDueDate()
 * @method void setLastPresentedDueDate(?string $lastPresentedDueDate)
 * @method int|null getDocumentFileId()
 * @method void setDocumentFileId(?int $documentFileId)
 * @method int|null getReturnedDebitId()
 * @method void setReturnedDebitId(?int $returnedDebitId)
 * @method int|null getMandateTextVersion()
 * @method void setMandateTextVersion(?int $mandateTextVersion)
 * @method string|null getConsentAt()
 * @method void setConsentAt(?string $consentAt)
 * @method string|null getConsentIp()
 * @method void setConsentIp(?string $consentIp)
 * @method string|null getConsentUserAgent()
 * @method void setConsentUserAgent(?string $consentUserAgent)
 * @method string|null getConsentActor()
 * @method void setConsentActor(?string $consentActor)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string|null getRedactedAt()
 * @method void setRedactedAt(?string $redactedAt)
 */
class Mandate extends Entity implements \JsonSerializable {
	protected $memberId;
	protected $mandateReference;
	protected $iban;
	protected $bic;
	protected $accountHolder;
	protected $signatureType = self::SIGNATURE_PAPER;
	protected $signedAt;
	protected $status = self::STATUS_DRAFT;
	protected $activatedAt;
	protected $endedAt;
	protected $endReason;
	protected $suspendedAt;
	protected $suspendedBy;
	protected $suspensionOrigin;
	protected $suspensionNote;
	protected $lastPresentedDueDate;
	protected $documentFileId;
	protected $returnedDebitId;
	protected $mandateTextVersion;
	protected $consentAt;
	protected $consentIp;
	protected $consentUserAgent;
	protected $consentActor;
	protected $createdAt;
	// DSGVO-Anonymisierung (Spec §3.8, Issue #78) - siehe MemberAnonymizationService.
	protected $redactedAt;

	/**
	 * Platzhalter für `account_holder` nach der Anonymisierung - die Spalte
	 * ist Pflicht (siehe Version000140), anders als `iban`/`bic` kann sie
	 * deshalb nicht einfach geleert werden.
	 */
	public const REDACTED_ACCOUNT_HOLDER = '(anonymisiert)';

	/** Nur `papier` ist in diesem Ticket (#66) tatsächlich nutzbar; die anderen beiden sind Enum-Vorgriffe auf #67 (Einmal-Link) und QES (nicht v1). */
	public const SIGNATURE_PAPER = 'papier';
	public const SIGNATURE_ELECTRONIC = 'elektronisch';
	public const SIGNATURE_QES = 'qes';
	public const SIGNATURE_TYPES = [self::SIGNATURE_PAPER, self::SIGNATURE_ELECTRONIC, self::SIGNATURE_QES];

	public const STATUS_DRAFT = 'entwurf';
	public const STATUS_ACTIVE = 'aktiv';
	public const STATUS_SUSPENDED = 'ausgesetzt';
	public const STATUS_ENDED = 'erloschen';
	public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_ENDED];

	/**
	 * „Verfallen" ist kein eigener Zustand: END_REASON_EXPIRED landet in status=erloschen (Spec §2.2).
	 *
	 * `verworfen` (Issue #118): ein Entwurf wird verworfen, bevor er je wirksam
	 * war. Bewusst ein eigener Wert statt `widerrufen` oder `beendet` – ein
	 * Widerruf nimmt eine *erteilte* Einzugsermächtigung zurück (und löst die
	 * Zahlungsaufforderung bei offenen Forderungen aus), `beendet` ist der
	 * automatische Austritts-Übergang; beides wäre für einen Entwurf, der nie
	 * einzugsfähig war und nie etwas eingezogen hat, eine falsche Auskunft.
	 */
	public const END_REASON_REVOKED = 'widerrufen';
	public const END_REASON_REPLACED = 'ersetzt';
	public const END_REASON_EXPIRED = 'verfallen';
	public const END_REASON_TERMINATED = 'beendet';
	public const END_REASON_DISCARDED = 'verworfen';
	public const END_REASONS = [
		self::END_REASON_REVOKED,
		self::END_REASON_REPLACED,
		self::END_REASON_EXPIRED,
		self::END_REASON_TERMINATED,
		self::END_REASON_DISCARDED,
	];

	/** `ruecklastschrift` wird ausschließlich automatisch gesetzt, siehe {@see \OCA\Vereinsbuchhaltung\Service\MandateService::suspendDueToReturnedDebit()} (Issue #73, Rücklastschrift-Fachlogik). */
	public const SUSPENSION_MANUAL = 'manuell';
	public const SUSPENSION_RETURNED_DEBIT = 'ruecklastschrift';
	public const SUSPENSION_ORIGINS = [self::SUSPENSION_MANUAL, self::SUSPENSION_RETURNED_DEBIT];

	/** Immer RCUR, nie FRST (Compliance-Anhang Spec §8) – kein Konfigurationsschalter, deshalb keine Spalte, nur diese Konstante. */
	public const SEQUENCE_TYPE = 'RCUR';

	/** Mandatsverfall 36 Monate nach der letzten Vorlage bzw. Unterschrift (Spec §2.2/§8). */
	public const EXPIRY_MONTHS = 36;

	public function isLive(): bool {
		return $this->status !== self::STATUS_ENDED;
	}

	public function isRedacted(): bool {
		return $this->redactedAt !== null;
	}

	/**
	 * Nur ein `aktives` Mandat ist einzugsfähig (Spec §2.2 Zustandsmodell) –
	 * `entwurf`/`ausgesetzt`/`erloschen` sind es ausdrücklich nicht.
	 */
	public function isCollectible(): bool {
		return $this->status === self::STATUS_ACTIVE;
	}

	/** Ob dieses Mandat den elektronischen Erteilungsweg nimmt (Issue #67). */
	public function isElectronic(): bool {
		return $this->signatureType === self::SIGNATURE_ELECTRONIC;
	}

	/**
	 * IBAN mit maskierter Mitte für die Revisor-Ansicht (Spec §3.9: „Einzug-
	 * Unterreiter lesend … IBAN maskiert"). Ländercode/Prüfziffer und die
	 * letzten vier Stellen bleiben sichtbar – genug, um ein Mandat wieder-
	 * zuerkennen, ohne die vollständige Kontonummer preiszugeben.
	 */
	public function maskedIban(): ?string {
		return self::maskIban($this->iban);
	}

	/**
	 * Dieselbe Maskierung für einen beliebigen IBAN-Wert – für Ereignistexte der
	 * Historie, die auch der Revisor liest (Issue #118: „IBAN alt → neu“ einer
	 * Entwurfskorrektur soll keine volle Kontonummer in den Verlauf schreiben).
	 */
	public static function maskIban(?string $iban): ?string {
		if ($iban === null || $iban === '') {
			return $iban;
		}
		$len = strlen($iban);
		if ($len <= 8) {
			return str_repeat('•', $len);
		}
		return substr($iban, 0, 4) . str_repeat('•', $len - 8) . substr($iban, -4);
	}

	/**
	 * Störfall-Text je Zustand (Spec §3.2/§7). `aktiv` hat keinen – ein
	 * aktives Mandat ohne weitere Auffälligkeit ist kein Störfall.
	 */
	public function storyText(\OCP\IL10N $l10n): ?string {
		return match ($this->status) {
			self::STATUS_DRAFT => $l10n->t('Unterschrift fehlt'),
			self::STATUS_SUSPENDED => $l10n->t('Klärung offen'),
			self::STATUS_ENDED => $l10n->t('neues Mandat einholen'),
			default => null,
		};
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'memberId' => $this->memberId,
			'mandateReference' => $this->mandateReference,
			'iban' => $this->iban,
			'bic' => $this->bic,
			'accountHolder' => $this->accountHolder,
			'signatureType' => $this->signatureType,
			'signedAt' => $this->signedAt,
			'status' => $this->status,
			'activatedAt' => $this->activatedAt,
			'endedAt' => $this->endedAt,
			'endReason' => $this->endReason,
			'suspendedAt' => $this->suspendedAt,
			'suspendedBy' => $this->suspendedBy,
			'suspensionOrigin' => $this->suspensionOrigin,
			'suspensionNote' => $this->suspensionNote,
			'lastPresentedDueDate' => $this->lastPresentedDueDate,
			'documentFileId' => $this->documentFileId,
			'returnedDebitId' => $this->returnedDebitId,
			'mandateTextVersion' => $this->mandateTextVersion,
			'consentAt' => $this->consentAt,
			'consentIp' => $this->consentIp,
			'consentUserAgent' => $this->consentUserAgent,
			'consentActor' => $this->consentActor,
			'sequenceType' => self::SEQUENCE_TYPE,
			'isCollectible' => $this->isCollectible(),
			'createdAt' => $this->createdAt,
			'redactedAt' => $this->redactedAt,
		];
	}
}
