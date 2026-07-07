<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Ein gespeicherter Finanzplan-Stand (Snapshot) eines Geschäftsjahres.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getYear()
 * @method void setYear(int $year)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class BudgetSnapshot extends Entity implements \JsonSerializable {

	protected $userId;
	protected $year;
	protected $label;
	protected $createdAt;

	public function __construct() {
		$this->addType('year', 'integer');
		$this->addType('createdAt', 'datetime');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'year' => $this->year,
			'label' => $this->label,
			'createdAt' => $this->createdAt?->format(\DateTime::ATOM),
		];
	}
}
