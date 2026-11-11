<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Kostenstelle an der Buchungszeile (Issue #7): Abteilung, Mannschaft oder
 * Projekt, dem ein einzelner Betrag zugeordnet wird.
 *
 * Nicht zu verwechseln mit {@see CostCenter} – das ist die
 * Auswertungsgruppe, die ganze Konten zusammenfasst.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getCode()
 * @method void setCode(string $code)
 * @method string getName()
 * @method void setName(string $name)
 * @method bool getActive()
 * @method void setActive(bool $active)
 */
class BookingCostCenter extends Entity implements \JsonSerializable {
	protected $userId;
	protected $code;
	protected $name;
	protected $active = true;

	public function __construct() {
		$this->addType('active', 'boolean');
	}

	public function getId(): int {
		return (int)$this->id;
	}

	public function isActive(): bool {
		return (bool)$this->active;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'code' => $this->code,
			'name' => $this->name,
			'active' => $this->isActive(),
		];
	}
}
