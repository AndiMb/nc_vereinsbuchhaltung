<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Offener Posten (unbezahlte Forderung, z. B. Mitgliedsbeitrag/Rechnung) mit
 * Fälligkeit. debtor ist Freitext – keine Mitgliederverwaltung in dieser App.
 *
 * mandateId ist die einzige Ausnahme: gesetzt, wenn der Posten aus einem
 * Mitgliedsbeitrag mit SEPA-Mandat stammt (siehe MembershipFeeService) – nur
 * dann ist der Posten für den SEPA-Export überhaupt sichtbar (siehe
 * Version000126). Für alle anderen offenen Posten bleibt es beim Freitext.
 *
 * Seit Migration 000138 (Issue #68) bildet dieselbe Tabelle zusätzlich die
 * „Forderung (Claim)" aus Spec §2.2 ab: die Felder ab {@see getMemberId()}
 * sind additiv und für Alt-Posten (Rechnungen etc.) durchgehend NULL. Eine
 * Forderung erkennt man an gesetztem `memberId` + `type`. Der Zustand einer
 * Forderung ist laut Spec „vollständig abgeleitet, kein Statusfeld" –
 * {@see \OCA\Vereinsbuchhaltung\Service\ClaimStateResolver} leitet ihn aus
 * `status` + den neuen Feldern ab; die bestehende `status`-Spalte bleibt
 * trotzdem die technische Grundlage (Rückwärtskompatibilität mit
 * OpenItemService/SepaBatchService, die weiterhin direkt darauf filtern).
 *
 * @method string getDebtor()
 * @method void setDebtor(string $debtor)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method int getAmountCents()
 * @method void setAmountCents(int $amountCents)
 * @method string|null getDueDate()
 * @method void setDueDate(?string $dueDate)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int|null getAccountId()
 * @method void setAccountId(?int $accountId)
 * @method int|null getPaidJournalId()
 * @method void setPaidJournalId(?int $paidJournalId)
 * @method int|null getMandateId()
 * @method void setMandateId(?int $mandateId)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method int|null getMemberId()
 * @method void setMemberId(?int $memberId)
 * @method string|null getType()
 * @method void setType(?string $type)
 * @method int|null getAssignmentId()
 * @method void setAssignmentId(?int $assignmentId)
 * @method string|null getPeriodStart()
 * @method void setPeriodStart(?string $periodStart)
 * @method string|null getPeriodEnd()
 * @method void setPeriodEnd(?string $periodEnd)
 * @method string|null getPrenotifiedAt()
 * @method void setPrenotifiedAt(?string $prenotifiedAt)
 * @method string|null getDeferredUntil()
 * @method void setDeferredUntil(?string $deferredUntil)
 * @method string|null getDeferredReason()
 * @method void setDeferredReason(?string $deferredReason)
 * @method string|null getDeferredBy()
 * @method void setDeferredBy(?string $deferredBy)
 * @method string|null getDeferredAt()
 * @method void setDeferredAt(?string $deferredAt)
 * @method string|null getSettledAt()
 * @method void setSettledAt(?string $settledAt)
 * @method string|null getSettledBy()
 * @method void setSettledBy(?string $settledBy)
 * @method string|null getSettlementNote()
 * @method void setSettlementNote(?string $settlementNote)
 * @method string|null getCancelledAt()
 * @method void setCancelledAt(?string $cancelledAt)
 * @method string|null getCancelledReason()
 * @method void setCancelledReason(?string $cancelledReason)
 */
class OpenItem extends Entity implements \JsonSerializable {
	protected $debtor;
	protected $description;
	protected $amountCents = 0;
	protected $dueDate;
	protected $status = 'open';
	protected $accountId;
	protected $paidJournalId;
	protected $mandateId;
	protected $createdAt;

	// Additiv seit Migration 000138 (Claim-Modell, Issue #68) – siehe Klassen-Docblock.
	protected $memberId;
	protected $type;
	protected $assignmentId;
	protected $periodStart;
	protected $periodEnd;
	protected $prenotifiedAt;
	protected $deferredUntil;
	protected $deferredReason;
	protected $deferredBy;
	protected $deferredAt;
	protected $settledAt;
	protected $settledBy;
	protected $settlementNote;
	protected $cancelledAt;
	protected $cancelledReason;

	/**
	 * Vierter Wert `waived` seit Issue #68 – bewusst weiterhin englisch: die
	 * drei Bestandswerte stammen aus der Zeit vor der Eindeutschungsregel
	 * (Spec §13.1) und werden nicht rückwirkend geändert, der neue Wert bleibt
	 * innerhalb derselben Spalte konsistent dazu.
	 */
	public const STATUSES = ['open', 'paid', 'cancelled', 'waived'];

	/** Claim-Typ (Spec §2.2 „Forderung"): Beitrag vs. sonstige Gebühr. */
	public const TYPE_CONTRIBUTION = 'beitrag';
	public const TYPE_FEE = 'gebuehr';
	public const TYPES = [self::TYPE_CONTRIBUTION, self::TYPE_FEE];

	public function __construct() {
		$this->addType('amountCents', 'integer');
		$this->addType('accountId', 'integer');
		$this->addType('paidJournalId', 'integer');
		$this->addType('mandateId', 'integer');
		$this->addType('memberId', 'integer');
		$this->addType('assignmentId', 'integer');
	}

	public function isOverdue(): bool {
		return $this->status === 'open' && $this->dueDate !== null && $this->dueDate < date('Y-m-d');
	}

	/** Ob diese Zeile eine Forderung im Sinne von Issue #68 ist (statt eines freien Alt-Postens). */
	public function isClaim(): bool {
		return $this->memberId !== null && $this->type !== null;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'debtor' => $this->debtor,
			'description' => $this->description,
			'amountCents' => $this->amountCents,
			'amount' => $this->amountCents / 100,
			'dueDate' => $this->dueDate,
			'status' => $this->status,
			'accountId' => $this->accountId,
			'paidJournalId' => $this->paidJournalId,
			'mandateId' => $this->mandateId,
			'createdAt' => $this->createdAt,
			'overdue' => $this->isOverdue(),
			'memberId' => $this->memberId,
			'type' => $this->type,
			'assignmentId' => $this->assignmentId,
			'periodStart' => $this->periodStart,
			'periodEnd' => $this->periodEnd,
			'prenotifiedAt' => $this->prenotifiedAt,
			'deferredUntil' => $this->deferredUntil,
			'deferredReason' => $this->deferredReason,
			'deferredBy' => $this->deferredBy,
			'deferredAt' => $this->deferredAt,
			'settledAt' => $this->settledAt,
			'settledBy' => $this->settledBy,
			'settlementNote' => $this->settlementNote,
			'cancelledAt' => $this->cancelledAt,
			'cancelledReason' => $this->cancelledReason,
		];
	}
}
