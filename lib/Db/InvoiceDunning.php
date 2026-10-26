<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Eine Zahlungserinnerung oder Mahnung zu einer Rechnung. `level`: 1 Zahlungserinnerung,
 * 2 erste Mahnung, 3 zweite (letzte) Mahnung.
 *
 * @method int getInvoiceId()
 * @method void setInvoiceId(int $invoiceId)
 * @method int getLevel()
 * @method void setLevel(int $level)
 * @method string getSentAt()
 * @method void setSentAt(string $sentAt)
 * @method string getSentTo()
 * @method void setSentTo(string $sentTo)
 * @method int getRemainingCents()
 * @method void setRemainingCents(int $remainingCents)
 * @method int|null getDocumentId()
 * @method void setDocumentId(?int $documentId)
 * @method int getAuto()
 * @method void setAuto(int $auto)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 */
class InvoiceDunning extends Entity implements \JsonSerializable {
	protected $invoiceId;
	protected $level;
	protected $sentAt;
	protected $sentTo;
	protected $remainingCents = 0;
	protected $documentId;
	protected $auto = 0;
	protected $createdBy;

	public function __construct() {
		$this->addType('invoiceId', 'integer');
		$this->addType('level', 'integer');
		$this->addType('remainingCents', 'integer');
		$this->addType('documentId', 'integer');
		$this->addType('auto', 'integer');
	}

	public const LEVEL_REMINDER = 1;
	public const LEVEL_FIRST = 2;
	public const LEVEL_FINAL = 3;
	public const LEVELS = [self::LEVEL_REMINDER, self::LEVEL_FIRST, self::LEVEL_FINAL];

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'invoiceId' => $this->invoiceId,
			'level' => $this->level,
			'sentAt' => $this->sentAt,
			'sentTo' => $this->sentTo,
			'remainingCents' => $this->remainingCents,
			'documentId' => $this->documentId,
			'auto' => $this->auto,
			'createdBy' => $this->createdBy,
		];
	}
}
