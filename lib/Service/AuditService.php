<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\AuditEntry;
use OCA\Vereinsbuchhaltung\Db\AuditEntryMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCP\IUserSession;

/**
 * Änderungsprotokoll: hält fest, wer wann was geändert hat.
 * Protokollieren darf die eigentliche Aktion niemals scheitern lassen,
 * daher werden Fehler hier bewusst verschluckt.
 */
class AuditService {

	public function __construct(
		private AuditEntryMapper $mapper,
		private IUserSession $userSession,
		private TransactionRunner $transaction,
	) {
	}

	/**
	 * @param array<string,mixed> $details kleine, anzeigbare Zusatzinfos (JSON)
	 * @param string|null $actor überschreibt den angemeldeten Nutzer. Nötig für
	 *                           Hintergrundläufe (Wachordner): dort gibt es keine Sitzung, und ohne
	 *                           diesen Wert stünde im Protokoll nur "?" – für die Kassenprüfung
	 *                           wäre dann nicht erkennbar, dass die App selbst gehandelt hat.
	 */
	public function log(string $action, ?string $objectType = null, ?int $objectId = null, array $details = [], ?string $actor = null): void {
		// Zeitpunkt und Nutzer JETZT festhalten – geschrieben wird ggf. später,
		// dann ist der Aufrufkontext ein anderer.
		$ts = (new \DateTime())->format('Y-m-d H:i:s');
		$uid = $actor ?? $this->userSession->getUser()?->getUID() ?? '?';

		// Erst nach dem Commit schreiben. Zwei Gründe:
		//  - Ein fehlgeschlagenes INSERT innerhalb einer Transaktion macht auf
		//    PostgreSQL die ganze Transaktion unbrauchbar. Da Fehler hier
		//    bewusst verschluckt werden, scheiterte anschließend der Commit
		//    des eigentlichen Vorgangs mit einer irreführenden Meldung.
		//  - Ein zurückgerollter Vorgang soll auch kein Protokoll hinterlassen:
		//    protokolliert wird, was tatsächlich passiert ist.
		$this->transaction->afterCommit(function () use ($action, $objectType, $objectId, $details, $ts, $uid): void {
			try {
				$entry = new AuditEntry();
				$entry->setTs($ts);
				$entry->setUserId($uid);
				$entry->setAction(mb_substr($action, 0, 64));
				$entry->setObjectType($objectType);
				$entry->setObjectId($objectId);
				$entry->setDetails($details !== [] ? json_encode($details, JSON_UNESCAPED_UNICODE) : null);
				$this->mapper->insert($entry);
			} catch (\Throwable) {
				// Protokoll darf die Aktion nicht blockieren.
			}
		});
	}

	/** @return AuditEntry[] */
	public function latest(int $limit, int $offset): array {
		return $this->mapper->findLatest($limit, $offset);
	}
}
