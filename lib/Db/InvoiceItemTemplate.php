<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Positionsvorlage für Rechnungen (Epic #168, Stufe 3): Name, Beschreibung, Einheit,
 * Preis, Steuerkategorie und Erlöskonto, die der Editor als Kopie in eine Position übernimmt.
 *
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string getUnitCode()
 * @method void setUnitCode(string $unitCode)
 * @method int getUnitPriceCents()
 * @method void setUnitPriceCents(int $unitPriceCents)
 * @method string getTaxCategory()
 * @method void setTaxCategory(string $taxCategory)
 * @method int getTaxRateBp()
 * @method void setTaxRateBp(int $taxRateBp)
 * @method int|null getAccountId()
 * @method void setAccountId(?int $accountId)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 */
class InvoiceItemTemplate extends Entity implements \JsonSerializable {
	protected $name;
	protected $description;
	protected $unitCode = 'C62';
	protected $unitPriceCents = 0;
	protected $taxCategory = 'S';
	protected $taxRateBp = 0;
	protected $accountId;
	protected $createdAt;
	protected $createdBy;

	public function __construct() {
		$this->addType('unitPriceCents', 'integer');
		$this->addType('taxRateBp', 'integer');
		$this->addType('accountId', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'name' => $this->name,
			'description' => $this->description,
			'unitCode' => $this->unitCode,
			'unitPriceCents' => $this->unitPriceCents,
			'taxCategory' => $this->taxCategory,
			'taxRateBp' => $this->taxRateBp,
			'accountId' => $this->accountId,
			'createdAt' => $this->createdAt,
			'createdBy' => $this->createdBy,
		];
	}
}
