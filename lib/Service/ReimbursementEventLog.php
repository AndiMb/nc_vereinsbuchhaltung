<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\ReimbursementEvent;
use OCA\Vereinsbuchhaltung\Db\ReimbursementEventMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Hängt Ereignisse an die Historie eines Auslagenantrags an und hält dabei die
 * Hash-Kette zusammen.
 *
 * Jedes Ereignis trägt eine lückenlose Folgenummer (`seq`, je Antrag eindeutig)
 * und den Hash seines Vorgängers. Der eigene Hash deckt alle Felder ab, die
 * etwas aussagen: wer ein Ereignis nachträglich ändert, bricht die Kette ab
 * dieser Stelle. Zwei gleichzeitig angehängte Ereignisse ermitteln dieselbe
 * Folgenummer; der Unique-Index lässt nur eines durch, das andere scheitert
 * und der Vorgang wird neu aufgesetzt (siehe ReimbursementService, der den
 * ganzen Vorgang in `TransactionRunner::runWithRetry()` klammert).
 */
class ReimbursementEventLog {

	public function __construct(
		private ReimbursementEventMapper $mapper,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @param array<string,mixed> $data Einzelheiten der Aktion, werden als JSON abgelegt
	 */
	public function append(
		int $reimbursementId,
		string $action,
		string $actorUid,
		string $actorRole,
		?string $comment = null,
		array $data = [],
	): ReimbursementEvent {
		$previous = $this->mapper->findLast($reimbursementId);

		$event = new ReimbursementEvent();
		$event->setReimbursementId($reimbursementId);
		$event->setSeq($previous === null ? 1 : $previous->getSeq() + 1);
		$event->setAction($action);
		$event->setActorUid($actorUid);
		$event->setActorRole($actorRole);
		$event->setCreatedAt($this->time->getDateTime()->format('Y-m-d H:i:s'));
		$event->setComment($comment);
		$event->setData($data === [] ? null : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		$event->setPrevHash($previous?->getHash());
		$event->setHash(self::hashOf($event));

		return $this->mapper->insert($event);
	}

	/**
	 * Wer hat den Antrag schon freigegeben?
	 *
	 * @return list<string> Nutzerkennungen, jede höchstens einmal
	 */
	public function approverUids(int $reimbursementId): array {
		return $this->mapper->approverUids($reimbursementId);
	}

	/**
	 * Freigeber für eine ganze Liste von Anträgen, in einer Abfrage.
	 *
	 * @param list<int> $reimbursementIds
	 * @return array<int, list<string>> Antrags-ID => Nutzerkennungen
	 */
	public function approverUidsFor(array $reimbursementIds): array {
		return $this->mapper->approverUidsFor($reimbursementIds);
	}

	/** @return ReimbursementEvent[] */
	public function history(int $reimbursementId): array {
		return $this->mapper->findByReimbursement($reimbursementId);
	}

	/**
	 * Prüft eine Historie auf Lücken und Brüche der Kette.
	 *
	 * Eine Oberfläche dafür gibt es in v1 nicht; die Methode ist die
	 * Grundlage für Tests und für eine spätere Prüfroutine.
	 *
	 * @param ReimbursementEvent[] $events in Reihenfolge der Folgenummer
	 */
	public static function verify(array $events): bool {
		$expectedSeq = 1;
		$previousHash = null;
		foreach ($events as $event) {
			if ($event->getSeq() !== $expectedSeq) {
				return false;
			}
			if ($event->getPrevHash() !== $previousHash) {
				return false;
			}
			if (!hash_equals(self::hashOf($event), $event->getHash())) {
				return false;
			}
			$previousHash = $event->getHash();
			$expectedSeq++;
		}
		return true;
	}

	/**
	 * Der Hash über die aussagekräftigen Felder. Die Reihenfolge der Felder
	 * und die JSON-Form sind Teil des Formats und dürfen sich nicht ändern,
	 * sonst stimmen die Hashes bestehender Ereignisse nicht mehr.
	 */
	public static function hashOf(ReimbursementEvent $event): string {
		return hash('sha256', json_encode([
			$event->getReimbursementId(),
			$event->getSeq(),
			$event->getAction(),
			$event->getActorUid(),
			$event->getActorRole(),
			$event->getCreatedAt(),
			$event->getComment(),
			$event->getData(),
			$event->getPrevHash(),
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
	}
}
