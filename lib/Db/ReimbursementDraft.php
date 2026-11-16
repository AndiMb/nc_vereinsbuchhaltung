<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entwurf eines Auslagenantrags (Issue #356): die private Vorarbeit eines
 * Mitglieds, etwa unterwegs angelegt, bevor die Rechnung da ist.
 *
 * Alle Felder sind optional. Erst das Einreichen
 * ({@see \OCA\Vereinsbuchhaltung\Service\ReimbursementDraftService::submit()})
 * prüft die Pflichtangaben und legt daraus einen gewöhnlichen
 * {@see Reimbursement} an; der Entwurf verschwindet dabei. Verwalter sehen
 * Entwürfe nie, auch nicht als Zahl.
 *
 * `iban` liegt verschlüsselt (siehe {@see ReimbursementDraftMapper}).
 *
 * @method int getMemberId()
 * @method void setMemberId(int $memberId)
 * @method int|null getAmountCents()
 * @method void setAmountCents(?int $amountCents)
 * @method string|null getExpenseDate()
 * @method void setExpenseDate(?string $expenseDate)
 * @method string|null getPurpose()
 * @method void setPurpose(?string $purpose)
 * @method string|null getInvoiceNumber()
 * @method void setInvoiceNumber(?string $invoiceNumber)
 * @method string|null getSupplier()
 * @method void setSupplier(?string $supplier)
 * @method string|null getIban()
 * @method void setIban(?string $iban)
 * @method string|null getAccountHolder()
 * @method void setAccountHolder(?string $accountHolder)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class ReimbursementDraft extends Entity {
	protected $memberId;
	protected $amountCents;
	protected $expenseDate;
	protected $purpose;
	protected $invoiceNumber;
	protected $supplier;
	protected $iban;
	protected $accountHolder;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('memberId', 'integer');
		$this->addType('amountCents', 'integer');
	}
}
