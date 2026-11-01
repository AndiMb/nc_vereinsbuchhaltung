<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Rechnungsstapel (#243): die aus einer Tabelle angelegten Rechnungen eines
 * Projekts, z. B. „Probenwochenende 2026“. Die Rechnungen verweisen über
 * `batch_id` auf ihn.
 *
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getSourceFilename()
 * @method void setSourceFilename(?string $sourceFilename)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class InvoiceBatch extends Entity implements \JsonSerializable {
	protected $name;
	protected $sourceFilename;
	protected $createdBy;
	protected $createdAt;

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'name' => $this->name,
			'sourceFilename' => $this->sourceFilename,
			'createdBy' => $this->createdBy,
			'createdAt' => $this->createdAt,
		];
	}
}
