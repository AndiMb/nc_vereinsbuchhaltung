<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Lastschriftlauf (Spec §2.2 „Lastschriftlauf (Debit Batch)"/§3.5, Issue
 * #71): bündelt alle an einem Einzugstermin fälligen {@see DebitItem} zu
 * einer pain.008-Datei. Existiert erst ab der Freigabe – siehe
 * {@see \OCA\Vereinsbuchhaltung\Service\DebitBatchService::release()} und,
 * für die Zeit davor, {@see \OCA\Vereinsbuchhaltung\Service\DebitRunQueryService}.
 *
 * Enum-Sprache deutsch, konsistent mit dem {@see Mandate}::STATUS_*-Muster
 * (Spec §13.1 „Entscheidung Florian 2026-09-16"): `freigegeben` → `eingereicht`
 * | `verworfen`, beide terminal. `verworfen` behält die volle Historie (Zeile
 * bleibt stehen), die zugehörigen Forderungen werden wieder frei – siehe
 * {@see DebitItemMapper::findOpenItemIdsInLiveBatches()}, die genau deshalb
 * nur `freigegeben`/`eingereicht` als „gebunden" zählt.
 *
 * `msg_id`/`creation_date_time` werden bei der Freigabe eingefroren und nie
 * wieder verändert – Grundlage der byte-identischen Nachrenderbarkeit (Spec
 * §3.5). `due_date` bleibt bis zur Einreichung änderbar, aber nur nach
 * hinten (siehe {@see \OCA\Vereinsbuchhaltung\Service\DebitBatchStateMachine::assertCanReschedule()}).
 * Gläubigerangaben (`creditor_*`) sind ebenfalls ein Schnappschuss: eine
 * spätere Änderung der App-Einstellungen darf eine bereits freigegebene
 * Datei nicht rückwirkend verändern.
 *
 * @method string getDueDate()
 * @method void setDueDate(string $dueDate)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getReleasedBy()
 * @method void setReleasedBy(?string $releasedBy)
 * @method string getReleasedAt()
 * @method void setReleasedAt(string $releasedAt)
 * @method string|null getSubmittedBy()
 * @method void setSubmittedBy(?string $submittedBy)
 * @method string|null getSubmittedAt()
 * @method void setSubmittedAt(?string $submittedAt)
 * @method string|null getDiscardedBy()
 * @method void setDiscardedBy(?string $discardedBy)
 * @method string|null getDiscardedAt()
 * @method void setDiscardedAt(?string $discardedAt)
 * @method string|null getDiscardReason()
 * @method void setDiscardReason(?string $discardReason)
 * @method string getMsgId()
 * @method void setMsgId(string $msgId)
 * @method string getCreationDateTime()
 * @method void setCreationDateTime(string $creationDateTime)
 * @method string getCreditorId()
 * @method void setCreditorId(string $creditorId)
 * @method string getCreditorName()
 * @method void setCreditorName(string $creditorName)
 * @method string getCreditorIban()
 * @method void setCreditorIban(string $creditorIban)
 * @method string|null getCreditorBic()
 * @method void setCreditorBic(?string $creditorBic)
 */
class DebitBatch extends Entity implements \JsonSerializable {
	protected $dueDate;
	protected $status = self::STATUS_RELEASED;
	protected $releasedBy;
	protected $releasedAt;
	protected $submittedBy;
	protected $submittedAt;
	protected $discardedBy;
	protected $discardedAt;
	protected $discardReason;
	protected $msgId;
	protected $creationDateTime;
	protected $creditorId;
	protected $creditorName;
	protected $creditorIban;
	protected $creditorBic;

	public const STATUS_RELEASED = 'freigegeben';
	public const STATUS_SUBMITTED = 'eingereicht';
	public const STATUS_DISCARDED = 'verworfen';
	public const STATUSES = [self::STATUS_RELEASED, self::STATUS_SUBMITTED, self::STATUS_DISCARDED];

	/** `eingereicht`/`verworfen` sind beide terminal (Spec §3.5). */
	public function isTerminal(): bool {
		return $this->status !== self::STATUS_RELEASED;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'dueDate' => $this->dueDate,
			'status' => $this->status,
			'releasedBy' => $this->releasedBy,
			'releasedAt' => $this->releasedAt,
			'submittedBy' => $this->submittedBy,
			'submittedAt' => $this->submittedAt,
			'discardedBy' => $this->discardedBy,
			'discardedAt' => $this->discardedAt,
			'discardReason' => $this->discardReason,
			'msgId' => $this->msgId,
			'creationDateTime' => $this->creationDateTime,
			'creditorId' => $this->creditorId,
			'creditorName' => $this->creditorName,
			'creditorIban' => $this->creditorIban,
			'creditorBic' => $this->creditorBic,
		];
	}
}
