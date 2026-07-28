<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int|null getEntryNo()
 * @method void setEntryNo(?int $entryNo)
 * @method string getDate()
 * @method void setDate(string $date)
 * @method int getYear()
 * @method void setYear(int $year)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getDocumentRef()
 * @method void setDocumentRef(?string $documentRef)
 * @method int|null getBankTxId()
 * @method void setBankTxId(?int $bankTxId)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 */
class Journal extends Entity implements \JsonSerializable {
	protected $userId;
	protected $entryNo;
	protected $date;
	/**
	 * Kalenderjahr aus {@see $date}, redundant gespeichert. Nötig, weil die
	 * Buchungsnummer je Geschäftsjahr bei 1 startet und sich nur so ein
	 * portabler Unique-Index (user_id, year, entry_no) bilden lässt – aus
	 * einer DATE-Spalte lässt sich das Jahr nicht datenbankübergreifend
	 * gleich indizieren. Wird ausschließlich über
	 * {@see setDateWithYear()} gepflegt, damit beide Felder nie auseinanderlaufen.
	 */
	protected $year;
	protected $description;
	protected $documentRef;
	protected $bankTxId;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('entryNo', 'integer');
		$this->addType('year', 'integer');
		$this->addType('bankTxId', 'integer');
	}

	/**
	 * Setzt Datum und abgeleitetes Jahr gemeinsam. Einziger zulässiger Weg,
	 * das Datum eines Buchungssatzes zu setzen.
	 */
	public function setDateWithYear(string $date): void {
		$this->setDate($date);
		$this->setYear(self::yearOf($date));
	}

	/** Kalenderjahr eines ISO-Datums (YYYY-MM-DD); 0 bei unbrauchbarer Eingabe. */
	public static function yearOf(string $date): int {
		return (int)substr($date, 0, 4);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'entryNo' => $this->entryNo,
			'date' => $this->date,
			'description' => $this->description,
			'documentRef' => $this->documentRef,
			'bankTxId' => $this->bankTxId,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
		];
	}
}
