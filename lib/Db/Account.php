<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getNumber()
 * @method void setNumber(string $number)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getType()
 * @method void setType(string $type)
 * @method string|null getCategory()
 * @method void setCategory(?string $category)
 * @method bool getIsBank()
 * @method void setIsBank(bool $isBank)
 * @method bool getActive()
 * @method void setActive(bool $active)
 */
class Account extends Entity implements \JsonSerializable {
	protected $userId;
	protected $number;
	protected $name;
	protected $type;
	protected $category;
	protected $isBank = false;
	protected $active = true;

	public function __construct() {
		$this->addType('isBank', 'boolean');
		$this->addType('active', 'boolean');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'number' => $this->number,
			'name' => $this->name,
			'type' => $this->type,
			'category' => $this->category,
			'isBank' => $this->isBank,
			'active' => $this->active,
		];
	}
}
