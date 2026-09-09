<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Ein gespeicherter Finanzplan-Stand (Snapshot) eines Geschäftsjahres.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getPeriodId()
 * @method void setPeriodId(int $periodId)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class BudgetSnapshot extends Entity implements \JsonSerializable {

	protected $userId;
	protected $periodId;
	protected $label;
	protected $createdAt;

	public function __construct() {
		$this->addType('periodId', 'integer');
		$this->addType('createdAt', 'datetime');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'periodId' => $this->periodId,
			'label' => $this->label,
			'createdAt' => $this->createdAt?->format(\DateTime::ATOM),
		];
	}
}
