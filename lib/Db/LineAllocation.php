<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Anteil einer Buchungszeile, der einer Kostenstelle zugeordnet ist
 * (Issue #7). Der Betrag ist immer positiv und steht auf der Seite der
 * Zeile; was die Summe aller Anteile einer Zeile nicht abdeckt, gilt als
 * "ohne Kostenstelle".
 *
 * @method int getLineId()
 * @method void setLineId(int $lineId)
 * @method int getCostCenterId()
 * @method void setCostCenterId(int $costCenterId)
 * @method int getAmountCents()
 * @method void setAmountCents(int $amountCents)
 */
class LineAllocation extends Entity {
	protected $lineId;
	protected $costCenterId;
	protected $amountCents = 0;

	public function __construct() {
		// Pflichtspalte ohne Datenbank-Vorgabe: auch der Vorgabewert muss im INSERT stehen
		// (der Setter merkt nur geänderte Werte vor, siehe EntityDefaultValueInsertTest).
		$this->markFieldUpdated('amountCents');
		$this->addType('lineId', 'integer');
		$this->addType('costCenterId', 'integer');
		$this->addType('amountCents', 'integer');
	}
}
