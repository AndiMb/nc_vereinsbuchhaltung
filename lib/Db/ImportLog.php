<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getFilename()
 * @method void setFilename(string $filename)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method int getRowsTotal()
 * @method void setRowsTotal(int $rowsTotal)
 * @method int getRowsNew()
 * @method void setRowsNew(int $rowsNew)
 * @method int getRowsDuplicate()
 * @method void setRowsDuplicate(int $rowsDuplicate)
 * @method string|null getSource()
 * @method void setSource(?string $source)
 */
class ImportLog extends Entity implements \JsonSerializable {
	protected $userId;
	protected $filename;
	protected $createdAt;
	protected $rowsTotal = 0;
	protected $rowsNew = 0;
	protected $rowsDuplicate = 0;
	/** Format der Quelle: csv, camt, mt940 (später fints). */
	protected $source = 'csv';

	public function __construct() {
		$this->addType('rowsTotal', 'integer');
		$this->addType('rowsNew', 'integer');
		$this->addType('rowsDuplicate', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'filename' => $this->filename,
			'createdAt' => $this->createdAt,
			'rowsTotal' => $this->rowsTotal,
			'rowsNew' => $this->rowsNew,
			'rowsDuplicate' => $this->rowsDuplicate,
			'source' => $this->source,
		];
	}
}
