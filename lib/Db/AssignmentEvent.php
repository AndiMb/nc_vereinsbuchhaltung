<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Append-only Historie einer Zuweisung, siehe Spec §2.2 „Zuweisung
 * (Assignment)". Analog zu `MandateEvent` (Ticket #66) – jeder
 * Zustandswechsel/jede Feldänderung erzeugt einen Eintrag, nie ein Update auf
 * einen bestehenden.
 *
 * `actorType`/`actorUid`/`onBehalfNote` folgen demselben Muster wie überall
 * im Modul (Spec §3.9 „Personalunion"): der **Kanal** entscheidet
 * `actor_type`, nicht die Identität. Da Self-Service noch nicht existiert
 * (Ticket #69/#74), ist `actor_type` in diesem Ticket immer `staff` oder
 * `system` (Austritts-Job) – der Wertebereich lässt `member` trotzdem schon
 * zu, damit spätere Tickets additiv bleiben.
 *
 * `details` ist ein JSON-Objekt mit den fachlich relevanten alten/neuen
 * Werten des jeweiligen Ereignisses (z.B. `{"from":800,"to":1000}` bei
 * `amount_changed`) – bewusst kein eigenes Spaltenpaar je Ereignistyp.
 *
 * @method int getAssignmentId()
 * @method void setAssignmentId(int $assignmentId)
 * @method string getType()
 * @method void setType(string $type)
 * @method string getActorType()
 * @method void setActorType(string $actorType)
 * @method string|null getActorUid()
 * @method void setActorUid(?string $actorUid)
 * @method string|null getOnBehalfNote()
 * @method void setOnBehalfNote(?string $onBehalfNote)
 * @method string|null getDetails()
 * @method void setDetails(?string $details)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class AssignmentEvent extends Entity implements \JsonSerializable {
	protected $assignmentId;
	protected $type;
	protected $actorType;
	protected $actorUid;
	protected $onBehalfNote;
	protected $details;
	protected $createdAt;

	public const TYPE_AMOUNT_CHANGED = 'amount_changed';
	public const TYPE_INTERVAL_CHANGED = 'interval_changed';
	public const TYPE_MIN_AMOUNT_OVERRIDE_SET = 'min_amount_override_set';
	public const TYPE_GROUP_CHANGED = 'group_changed';
	public const TYPE_ASSIGNMENT_STARTED = 'assignment_started';
	public const TYPE_ASSIGNMENT_ENDED = 'assignment_ended';
	public const TYPES = [
		self::TYPE_AMOUNT_CHANGED,
		self::TYPE_INTERVAL_CHANGED,
		self::TYPE_MIN_AMOUNT_OVERRIDE_SET,
		self::TYPE_GROUP_CHANGED,
		self::TYPE_ASSIGNMENT_STARTED,
		self::TYPE_ASSIGNMENT_ENDED,
	];

	public const ACTOR_MEMBER = 'member';
	public const ACTOR_STAFF = 'staff';
	public const ACTOR_SYSTEM = 'system';
	public const ACTOR_TYPES = [self::ACTOR_MEMBER, self::ACTOR_STAFF, self::ACTOR_SYSTEM];

	public function __construct() {
		$this->addType('assignmentId', 'integer');
	}

	/** @return array<string,mixed> */
	public function getDetailsArray(): array {
		if ($this->details === null || $this->details === '') {
			return [];
		}
		$decoded = json_decode($this->details, true);
		return is_array($decoded) ? $decoded : [];
	}

	/** @param array<string,mixed> $details */
	public function setDetailsArray(array $details): void {
		$this->setDetails($details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'assignmentId' => $this->assignmentId,
			'type' => $this->type,
			'actorType' => $this->actorType,
			'actorUid' => $this->actorUid,
			'onBehalfNote' => $this->onBehalfNote,
			'details' => $this->getDetailsArray(),
			'createdAt' => $this->createdAt,
		];
	}
}
