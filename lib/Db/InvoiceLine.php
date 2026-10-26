<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Position einer Rechnung. Menge in Zehntausendstel (`quantityE4`: 10000 = 1),
 * Rabatt und Steuersatz in Basispunkten (`discountBp`, `taxRateBp`: 1900 =
 * 19 %), Einzelpreis und Zeilensumme in Cent. Steuerkategorie nach EN 16931:
 * S (Regelsatz), E (steuerbefreit), AE (Reverse Charge), Z (Nullsatz).
 *
 * @method int getInvoiceId()
 * @method void setInvoiceId(int $invoiceId)
 * @method int getPosition()
 * @method void setPosition(int $position)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method int getQuantityE4()
 * @method void setQuantityE4(int $quantityE4)
 * @method string getUnitCode()
 * @method void setUnitCode(string $unitCode)
 * @method int getUnitPriceCents()
 * @method void setUnitPriceCents(int $unitPriceCents)
 * @method int getDiscountBp()
 * @method void setDiscountBp(int $discountBp)
 * @method string getTaxCategory()
 * @method void setTaxCategory(string $taxCategory)
 * @method int getTaxRateBp()
 * @method void setTaxRateBp(int $taxRateBp)
 * @method string|null getTaxExemptionText()
 * @method void setTaxExemptionText(?string $taxExemptionText)
 * @method int getLineNetCents()
 * @method void setLineNetCents(int $lineNetCents)
 * @method int|null getAccountId()
 * @method void setAccountId(?int $accountId)
 * @method int|null getCostCenterId()
 * @method void setCostCenterId(?int $costCenterId)
 * @method string|null getDeliveryDate()
 * @method void setDeliveryDate(?string $deliveryDate)
 */
class InvoiceLine extends Entity implements \JsonSerializable {
	public const TAX_STANDARD = 'S';
	public const TAX_EXEMPT = 'E';
	public const TAX_REVERSE_CHARGE = 'AE';
	public const TAX_ZERO = 'Z';
	public const TAX_CATEGORIES = [self::TAX_STANDARD, self::TAX_EXEMPT, self::TAX_REVERSE_CHARGE, self::TAX_ZERO];

	/** Menge 1 in Zehntausendstel. */
	public const QUANTITY_ONE = 10000;

	protected $invoiceId;
	protected $position = 1;
	protected $name;
	protected $description;
	protected $quantityE4 = self::QUANTITY_ONE;
	protected $unitCode = 'C62';
	protected $unitPriceCents = 0;
	protected $discountBp = 0;
	protected $taxCategory = self::TAX_EXEMPT;
	protected $taxRateBp = 0;
	protected $taxExemptionText;
	protected $lineNetCents = 0;
	protected $accountId;
	protected $costCenterId;
	protected $deliveryDate;

	public function __construct() {
		$this->addType('invoiceId', 'integer');
		$this->addType('position', 'integer');
		$this->addType('quantityE4', 'integer');
		$this->addType('unitPriceCents', 'integer');
		$this->addType('discountBp', 'integer');
		$this->addType('taxRateBp', 'integer');
		$this->addType('lineNetCents', 'integer');
		$this->addType('accountId', 'integer');
		$this->addType('costCenterId', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'invoiceId' => $this->invoiceId,
			'position' => $this->position,
			'name' => $this->name,
			'description' => $this->description,
			'quantityE4' => $this->quantityE4,
			'quantity' => $this->quantityE4 / 10000,
			'unitCode' => $this->unitCode,
			'unitPriceCents' => $this->unitPriceCents,
			'discountBp' => $this->discountBp,
			'taxCategory' => $this->taxCategory,
			'taxRateBp' => $this->taxRateBp,
			'taxExemptionText' => $this->taxExemptionText,
			'lineNetCents' => $this->lineNetCents,
			'accountId' => $this->accountId,
			'costCenterId' => $this->costCenterId,
			'deliveryDate' => $this->deliveryDate,
		];
	}
}
