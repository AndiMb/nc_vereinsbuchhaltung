<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Eine Zahlung zu einer Rechnung. `parts` hält die Aufteilung auf die
 * Erlöskonten als JSON `{"<Konto-ID>": <Cent>}`.
 *
 * @method int getInvoiceId()
 * @method void setInvoiceId(int $invoiceId)
 * @method int getOpenItemId()
 * @method void setOpenItemId(int $openItemId)
 * @method int|null getBankTxId()
 * @method void setBankTxId(?int $bankTxId)
 * @method int|null getJournalId()
 * @method void setJournalId(?int $journalId)
 * @method int getAmountCents()
 * @method void setAmountCents(int $amountCents)
 * @method string|null getParts()
 * @method void setParts(?string $parts)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 */
class InvoicePayment extends Entity implements \JsonSerializable {
	protected $invoiceId;
	protected $openItemId;
	protected $bankTxId;
	protected $journalId;
	protected $amountCents = 0;
	protected $parts;
	protected $createdAt;
	protected $createdBy;

	public function __construct() {
		$this->addType('invoiceId', 'integer');
		$this->addType('openItemId', 'integer');
		$this->addType('bankTxId', 'integer');
		$this->addType('journalId', 'integer');
		$this->addType('amountCents', 'integer');
	}

	/** @return array<int, int> Konto-ID => Cent */
	public function partsMap(): array {
		$data = $this->parts !== null ? json_decode($this->parts, true) : null;
		$out = [];
		if (is_array($data)) {
			foreach ($data as $accountId => $cents) {
				$out[(int)$accountId] = (int)$cents;
			}
		}
		return $out;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'invoiceId' => $this->invoiceId,
			'bankTxId' => $this->bankTxId,
			'journalId' => $this->journalId,
			'amountCents' => $this->amountCents,
			'createdAt' => $this->createdAt,
			'createdBy' => $this->createdBy,
		];
	}
}
