<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Eine eigene Vorlage des Gliederungsberichts. Die Definition ist JSON, siehe
 * {@see \OCA\Vereinsbuchhaltung\Service\Report\ReportDefinition}.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getDefinition()
 * @method void setDefinition(string $definition)
 * @method int getPosition()
 * @method void setPosition(int $position)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class ReportTemplate extends Entity {

	protected $userId;
	protected $name;
	protected $definition;
	protected $position;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('position', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
