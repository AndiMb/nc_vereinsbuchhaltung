<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Auslagenantrag eines Mitglieds (docs/reimbursement-design.md).
 *
 * `requiredApprovals` ist das N zum Zeitpunkt der Einreichung: ändert ein
 * Admin die Einstellung später, entscheidet der Antrag nach den Regeln, die
 * beim Einreichen galten. Die Freigaben selbst stehen als Ereignisse in
 * `vbh_reimbursement_events`; hier steht nur der daraus folgende Status.
 *
 * `iban` liegt verschlüsselt (siehe {@see ReimbursementMapper}); die Entity
 * hält den Klartext.
 *
 * @method int getMemberId()
 * @method void setMemberId(int $memberId)
 * @method int getAmountCents()
 * @method void setAmountCents(int $amountCents)
 * @method string getExpenseDate()
 * @method void setExpenseDate(string $expenseDate)
 * @method string getPurpose()
 * @method void setPurpose(string $purpose)
 * @method string getIban()
 * @method void setIban(string $iban)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int getRequiredApprovals()
 * @method void setRequiredApprovals(int $requiredApprovals)
 * @method string getSubmittedBy()
 * @method void setSubmittedBy(string $submittedBy)
 * @method string getSubmittedAt()
 * @method void setSubmittedAt(string $submittedAt)
 * @method string|null getDecidedAt()
 * @method void setDecidedAt(?string $decidedAt)
 * @method string|null getPaidAt()
 * @method void setPaidAt(?string $paidAt)
 * @method string|null getRejectionReason()
 * @method void setRejectionReason(?string $rejectionReason)
 * @method int|null getOpenItemId()
 * @method void setOpenItemId(?int $openItemId)
 * @method int|null getJournalId()
 * @method void setJournalId(?int $journalId)
 */
class Reimbursement extends Entity implements \JsonSerializable {
	protected $memberId;
	protected $amountCents;
	protected $expenseDate;
	protected $purpose;
	protected $iban;
	// Bewusst ohne Vorgabewert: eine Entity schreibt nur Felder, die sich geändert haben. Mit
	// Vorgabe `submitted` bliebe der Status beim Anlegen unmarkiert und fehlte im INSERT.
	protected $status;
	protected $requiredApprovals;
	protected $submittedBy;
	protected $submittedAt;
	protected $decidedAt;
	protected $paidAt;
	protected $rejectionReason;
	protected $openItemId;
	protected $journalId;

	public const STATUS_SUBMITTED = 'submitted';
	public const STATUS_PARTIALLY_APPROVED = 'partially_approved';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_PAID = 'paid';
	public const STATUS_REJECTED = 'rejected';
	public const STATUS_WITHDRAWN = 'withdrawn';
	/** Noch nicht abgeschlossen: blockiert die Anonymisierung des Mitglieds. */
	public const STATUSES_PENDING = [
		self::STATUS_SUBMITTED,
		self::STATUS_PARTIALLY_APPROVED,
		self::STATUS_APPROVED,
	];

	/** Nur hier lassen sich Freigaben erteilen oder ein Antrag ablehnen. */
	public const STATUSES_OPEN_FOR_DECISION = [
		self::STATUS_SUBMITTED,
		self::STATUS_PARTIALLY_APPROVED,
	];

	public function __construct() {
		$this->addType('memberId', 'integer');
		$this->addType('amountCents', 'integer');
		$this->addType('requiredApprovals', 'integer');
		$this->addType('openItemId', 'integer');
		$this->addType('journalId', 'integer');
	}

	public function isOpenForDecision(): bool {
		return in_array($this->status, self::STATUSES_OPEN_FOR_DECISION, true);
	}

	/**
	 * Ohne IBAN: die Mitgliedersicht trägt sie nicht ungefragt in jede Liste, die
	 * Verwaltungssicht ergänzt sie (maskiert oder voll) je nach Rolle.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'memberId' => $this->memberId,
			'amountCents' => $this->amountCents,
			'expenseDate' => $this->expenseDate,
			'purpose' => $this->purpose,
			'status' => $this->status,
			'requiredApprovals' => $this->requiredApprovals,
			'submittedBy' => $this->submittedBy,
			'submittedAt' => $this->submittedAt,
			'decidedAt' => $this->decidedAt,
			'paidAt' => $this->paidAt,
			'rejectionReason' => $this->rejectionReason,
			'openItemId' => $this->openItemId,
			'journalId' => $this->journalId,
		];
	}
}
