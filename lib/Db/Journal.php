<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int|null getEntryNo()
 * @method void setEntryNo(?int $entryNo)
 * @method string getDate()
 * @method void setDate(string $date)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getDocumentRef()
 * @method void setDocumentRef(?string $documentRef)
 * @method int|null getBankTxId()
 * @method void setBankTxId(?int $bankTxId)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 */
class Journal extends Entity implements \JsonSerializable {
	protected $userId;
	protected $entryNo;
	protected $date;
	protected $description;
	protected $documentRef;
	protected $bankTxId;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('entryNo', 'integer');
		$this->addType('bankTxId', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'entryNo' => $this->entryNo,
			'date' => $this->date,
			'description' => $this->description,
			'documentRef' => $this->documentRef,
			'bankTxId' => $this->bankTxId,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
		];
	}
}
