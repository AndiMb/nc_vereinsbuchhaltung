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

	public const STATUSES = ['open', 'paid', 'cancelled'];

	public function __construct() {
		$this->addType('amountCents', 'integer');
		$this->addType('accountId', 'integer');
		$this->addType('paidJournalId', 'integer');
		$this->addType('mandateId', 'integer');
	}

	public function isOverdue(): bool {
		return $this->status === 'open' && $this->dueDate !== null && $this->dueDate < date('Y-m-d');
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
		];
	}
}
