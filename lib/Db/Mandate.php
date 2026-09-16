<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * SEPA-Lastschriftmandat, voller Lebenszyklus – siehe Spec §2.2 „Mandat
 * (Mandate)" und §3.2 „Mandats-Lifecycle" unter docs/beitraege-sepa-modul-spec.md,
 * Issue #66. Löst das bisherige, flache `SepaMandate`/`vbh_sepa_mandates`
 * *nicht* ab (das bleibt vorerst am alten Einzugszyklus hängen, siehe
 * {@see SepaMandate}) – ein bewusst neues, additives Tabellenpaar
 * (`vbh_mandates`, `vbh_mandate_amendments`, `vbh_mandate_events`), das erst
 * mit dem Einzugszyklus-Ticket (T10) den alten Bestand ablöst. Bis dahin
 * existieren beide Mandatssysteme nebeneinander.
 *
 * Enum-Sprache (siehe Spec §13.1 und Entscheidung Florian 2026-09-16): die
 * dort *ausdrücklich genannten* Übersetzungen `draft/entwurf/aktiv/
 * ausgesetzt/erloschen` werden übernommen; nach demselben Muster (deutsch wo
 * Fachbegriff) auch `signature_type` (`papier`/`elektronisch`, `qes` bleibt
 * die international übliche Abkürzung) und `suspension_origin`
 * (`manuell`/`ruecklastschrift` – „Rücklastschrift" ist bereits der
 * durchgängige deutsche Fachbegriff dieser Spec). `end_reason` folgt
 * `status` konsistent: `widerrufen`/`ersetzt`/`verfallen`/`beendet`.
 * `MandateAmendment::TYPE_*`/`::STATUS_*` und `MandateEvent::ACTOR_*` bleiben
 * dagegen englisch, wie im Issue-Feldkatalog selbst angegeben (dort nicht als
 * klärungsbedürftig markiert) – siehe {@see MandateAmendment}, {@see MandateEvent}.
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
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
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
	protected $createdAt;

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

	/** „Verfallen" ist kein eigener Zustand: END_REASON_EXPIRED landet in status=erloschen (Spec §2.2). */
	public const END_REASON_REVOKED = 'widerrufen';
	public const END_REASON_REPLACED = 'ersetzt';
	public const END_REASON_EXPIRED = 'verfallen';
	public const END_REASON_TERMINATED = 'beendet';
	public const END_REASONS = [self::END_REASON_REVOKED, self::END_REASON_REPLACED, self::END_REASON_EXPIRED, self::END_REASON_TERMINATED];

	/** `ruecklastschrift` erst ab Issue #73 tatsächlich auslösbar (Rücklastschrift-Fachlogik). */
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

	/**
	 * Nur ein `aktives` Mandat ist einzugsfähig (Spec §2.2 Zustandsmodell) –
	 * `entwurf`/`ausgesetzt`/`erloschen` sind es ausdrücklich nicht.
	 */
	public function isCollectible(): bool {
		return $this->status === self::STATUS_ACTIVE;
	}

	/**
	 * IBAN mit maskierter Mitte für die Revisor-Ansicht (Spec §3.9: „Einzug-
	 * Unterreiter lesend … IBAN maskiert"). Ländercode/Prüfziffer und die
	 * letzten vier Stellen bleiben sichtbar – genug, um ein Mandat wieder-
	 * zuerkennen, ohne die vollständige Kontonummer preiszugeben.
	 */
	public function maskedIban(): ?string {
		if ($this->iban === null || $this->iban === '') {
			return $this->iban;
		}
		$len = strlen($this->iban);
		if ($len <= 8) {
			return str_repeat('•', $len);
		}
		return substr($this->iban, 0, 4) . str_repeat('•', $len - 8) . substr($this->iban, -4);
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
			'sequenceType' => self::SEQUENCE_TYPE,
			'isCollectible' => $this->isCollectible(),
			'createdAt' => $this->createdAt,
		];
	}
}
