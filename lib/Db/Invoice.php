<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Rechnung oder Stornorechnung. Eine Rechnung ist in der App ein Dokument
 * plus offener Posten, keine Buchung (Ist-Prinzip, siehe
 * docs/rechnungsmodul-design.md): gebucht wird erst, wenn der Bankumsatz der
 * Zahlung zugeordnet wird.
 *
 * Lebenszyklus: `draft` (frei änderbar, ohne Nummer) → `issued` (festgeschrieben,
 * nummeriert, unveränderlich) → `sent` (per Mail verschickt) → `paid`
 * (vollständig ausgeglichen); `cancelled` nach einer Stornierung. Nach
 * {@see getIssuedAt()} ändert sich der Inhalt nicht mehr (GoBD); nur Status,
 * `sentAt` und die Stornofelder dürfen sich noch ändern.
 *
 * Beträge in Cent. `debtorSnapshot` ist ein JSON-Schnappschuss der
 * Empfängerdaten zum Zeitpunkt der Festschreibung.
 *
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getNumber()
 * @method void setNumber(?string $number)
 * @method int|null getNumberRangeId()
 * @method void setNumberRangeId(?int $numberRangeId)
 * @method string|null getIssueDate()
 * @method void setIssueDate(?string $issueDate)
 * @method string|null getDeliveryDate()
 * @method void setDeliveryDate(?string $deliveryDate)
 * @method string|null getPeriodStart()
 * @method void setPeriodStart(?string $periodStart)
 * @method string|null getPeriodEnd()
 * @method void setPeriodEnd(?string $periodEnd)
 * @method string|null getDueDate()
 * @method void setDueDate(?string $dueDate)
 * @method int|null getDebtorId()
 * @method void setDebtorId(?int $debtorId)
 * @method string|null getDebtorSnapshot()
 * @method void setDebtorSnapshot(?string $debtorSnapshot)
 * @method string getTaxMode()
 * @method void setTaxMode(string $taxMode)
 * @method string|null getExemptionText()
 * @method void setExemptionText(?string $exemptionText)
 * @method string|null getBuyerReference()
 * @method void setBuyerReference(?string $buyerReference)
 * @method string|null getOrderReference()
 * @method void setOrderReference(?string $orderReference)
 * @method string|null getPaymentTerms()
 * @method void setPaymentTerms(?string $paymentTerms)
 * @method string|null getNoteHead()
 * @method void setNoteHead(?string $noteHead)
 * @method string|null getNoteFoot()
 * @method void setNoteFoot(?string $noteFoot)
 * @method int getNetCents()
 * @method void setNetCents(int $netCents)
 * @method int getTaxCents()
 * @method void setTaxCents(int $taxCents)
 * @method int getGrossCents()
 * @method void setGrossCents(int $grossCents)
 * @method string getCurrency()
 * @method void setCurrency(string $currency)
 * @method int|null getPrecedingInvoiceId()
 * @method void setPrecedingInvoiceId(?int $precedingInvoiceId)
 * @method int|null getOpenItemId()
 * @method void setOpenItemId(?int $openItemId)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 * @method string|null getIssuedAt()
 * @method void setIssuedAt(?string $issuedAt)
 * @method string|null getIssuedBy()
 * @method void setIssuedBy(?string $issuedBy)
 * @method string|null getSentAt()
 * @method void setSentAt(?string $sentAt)
 * @method string|null getCancelledAt()
 * @method void setCancelledAt(?string $cancelledAt)
 * @method string|null getCancelReason()
 * @method void setCancelReason(?string $cancelReason)
 */
class Invoice extends Entity implements \JsonSerializable {
	public const KIND_INVOICE = 'rechnung';
	public const KIND_CREDIT = 'storno';
	public const KINDS = [self::KIND_INVOICE, self::KIND_CREDIT];

	public const STATUS_DRAFT = 'draft';
	public const STATUS_ISSUED = 'issued';
	public const STATUS_SENT = 'sent';
	public const STATUS_PAID = 'paid';
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUSES = [
		self::STATUS_DRAFT,
		self::STATUS_ISSUED,
		self::STATUS_SENT,
		self::STATUS_PAID,
		self::STATUS_CANCELLED,
	];

