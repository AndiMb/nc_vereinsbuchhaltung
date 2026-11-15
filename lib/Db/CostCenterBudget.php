<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Planwerte einer Kostenstelle für ein Geschäftsjahr (Issue #335): Einnahmen
 * und Ausgaben, beide ≥ 0. Eine Zeile gibt es nur, solange einer der beiden
 * Werte gesetzt ist.
 *
 * @method int getCostCenterId()
 * @method void setCostCenterId(int $costCenterId)
 * @method int getPeriodId()
 * @method void setPeriodId(int $periodId)
 * @method int getIncomeCents()
 * @method void setIncomeCents(int $incomeCents)
 * @method int getExpenseCents()
 * @method void setExpenseCents(int $expenseCents)
 */
class CostCenterBudget extends Entity {
	// Bewusst ohne Standardwerte: der Mapper setzt beim Einfügen jedes Feld,
	// und ein Setter mit dem Wert des Klassen-Standards markierte das Feld
	// nicht als geändert – es fehlte dann im INSERT.
	protected $costCenterId;
	protected $periodId;
	protected $incomeCents;
	protected $expenseCents;

	public function __construct() {
		$this->addType('costCenterId', 'integer');
		$this->addType('periodId', 'integer');
		$this->addType('incomeCents', 'integer');
		$this->addType('expenseCents', 'integer');
	}
}
