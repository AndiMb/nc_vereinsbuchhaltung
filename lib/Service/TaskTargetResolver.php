<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Zielverweis der Aufgabenliste (Issue #99): die Aufgaben nennen ein
 * Fachobjekt (`objectType`/`objectId`: Mandat, Zuweisung, Forderung ...), die
 * Oberfläche springt aber in die Mitglieder-Akte - und die braucht die
 * Mitglieds-ID. Statt sie im Browser aus drei großen Listen (Mandate,
 * Zuweisungen, Forderungen) zusammenzusuchen, löst das Backend sie hier
 * auf: ein Punktzugriff je Aufgabe, und es gibt ohnehin nur wenige.
 *
 * `memberId` ist `null`, wenn die Aufgabe kein Mitglied betrifft
 * (aggregierte Einzug-Aufgaben, Läufe) oder das Objekt inzwischen gelöscht
 * ist - dann gibt es auch keine Akte, in die man springen könnte.
 */
final class TaskTargetResolver {

	public function __construct(
		private MemberMapper $members,
		private MandateMapper $mandates,
		private AssignmentMapper $assignments,
		private OpenItemMapper $openItems,
	) {
	}

	/**
	 * @param list<array<string, mixed>> $tasks Aufgaben in der Form des TaskController (severity, message, objectType, objectId ...)
	 * @return list<array<string, mixed>> dieselben Aufgaben, je um `memberId` (?int) ergänzt
	 */
	public function withMemberIds(array $tasks): array {
		/** @var array<string, int|null> $resolved */
		$resolved = [];
		$out = [];
		foreach ($tasks as $task) {
			$type = $task['objectType'] ?? null;
			$id = $task['objectId'] ?? null;
			$memberId = null;
			if (is_string($type) && is_int($id)) {
				$key = $type . ':' . $id;
				if (!array_key_exists($key, $resolved)) {
					$resolved[$key] = $this->resolve($type, $id);
				}
				$memberId = $resolved[$key];
			}
			$out[] = $task + ['memberId' => $memberId];
		}
		return $out;
	}

	private function resolve(string $type, int $id): ?int {
		try {
			return match ($type) {
				'member' => $this->members->findOrNull($id) !== null ? $id : null,
				'mandate' => $this->mandates->findOrNull($id)?->getMemberId(),
				'assignment' => $this->assignments->find($id)->getMemberId(),
				'claim' => $this->openItems->find($id)->getMemberId(),
				default => null,
			};
		} catch (DoesNotExistException) {
			return null;
		}
	}
}
