<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Backup;

use OCA\Vereinsbuchhaltung\Db\ReimbursementEvent;

/**
 * Was beim Einspielen nebenbei anfällt, während die Zeilen durchlaufen:
 * Summen für die Stimmigkeitsprüfung und die Namen, die im Ziel bekannt
 * sein sollten.
 *
 * @internal nur für {@see BackupImportService}
 */
final class BackupImportTally {
	public int $debit = 0;
	public int $credit = 0;
	/** @var array<int, list<ReimbursementEvent>> Auslagenantrag → Ereignisse */
	public array $events = [];
	/** @var array<string, true> */
	public array $users = [];
	/** @var array<string, true> */
	public array $groups = [];
	public bool $importerFound = false;
}
