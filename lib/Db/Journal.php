<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
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
 */
class Journal extends Entity implements \JsonSerializable {
	protected $userId;
	protected $date;
	protected $description;
	protected $documentRef;
	protected $bankTxId;
	protected $createdAt;

	public function __construct() {
		$this->addType('bankTxId', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'date' => $this->date,
			'description' => $this->description,
			'documentRef' => $this->documentRef,
			'bankTxId' => $this->bankTxId,
			'createdAt' => $this->createdAt,
		];
	}
}
