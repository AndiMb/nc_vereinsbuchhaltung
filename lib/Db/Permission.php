<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getPrincipalType()
 * @method void setPrincipalType(string $principalType)
 * @method string getPrincipalId()
 * @method void setPrincipalId(string $principalId)
 * @method string getRole()
 * @method void setRole(string $role)
 */
class Permission extends Entity implements \JsonSerializable {
	protected $principalType;
	protected $principalId;
	protected $role;

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'principalType' => $this->principalType,
			'principalId' => $this->principalId,
			'role' => $this->role,
		];
	}
}
