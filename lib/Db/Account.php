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
 * @method bool getTransitory()
 * @method void setTransitory(bool $transitory)
 * @method bool getActive()
 * @method void setActive(bool $active)
 * @method int getOpeningBalanceCents()
 * @method void setOpeningBalanceCents(int $openingBalanceCents)
 * @method string|null getOpeningDate()
 * @method void setOpeningDate(?string $openingDate)
 * @method int|null getParentId()
 * @method void setParentId(?int $parentId)
 */
class Account extends Entity implements \JsonSerializable {
	protected $userId;
	protected $number;
	protected $name;
	protected $type;
	protected $category;
	protected $isBank = false;
	protected $transitory = false;
	protected $active = true;
	protected $openingBalanceCents = 0;
	protected $openingDate;
	protected $parentId;

	public function __construct() {
		$this->addType('isBank', 'boolean');
		$this->addType('transitory', 'boolean');
		$this->addType('active', 'boolean');
		$this->addType('openingBalanceCents', 'integer');
		$this->addType('parentId', 'integer');
	}

	/**
	 * Echtes Bestandskonto: kumuliert seinen Saldo ueber Jahresgrenzen
	 * (Kontostand, Saldovortrag, Teil des Vermoegens). Durchlaufende Konten
	 * (transitory) sind davon ausgenommen – sie tragen keinen Bestand ueber den
	 * Jahreswechsel und werden jahresbezogen wie Erfolgskonten behandelt.
	 */
	public function isStockAccount(): bool {
		return in_array($this->type, ['asset', 'liability'], true) && !$this->transitory;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'number' => $this->number,
			'name' => $this->name,
			'type' => $this->type,
			'category' => $this->category,
			'isBank' => $this->isBank,
			'transitory' => $this->transitory,
			'active' => $this->active,
			'openingBalanceCents' => $this->openingBalanceCents,
			'openingBalance' => $this->openingBalanceCents / 100,
			'openingDate' => $this->openingDate,
			'parentId' => $this->parentId,
		];
	}
}