	/** Regelbesteuerung (Stufe 2): Steuersatz je Position. */
	public const TAX_REGULAR = 'regular';
	/** Kleinunternehmer nach § 19 UStG: kein Steuerausweis, Pflichthinweis. */
	public const TAX_SMALL_BUSINESS = 'kleinunternehmer';
	/** Steuerbefreiung nach § 4 UStG: kein Steuerausweis, Befreiungstext. */
	public const TAX_EXEMPT = 'exempt';
	public const TAX_MODES = [self::TAX_REGULAR, self::TAX_SMALL_BUSINESS, self::TAX_EXEMPT];

	protected $kind = self::KIND_INVOICE;
	protected $status = self::STATUS_DRAFT;
	protected $number;
	protected $numberRangeId;
	protected $issueDate;
	protected $deliveryDate;
	protected $periodStart;
	protected $periodEnd;
	protected $dueDate;
	protected $debtorId;
	protected $debtorSnapshot;
	protected $taxMode = self::TAX_SMALL_BUSINESS;
	protected $exemptionText;
	protected $buyerReference;
	protected $orderReference;
	protected $paymentTerms;
	protected $noteHead;
	protected $noteFoot;
	protected $netCents = 0;
	protected $taxCents = 0;
	protected $grossCents = 0;
	protected $currency = 'EUR';
	protected $precedingInvoiceId;
	protected $openItemId;
	protected $createdBy;
	protected $createdAt;
	protected $updatedAt;
	protected $issuedAt;
	protected $issuedBy;
	protected $sentAt;
	protected $cancelledAt;
	protected $cancelReason;

	public function __construct() {
		$this->addType('numberRangeId', 'integer');
		$this->addType('debtorId', 'integer');
		$this->addType('netCents', 'integer');
		$this->addType('taxCents', 'integer');
		$this->addType('grossCents', 'integer');
		$this->addType('precedingInvoiceId', 'integer');
		$this->addType('openItemId', 'integer');
	}

	public function isDraft(): bool {
		return $this->status === self::STATUS_DRAFT;
	}

	/** Festgeschrieben: Inhalt ist unveränderlich. */
	public function isIssued(): bool {
		return $this->issuedAt !== null;
	}

	public function isCredit(): bool {
		return $this->kind === self::KIND_CREDIT;
	}

	/** @return array<string, mixed>|null */
	public function debtorSnapshotData(): ?array {
		if ($this->debtorSnapshot === null || $this->debtorSnapshot === '') {
			return null;
		}
		$data = json_decode($this->debtorSnapshot, true);
		return is_array($data) ? $data : null;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'kind' => $this->kind,
			'status' => $this->status,
			'number' => $this->number,
			'numberRangeId' => $this->numberRangeId,
			'issueDate' => $this->issueDate,
			'deliveryDate' => $this->deliveryDate,
			'periodStart' => $this->periodStart,
			'periodEnd' => $this->periodEnd,
			'dueDate' => $this->dueDate,
			'debtorId' => $this->debtorId,
			'debtor' => $this->debtorSnapshotData(),
			'taxMode' => $this->taxMode,
			'exemptionText' => $this->exemptionText,
			'buyerReference' => $this->buyerReference,
			'orderReference' => $this->orderReference,
			'paymentTerms' => $this->paymentTerms,
			'noteHead' => $this->noteHead,
			'noteFoot' => $this->noteFoot,
			'netCents' => $this->netCents,
			'taxCents' => $this->taxCents,
			'grossCents' => $this->grossCents,
			'currency' => $this->currency,
			'precedingInvoiceId' => $this->precedingInvoiceId,
			'openItemId' => $this->openItemId,
			'createdBy' => $this->createdBy,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'issuedAt' => $this->issuedAt,
			'issuedBy' => $this->issuedBy,
			'sentAt' => $this->sentAt,
			'cancelledAt' => $this->cancelledAt,
			'cancelReason' => $this->cancelReason,
		];
	}
}
