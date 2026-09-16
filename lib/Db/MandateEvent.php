<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Append-only-Historie eines Mandats: ein Eintrag je Zustandswechsel oder
 * Feldänderung (Spec §2.2 „Historie"). Zusätzlich zu, nicht statt, dem
 * allgemeinen {@see \OCA\Vereinsbuchhaltung\Service\AuditService} – der
 * kennt keinen `actor_type` und keine Stellvertretungs-Notiz, beides
 * verlangt Spec §3.9 („der Kanal entscheidet actor_type, nicht die
 * Identität") ausdrücklich für die Mandats-Historie.
 *
 * In diesem Ticket (#66) ist jeder Akteur `staff` (Papier-Weg, kein
 * Self-Service) oder `system` (36-Monats-Verfall-Cron); `member` kommt erst
 * mit #67 (elektronischer Einmal-Link) tatsächlich vor.
 *
 * @method int getMandateId()
 * @method void setMandateId(int $mandateId)
 * @method string getActorType()
 * @method void setActorType(string $actorType)
 * @method string|null getActorUid()
 * @method void setActorUid(?string $actorUid)
 * @method string|null getOnBehalfNote()
 * @method void setOnBehalfNote(?string $onBehalfNote)
 * @method string getMessage()
 * @method void setMessage(string $message)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class MandateEvent extends Entity implements \JsonSerializable {
	protected $mandateId;
	protected $actorType;
	protected $actorUid;
	protected $onBehalfNote;
	protected $message;
	protected $createdAt;

	public const ACTOR_MEMBER = 'member';
	public const ACTOR_STAFF = 'staff';
	public const ACTOR_SYSTEM = 'system';
	public const ACTOR_TYPES = [self::ACTOR_MEMBER, self::ACTOR_STAFF, self::ACTOR_SYSTEM];

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'mandateId' => $this->mandateId,
			'actorType' => $this->actorType,
			'actorUid' => $this->actorUid,
			'onBehalfNote' => $this->onBehalfNote,
			'message' => $this->message,
			'createdAt' => $this->createdAt,
		];
	}
}
