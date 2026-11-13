<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit\Support;

use OCA\Vereinsbuchhaltung\Service\Backup\BackupConfigStore;

/** {@see BackupConfigStore}, dessen Einstellungen sich nicht schreiben lassen. */
class FailingBackupConfigStore extends BackupConfigStore {

	public function replace(array $values): void {
		throw new \RuntimeException('Einstellungen lassen sich nicht schreiben');
	}
}
