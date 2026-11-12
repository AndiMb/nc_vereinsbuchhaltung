<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit\Support;

use OCA\Vereinsbuchhaltung\Service\Backup\BackupFileSink;

/**
 * {@see BackupFileSink} im Speicher – die echte Senke schreibt in die
 * app-interne Ablage der Nextcloud und läuft im E2E-Test.
 */
class FakeBackupSink extends BackupFileSink {

	/** @var array<string, array<int, string>> Art → Datensatz-ID → Inhalt */
	public array $stored = [];
	public ?string $logo = null;
	/** @var list<string> Reihenfolge der Aufrufe */
	public array $calls = [];

	public function __construct() {
	}

	public function store(string $kind, int $id, string $content): void {
		$this->stored[$kind][$id] = $content;
	}

	public function storeLogo(string $content): void {
		$this->logo = $content;
	}

	public function clearAppData(): void {
		$this->calls[] = 'clearAppData';
		$this->stored = [];
		$this->logo = null;
	}

	public function prepareWipe(): \Closure {
		$this->calls[] = 'prepareWipe';
		return function (): void {
			$this->calls[] = 'wipeFiles';
			$this->stored = [];
			$this->logo = null;
		};
	}
}
