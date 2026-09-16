<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Meldepflichtige Änderung an einem *bestehenden* Mandat, ohne dass eine neue
 * Unterschrift nötig wird – siehe Spec §2.2 „Amendment vs. neues Mandat" und
 * Compliance-Anhang §8 (IBAN-/Kontowechsel: `AmdmntInd=true` + `OrgnlDbtrAcct`).
 * In diesem Ticket (#66) entsteht nur `type: account` (IBAN/BIC-Wechsel bei
 * gleichem Kontoinhaber); `reference`/`creditor` sind Enum-Vorgriffe auf
 * Situationen, die erst mit dem Einzugszyklus- bzw. Rücklastschrift-Ticket
 * praktisch vorkommen (geänderte Mandatsreferenz bzw. Gläubiger-ID nach einer
 * Fusion/einem Vereinsumzug).
 *
 * Enum-Werte bleiben englisch: das Issue führt sie so im Feldkatalog auf und
 * markiert sie – anders als `Mandate::STATUS_*` etc. – nicht als
 * klärungsbedürftig (siehe {@see Mandate} für die Begründung der dortigen
 * Eindeutschung).
 *
 * @method int getMandateId()
 * @method void setMandateId(int $mandateId)
 * @method string getType()
 * @method void setType(string $type)
 * @method string|null getOldIban()
 * @method void setOldIban(?string $oldIban)
 * @method string|null getOldBic()
 * @method void setOldBic(?string $oldBic)
 * @method string|null getOldAccountHolder()
 * @method void setOldAccountHolder(?string $oldAccountHolder)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int|null getDebitItemId()
 * @method void setDebitItemId(?int $debitItemId)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class MandateAmendment extends Entity implements \JsonSerializable {
	protected $mandateId;
	protected $type;
	protected $oldIban;
	protected $oldBic;
	protected $oldAccountHolder;
	protected $status = self::STATUS_OPEN;
	protected $debitItemId;
	protected $createdAt;

	public const TYPE_ACCOUNT = 'account';
	public const TYPE_REFERENCE = 'reference';
	public const TYPE_CREDITOR = 'creditor';
	public const TYPES = [self::TYPE_ACCOUNT, self::TYPE_REFERENCE, self::TYPE_CREDITOR];

	/**
	 * `open`: der Bank noch nicht als Amendment mitgeteilt (steckt in keinem
	 * eingereichten Einzugsposten). `transmitted`: mit einem Einzugsposten
	 * eingereicht. Eine Rücklastschrift dieses Postens setzt laut Spec §2.2
	 * wieder auf `open` zurück – siehe {@see \OCA\Vereinsbuchhaltung\Service\MandateService::reopenAmendment()}.
	 */
	public const STATUS_OPEN = 'open';
	public const STATUS_TRANSMITTED = 'transmitted';
	public const STATUSES = [self::STATUS_OPEN, self::STATUS_TRANSMITTED];

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'mandateId' => $this->mandateId,
			'type' => $this->type,
			'oldIban' => $this->oldIban,
			'oldBic' => $this->oldBic,
			'oldAccountHolder' => $this->oldAccountHolder,
			'status' => $this->status,
			'debitItemId' => $this->debitItemId,
			'createdAt' => $this->createdAt,
		];
	}
}
