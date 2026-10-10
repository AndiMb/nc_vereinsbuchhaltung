<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\Task;
use OCA\Vereinsbuchhaltung\Db\TaskMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Siehe {@see Task} zur Frage, warum es diese kleine Persistenz überhaupt
 * gibt statt einer reinen abgeleiteten Abfrage.
 *
 * **Auflösung persistierter Hinweise** (Spec §3.5/§7 „niemand quittiert sie",
 * Issue #117): ein Hinweis aus einem Ereignis ist keine Zustandsabfrage, die
 * sich von selbst erledigt, und ein „Erledigt"-Knopf wäre genau das Quittieren,
 * das die Aufgabenliste nicht kennt. Deshalb verschwindet er aus
 * {@see findCurrent()} (die Zeile bleibt in der Tabelle), sobald eines davon
 * zutrifft:
 * - **Bezug entfallen**: das Mitglied, um das es geht, gibt es nicht mehr oder
 *   ist anonymisiert – es gäbe nichts mehr zu prüfen.
 * - **Konto wieder verknüpft** („NC-Konto von X wurde gelöscht – Adresse
 *   übernommen, bitte prüfen“): das Mitglied hat wieder ein Nextcloud-Konto, die
 *   Lage ist bereinigt. Gilt für jeden Hinweis zu einem Mitglied mit
 *   Mitglieds-ID; heute gibt es dafür genau diesen einen Erzeuger
 *   ({@see \OCA\Vereinsbuchhaltung\Listener\MemberAccountDeletionListener}).
 * - **Alter**: nach {@see RETENTION_DAYS} Tagen, für alle Hinweise. Das ist der
 *   einzige Weg für „N Mitglieder übernommen – Namen/Mailadressen prüfen“ aus der
 *   Datenübernahme (Migration 138): er hat kein einzelnes Mitglied, an dem sich
 *   eine Erledigung ablesen ließe. Ein Monat reicht für einen Hinweis, der nur zur
 *   Kenntnis nimmt (er steht nie im Badge), und er räumt sich nach einem
 *   Ereignis von selbst ab, auch wenn niemand etwas tut.
 * Eine „Mitglied wurde bearbeitet“-Regel gibt es bewusst nicht: Mitglieder
 * tragen kein Änderungsdatum, und wer die Adresse prüft und für richtig befindet,
 * ändert nichts – der Hinweis bliebe sonst gerade dann stehen.
 */
class TaskService {

	/** Wie lange ein Hinweis aus einem Ereignis höchstens in der Aufgabenliste steht. */
	public const RETENTION_DAYS = 30;

	public function __construct(
		private TaskMapper $mapper,
		private MemberMapper $members,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Die persistierten Hinweise, die noch gelten (siehe Klassendoc), neueste zuerst.
	 *
	 * @return Task[]
	 */
	public function findCurrent(): array {
		$cutoff = $this->time->getDateTime()->modify('-' . self::RETENTION_DAYS . ' days')->format('Y-m-d H:i:s');
		/** @var array<int,Member>|null $members erst geladen, wenn ein Hinweis zu einem Mitglied vorliegt */
		$members = null;

		$current = [];
		foreach ($this->mapper->findAll() as $task) {
			if ($task->getCreatedAt() < $cutoff) {
				continue;
			}
			if ($task->getObjectType() === 'member' && $task->getObjectId() !== null) {
				$members ??= $this->members->findAllById();
				$member = $members[$task->getObjectId()] ?? null;
				if ($member === null || $member->isRedacted() || $member->getNcUserId() !== null) {
					continue;
				}
			}
			$current[] = $task;
		}
		return $current;
	}

	public function create(string $severity, string $message, ?string $objectType = null, ?int $objectId = null): Task {
		$task = new Task();
		$task->setSeverity($severity);
		$task->setMessage($message);
		$task->setObjectType($objectType);
		$task->setObjectId($objectId);
		$task->setCreatedAt((new \DateTime())->format('Y-m-d H:i:s'));
		return $this->mapper->insert($task);
	}
}
