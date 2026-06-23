<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getCode()
 * @method void setCode(string $code)
 * @method string getName()
 * @method void setName(string $name)
 */
class CostCenter extends Entity implements \JsonSerializable {
	protected $userId;
	protected $code;
	protected $name;

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'code' => $this->code,
			'name' => $this->name,
		];
	}
}
