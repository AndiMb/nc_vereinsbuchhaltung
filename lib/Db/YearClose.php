<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Abgeschlossenes (festgeschriebenes) Geschäftsjahr.
 *
 * @method int getYear()
 * @method void setYear(int $year)
 * @method string getClosedAt()
 * @method void setClosedAt(string $closedAt)
 * @method string getClosedBy()
 * @method void setClosedBy(string $closedBy)
 */
class YearClose extends Entity implements \JsonSerializable {
	protected $year;
	protected $closedAt;
	protected $closedBy;

	public function __construct() {
		$this->addType('year', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'year' => $this->year,
			'closedAt' => $this->closedAt,
			'closedBy' => $this->closedBy,
		];
	}
}
