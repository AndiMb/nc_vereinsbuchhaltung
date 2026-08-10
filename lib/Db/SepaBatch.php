<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Ein SEPA-Sammeleinzug (eine pain.008-Datei). Die Gläubiger-Angaben werden
 * bei der Erzeugung aus den Einstellungen kopiert (nicht live nachgeschlagen):
 * ändert sich später die Gläubiger-ID, soll eine bereits erzeugte Datei
 * nachvollziehbar bleiben, mit welcher ID sie tatsächlich eingereicht wurde.
 *
 * @method string getExecutionDate()
 * @method void setExecutionDate(string $executionDate)
 * @method string getMessageId()
 * @method void setMessageId(string $messageId)
 * @method string getCreditorId()
 * @method void setCreditorId(string $creditorId)
 * @method string getCreditorName()
 * @method void setCreditorName(string $creditorName)
 * @method string getCreditorIban()
 * @method void setCreditorIban(string $creditorIban)
 * @method string|null getCreditorBic()
 * @method void setCreditorBic(?string $creditorBic)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class SepaBatch extends Entity implements \JsonSerializable {
	protected $executionDate;
	protected $messageId;
	protected $creditorId;
	protected $creditorName;
	protected $creditorIban;
	protected $creditorBic;
	protected $createdBy;
	protected $createdAt;

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'executionDate' => $this->executionDate,
			'messageId' => $this->messageId,
			'creditorId' => $this->creditorId,
			'creditorName' => $this->creditorName,
			'creditorIban' => $this->creditorIban,
			'creditorBic' => $this->creditorBic,
			'createdBy' => $this->createdBy,
			'createdAt' => $this->createdAt,
		];
	}
}
