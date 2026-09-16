<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Eine Aufgabe/ein Störfall (Spec §7): ein Hinweis, dass an einer Stelle
 * Handlungsbedarf besteht oder zumindest eine Prüfung sinnvoll ist.
 *
 * Die Spec beschreibt die meisten künftigen Aufgaben (Mandat ohne Nachweis,
 * Freigabe fällig, ...) ausdrücklich als "abgeleitete Abfrage, kein Job –
 * keine Entity, kostet nichts". Für dieses Ticket gibt es aber zwei
 * ereignisgetriebene Fälle, die sich nicht aus dem aktuellen Zustand ableiten
 * lassen, weil sie ein historisches Ereignis dokumentieren statt einen
 * andauernden Zustand: die Mitglieder-Übernahme aus der Migration und die
 * gerettete Mailadresse nach einer NC-Kontolöschung. Diese Tabelle ist die
 * dafür nötige, bewusst minimale Grundlage – ein generisches Aufgaben-Konzept
 * gab es im Bestand noch nicht (siehe PR-Beschreibung). Spätere Tickets
 * (Mandats-Lifecycle, Einzugszyklus, ...) entscheiden selbst, ob sie eigene
 * Fälle hier einhängen oder bei einer reinen Abfrage bleiben.
 *
 * @method string getSeverity()
 * @method void setSeverity(string $severity)
 * @method string getMessage()
 * @method void setMessage(string $message)
 * @method string|null getObjectType()
 * @method void setObjectType(?string $objectType)
 * @method int|null getObjectId()
 * @method void setObjectId(?int $objectId)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class Task extends Entity implements \JsonSerializable {
	protected $severity;
	protected $message;
	protected $objectType;
	protected $objectId;
	protected $createdAt;

	public const SEVERITY_HINT = 'hinweis';
	public const SEVERITY_ACTION_REQUIRED = 'handlungsbedarf';

	public function __construct() {
		$this->addType('objectId', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'severity' => $this->severity,
			'message' => $this->message,
			'objectType' => $this->objectType,
			'objectId' => $this->objectId,
			'createdAt' => $this->createdAt,
		];
	}
}
