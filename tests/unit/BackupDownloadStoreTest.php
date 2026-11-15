<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Backup\BackupDownloadStore;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;

/**
 * Zwischenablage der fertigen Sicherung zwischen „erstellen“ und
 * „herunterladen“ (Issue #111).
 */
class BackupDownloadStoreTest extends TestCase {

	/** @var array<string, string> */
	private array $stored = [];
	/** @var list<string> */
	private array $tempFiles = [];

	protected function tearDown(): void {
		foreach ($this->tempFiles as $file) {
			if (is_file($file)) {
				@unlink($file);
			}
		}
	}

	private function temp(string $content = ''): string {
		$path = tempnam(sys_get_temp_dir(), 'vbhdl');
		file_put_contents($path, $content);
		$this->tempFiles[] = $path;
		return $path;
	}

	private function store(): BackupDownloadStore {
		$folder = $this->createMock(ISimpleFolder::class);
		$file = function (string $name): ISimpleFile {
			$mock = $this->createMock(ISimpleFile::class);
			$mock->method('getName')->willReturn($name);
			$mock->method('getContent')->willReturnCallback(fn (): string => $this->stored[$name]);
			$mock->method('read')->willReturnCallback(function () use ($name) {
				$stream = fopen('php://memory', 'w+b');
				fwrite($stream, $this->stored[$name]);
				rewind($stream);
				return $stream;
			});
			$mock->method('delete')->willReturnCallback(function () use ($name): void {
				unset($this->stored[$name]);
			});
			return $mock;
		};
		$folder->method('getDirectoryListing')->willReturnCallback(fn (): array => array_map($file, array_keys($this->stored)));
		$folder->method('getFile')->willReturnCallback(function (string $name) use ($file): ISimpleFile {
			if (!isset($this->stored[$name])) {
				throw new NotFoundException();
			}
			return $file($name);
		});
		$folder->method('newFile')->willReturnCallback(function (string $name, $content = null) use ($file): ISimpleFile {
			$this->stored[$name] = is_resource($content) ? (string)stream_get_contents($content) : (string)$content;
			return $file($name);
		});

		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->with('backup-downloads')->willReturn($folder);
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willReturn($appData);
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(fn (): string => $this->temp());

		return new BackupDownloadStore($factory, $temp);
	}

	public function testBereitlegenUndEinmalAbholen(): void {
		$store = $this->store();

		$token = $store->put($this->temp('verschlüsselte sicherung'), 'verein-2026.vbhbackup');

		$this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
		$this->assertCount(1, $this->stored);

		$taken = $store->take($token);
		$this->assertSame('verein-2026.vbhbackup', $taken['fileName']);
		$this->assertSame('verschlüsselte sicherung', file_get_contents($taken['path']));
		$this->assertSame([], $this->stored, 'Nach dem Abholen liegt nichts mehr in der Ablage.');
		$this->assertNull($store->take($token), 'Ein Token gilt genau einmal.');
	}

	public function testNeueSicherungVerdraengtNichtAbgeholte(): void {
		$store = $this->store();
		$first = $store->put($this->temp('eins'), 'a.vbhbackup');
		$second = $store->put($this->temp('zwei'), 'b.vbhbackup');

		$this->assertCount(1, $this->stored);
		$this->assertNull($store->take($first));
		$this->assertSame('zwei', file_get_contents($store->take($second)['path']));
	}

	public function testFremdesTokenBeruehrtDieAblageNicht(): void {
		$store = $this->store();
		$store->put($this->temp('eins'), 'a.vbhbackup');

		foreach (['', '../x', str_repeat('a', 31), str_repeat('g', 32), str_repeat('a', 32)] as $token) {
			$this->assertNull($store->take($token), $token);
		}
		$this->assertCount(1, $this->stored);
	}
}
