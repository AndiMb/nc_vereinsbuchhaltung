<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Eine Zeile eines SEPA-Sammeleinzugs (eine Lastschrift-Transaktion). Bleibt
 * nach dem Export bestehen, auch wenn ihr offener Posten längst bezahlt oder
 * storniert ist – die Rücklastschrift-Erkennung (Phase 5) muss eine
 * eingehende Rückbuchung noch dieser Zeile zuordnen können.
 *
 * @method int getBatchId()
 * @method void setBatchId(int $batchId)
 * @method int getOpenItemId()
 * @method void setOpenItemId(int $openItemId)
 * @method int getMandateId()
 * @method void setMandateId(int $mandateId)
 * @method int getAmountCents()
 * @method void setAmountCents(int $amountCents)
 * @method string getSequenceType()
 * @method void setSequenceType(string $sequenceType)
 * @method string getEndToEndId()
 * @method void setEndToEndId(string $endToEndId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getReturnReason()
 * @method void setReturnReason(?string $returnReason)
 * @method string|null getReturnDate()
 * @method void setReturnDate(?string $returnDate)
 * @method string|null getNotifiedAt()
 * @method void setNotifiedAt(?string $notifiedAt)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class SepaBatchItem extends Entity implements \JsonSerializable {
	protected $batchId;
	protected $openItemId;
	protected $mandateId;
	protected $amountCents = 0;
	protected $sequenceType;
	protected $endToEndId;
	protected $status = 'pending';
	protected $returnReason;
	protected $returnDate;
	protected $notifiedAt;
	protected $createdAt;

	/** FRST = erster Einzug eines Mandats, RCUR = Folgeeinzug, OOFF = einmalig. */
	public const SEQUENCE_TYPES = ['FRST', 'RCUR', 'OOFF'];
	public const STATUSES = ['pending', 'returned'];

	public function __construct() {
		$this->addType('batchId', 'integer');
		$this->addType('openItemId', 'integer');
		$this->addType('mandateId', 'integer');
		$this->addType('amountCents', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'batchId' => $this->batchId,
			'openItemId' => $this->openItemId,
			'mandateId' => $this->mandateId,
			'amountCents' => $this->amountCents,
			'amount' => $this->amountCents / 100,
			'sequenceType' => $this->sequenceType,
			'endToEndId' => $this->endToEndId,
			'status' => $this->status,
			'returnReason' => $this->returnReason,
			'returnDate' => $this->returnDate,
			'notifiedAt' => $this->notifiedAt,
			'createdAt' => $this->createdAt,
		];
	}
}
