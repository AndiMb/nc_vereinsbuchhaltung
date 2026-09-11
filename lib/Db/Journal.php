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
 * @method int getPeriodId()
 * @method void setPeriodId(int $periodId)
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
	 * Das Geschäftsjahr, zu dem die Buchung gehört – als Verweis auf
	 * {@see Period}, abgeleitet aus {@see $date}.
	 *
	 * Nötig, weil die Buchungsnummer je Geschäftsjahr bei 1 startet und sich
	 * nur so ein portabler Unique-Index (user_id, period_id, entry_no) bilden
	 * lässt. Bis 0.32.0 stand hier die Jahreszahl aus dem Datum; seit Issue #8
	 * muss ein Geschäftsjahr weder dem Kalenderjahr entsprechen noch zwölf
	 * Monate dauern, und aus dem Datum allein ist es nicht mehr abzulesen.
	 *
	 * Wird ausschließlich über {@see setDateWithPeriod()} gepflegt, damit
	 * Datum und Zuordnung nie auseinanderlaufen.
	 */
	protected $periodId;
	protected $description;
	protected $documentRef;
	protected $bankTxId;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('entryNo', 'integer');
		$this->addType('periodId', 'integer');
		$this->addType('bankTxId', 'integer');
	}

	/**
	 * Setzt Datum und zugehöriges Geschäftsjahr gemeinsam. Einziger zulässiger
	 * Weg, das Datum eines Buchungssatzes zu setzen.
	 *
	 * Die Periode ermittelt der Aufrufer über
	 * {@see \OCA\Vereinsbuchhaltung\Service\PeriodService::forDate()} – nur
	 * der kennt die Geschäftsjahr-Regel und kann eine fehlende Periode anlegen.
	 */
	public function setDateWithPeriod(string $date, int $periodId): void {
		$this->setDate($date);
		$this->setPeriodId($periodId);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'entryNo' => $this->entryNo,
			'date' => $this->date,
			'periodId' => $this->periodId,
			'description' => $this->description,
			'documentRef' => $this->documentRef,
			'bankTxId' => $this->bankTxId,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
		];
	}
}
