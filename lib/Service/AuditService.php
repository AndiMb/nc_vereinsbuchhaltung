<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\AuditEntry;
use OCA\Vereinsbuchhaltung\Db\AuditEntryMapper;
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
	) {
	}

	/**
	 * @param array<string,mixed> $details kleine, anzeigbare Zusatzinfos (JSON)
	 */
	public function log(string $action, ?string $objectType = null, ?int $objectId = null, array $details = []): void {
		try {
			$entry = new AuditEntry();
			$entry->setTs((new \DateTime())->format('Y-m-d H:i:s'));
			$entry->setUserId($this->userSession->getUser()?->getUID() ?? '?');
			$entry->setAction(mb_substr($action, 0, 64));
			$entry->setObjectType($objectType);
			$entry->setObjectId($objectId);
			$entry->setDetails($details !== [] ? json_encode($details, JSON_UNESCAPED_UNICODE) : null);
			$this->mapper->insert($entry);
		} catch (\Throwable) {
			// Protokoll darf die Aktion nicht blockieren.
		}
	}

	/** @return AuditEntry[] */
	public function latest(int $limit, int $offset): array {
		return $this->mapper->findLatest($limit, $offset);
	}
}
