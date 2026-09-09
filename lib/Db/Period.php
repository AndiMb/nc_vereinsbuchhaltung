<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Ein Geschäftsjahr als benannter Zeitraum.
 *
 * Ersetzt seit 0.33.0 die bloße Jahreszahl (Issue #8): ein Geschäftsjahr muss
 * nicht mehr dem Kalenderjahr entsprechen, kann kürzer als zwölf Monate sein
 * (Semester) und trägt eine frei wählbare Bezeichnung. Buchungen, Planwerte
 * und Plan-Stände verweisen über die ID hierher; eine Jahreszahl steht
 * nirgends mehr als Schlüssel.
 *
 * Die Grenzen sind ISO-Strings (JJJJ-MM-TT) und beide inklusive – wie das
 * Buchungsdatum, mit dem sie verglichen werden.
 *
 * Die Festschreibung sitzt als closedAt/closedBy an der Periode selbst. Sie
 * stand bis 0.32.0 in einer eigenen Tabelle vbh_year_close, die ohne user_id
 * auskam und damit als einzige Tabelle nicht am Buch hing.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method string getStartDate()
 * @method void setStartDate(string $startDate)
 * @method string getEndDate()
 * @method void setEndDate(string $endDate)
 * @method string|null getClosedAt()
 * @method void setClosedAt(?string $closedAt)
 * @method string|null getClosedBy()
 * @method void setClosedBy(?string $closedBy)
 */
class Period extends Entity implements \JsonSerializable {
	protected $userId;
	protected $label;
	protected $startDate;
	protected $endDate;
	protected $closedAt;
	protected $closedBy;

	/** Ist die Periode festgeschrieben? */
	public function isClosed(): bool {
		return $this->closedAt !== null && $this->closedAt !== '';
	}

	/** Enthält die Periode dieses Buchungsdatum? Beide Grenzen zählen dazu. */
	public function contains(string $date): bool {
		return $date >= (string)$this->startDate && $date <= (string)$this->endDate;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'label' => $this->label,
			'startDate' => $this->startDate,
			'endDate' => $this->endDate,
			'closedAt' => $this->closedAt,
			'closedBy' => $this->closedBy,
		];
	}
}
