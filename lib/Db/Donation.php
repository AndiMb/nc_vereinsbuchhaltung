<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Spender einer Buchung auf einem Spendenkonto (Issue #10, Teil B): ein
 * Mitglied oder ein Debitor (externer Spender, z. B. ein Unternehmen). Betrag
 * und Datum stehen in der Buchung; hier nur, wer gespendet hat und ob es ein
 * Verzicht auf Erstattung von Aufwendungen war.
 *
 * @method int getJournalId()
 * @method void setJournalId(int $journalId)
 * @method int getMemberId()
 * @method void setMemberId(int $memberId)
 * @method int getDebtorId()
 * @method void setDebtorId(int $debtorId)
 * @method int getWaiver()
 * @method void setWaiver(int $waiver)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 */
class Donation extends Entity {

	public const DONOR_MEMBER = 'member';
	public const DONOR_DEBTOR = 'debtor';

	protected $journalId;
	protected $memberId;
	protected $debtorId;
	protected $waiver = 0;
	protected $createdAt;
	protected $createdBy;
	protected $updatedAt;

	public function __construct() {
		$this->addType('journalId', 'integer');
		$this->addType('memberId', 'integer');
		$this->addType('debtorId', 'integer');
		$this->addType('waiver', 'integer');
	}

	/** @return array{0: string, 1: int} Art und Id des Spenders */
	public function donor(): array {
		return $this->memberId > 0 ? [self::DONOR_MEMBER, (int)$this->memberId] : [self::DONOR_DEBTOR, (int)$this->debtorId];
	}

	public function isWaiver(): bool {
		return $this->waiver === 1;
	}
}
