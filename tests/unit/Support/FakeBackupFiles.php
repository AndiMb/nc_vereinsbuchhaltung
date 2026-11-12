<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit\Support;

use OCA\Vereinsbuchhaltung\Service\Backup\BackupFileSource;

/**
 * {@see BackupFileSource} mit festem Inhalt – die echte Quelle hängt an der
 * Nextcloud-Dateiablage und läuft im E2E-Test.
 */
class FakeBackupFiles extends BackupFileSource {

	/**
	 * @param array<string, array<int, ?string>> $files Art → Datensatz-ID → Inhalt (null = Datei fehlt)
	 */
	public function __construct(
		public array $files = [],
		public ?string $logo = null,
	) {
	}

	public function files(): \Generator {
		foreach ($this->files as $kind => $byId) {
			foreach ($byId as $id => $content) {
				yield ['kind' => $kind, 'id' => $id, 'read' => static fn (): ?string => $content];
			}
		}
	}

	public function logo(): ?string {
		return $this->logo;
	}
}
