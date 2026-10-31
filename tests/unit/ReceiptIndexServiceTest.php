<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\ReceiptIndexMapper;
use OCA\Vereinsbuchhaltung\Service\AttachmentWatchFolderService;
use OCA\Vereinsbuchhaltung\Service\ReceiptIndex\ReceiptFacts;
use OCA\Vereinsbuchhaltung\Service\ReceiptIndex\ReceiptIndexService;
use OCA\Vereinsbuchhaltung\Service\ReceiptIndex\ReceiptReader;
use OCP\DB\Exception as DbException;
use OCP\Files\File;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Belegindex: nur Neues und Geändertes lesen, Limits einhalten, Verwaistes
 * abräumen.
 */
class ReceiptIndexServiceTest extends TestCase {
	private ReceiptIndexMapper&MockObject $mapper;
	private ReceiptReader&MockObject $reader;
	private AttachmentWatchFolderService&MockObject $watchFolder;
	private float $now = 0.0;

	protected function setUp(): void {
		$this->mapper = $this->createMock(ReceiptIndexMapper::class);
		$this->reader = $this->createMock(ReceiptReader::class);
		$this->watchFolder = $this->createMock(AttachmentWatchFolderService::class);
		$this->now = 0.0;
	}

	private function service(): ReceiptIndexService {
		return new ReceiptIndexService($this->mapper, $this->reader, $this->watchFolder, null, fn (): float => $this->now);
	}

	/** @return array{fileId:int, name:string, size:int, mtime:int, mime:string} */
	private static function file(int $id, int $mtime = 100): array {
		return ['fileId' => $id, 'name' => "beleg-$id.pdf", 'size' => 10, 'mtime' => $mtime, 'mime' => 'application/pdf'];
	}

	/** @return array{size:int, mtime:int, version:int, facts: ReceiptFacts} */
	private static function row(int $mtime = 100, int $version = ReceiptReader::VERSION): array {
		return ['size' => 10, 'mtime' => $mtime, 'version' => $version, 'facts' => new ReceiptFacts('name', 'ok')];
	}

	public function testLiestNurNeueUndGeaenderteDateien(): void {
		$this->mapper->method('findAll')->willReturn([1 => self::row(100), 2 => self::row(50)]);
		$read = [];
		$this->reader->method('read')->willReturnCallback(function (string $name) use (&$read): ReceiptFacts {
			$read[] = $name;
			return new ReceiptFacts('name', 'ok');
		});
		$this->mapper->expects($this->exactly(4))->method('save'); // je Datei Platzhalter + Ergebnis

		$result = $this->service()->refresh([self::file(1), self::file(2), self::file(3)], 20, 10.0);

		$this->assertSame(['beleg-2.pdf', 'beleg-3.pdf'], $read);
		$this->assertSame(['indexed' => 2, 'pending' => 0], $result);
	}

	public function testNeueParserVersionLiestNeu(): void {
		$this->mapper->method('findAll')->willReturn([1 => self::row(100, 0)]);
		$this->reader->expects($this->once())->method('read')->willReturn(new ReceiptFacts('name', 'ok'));
		$this->mapper->expects($this->exactly(2))->method('save')
			->with(1, 10, 100, ReceiptReader::VERSION, $this->isInstanceOf(ReceiptFacts::class));

		$this->assertSame(['indexed' => 1, 'pending' => 0], $this->service()->refresh([self::file(1)], 20, 10.0));
	}

	public function testRefreshHaeltSichAnDasDateilimit(): void {
		$this->mapper->method('findAll')->willReturn([]);
		$this->reader->expects($this->exactly(20))->method('read')->willReturn(new ReceiptFacts('name', 'ok'));

		$files = array_map(static fn (int $i): array => self::file($i), range(1, 50));
		$this->assertSame(['indexed' => 20, 'pending' => 30], $this->service()->refresh($files, 20, 10.0));
	}

	public function testRefreshHaeltSichAnDasZeitlimit(): void {
		$this->mapper->method('findAll')->willReturn([]);
		$this->reader->expects($this->exactly(2))->method('read')->willReturnCallback(function (): ReceiptFacts {
			$this->now += 1.0;
			return new ReceiptFacts('name', 'ok');
		});

		$files = array_map(static fn (int $i): array => self::file($i), range(1, 5));
		$this->assertSame(['indexed' => 2, 'pending' => 3], $this->service()->refresh($files, 20, 2.0));
	}

	public function testVerschwundeneDateienWerdenAbgeraeumt(): void {
		$this->mapper->method('findAll')->willReturn([1 => self::row(), 9 => self::row()]);
		$this->mapper->expects($this->once())->method('deleteByFileIds')->with([9]);

		$this->assertSame(['indexed' => 0, 'pending' => 0], $this->service()->refresh([self::file(1)], 20, 10.0));
	}

	public function testDateiNichtMehrImOrdnerZaehltAlsUnreadable(): void {
		$this->mapper->method('findAll')->willReturn([]);
		$this->watchFolder->method('fileInFolder')->willThrowException(new \RuntimeException('weg'));
		$this->reader->method('read')->willReturnCallback(
			static fn (string $n, string $m, int $s, \Closure $content): ReceiptFacts => new ReceiptFacts('name', 'ok', [], [], [], [], $content()),
		);
		$saved = null;
		$this->mapper->expects($this->exactly(2))->method('save')
			->willReturnCallback(function (int $id, int $size, int $mtime, int $version, ReceiptFacts $facts) use (&$saved): void {
				$saved = $facts;
			});

		$this->assertSame(['indexed' => 1, 'pending' => 0], $this->service()->refresh([self::file(7)], 20, 10.0));
		$this->assertSame(ReceiptFacts::STATUS_UNREADABLE, $saved?->status);
	}

	public function testBytesKommenAusDemWaechterOrdner(): void {
		$this->mapper->method('findAll')->willReturn([]);
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('%PDF-bytes');
		$this->watchFolder->expects($this->once())->method('fileInFolder')->with(4)->willReturn($file);
		$got = null;
		$this->reader->method('read')->willReturnCallback(function (string $n, string $m, int $s, \Closure $content) use (&$got): ReceiptFacts {
			$got = $content();
			return new ReceiptFacts('name', 'ok');
		});

		$this->service()->refresh([self::file(4)], 20, 10.0);
		$this->assertSame('%PDF-bytes', $got);
	}

	/**
	 * Ein Prozess, der beim Lesen stirbt (Speicher, Zeitlimit), schreibt keine
	 * Zeile mehr: darum steht der Platzhalter VOR dem Lesen in der Tabelle.
	 */
	public function testPlatzhalterZeileStehtVorDemLesen(): void {
		$this->mapper->method('findAll')->willReturn([]);
		$events = [];
		$placeholder = null;
		$this->mapper->method('save')->willReturnCallback(function (int $id, int $size, int $mtime, int $version, ReceiptFacts $facts) use (&$events, &$placeholder): void {
			$events[] = 'save:' . $facts->status;
			$placeholder ??= [$id, $size, $mtime, $version, $facts];
		});
		$this->reader->method('read')->willReturnCallback(function () use (&$events): ReceiptFacts {
			$events[] = 'read';
			return new ReceiptFacts('text', 'ok');
		});

		$this->service()->refresh([['fileId' => 3, 'name' => 'RE-2026-4711.pdf', 'size' => 10, 'mtime' => 100, 'mime' => 'application/pdf']], 20, 10.0);

		$this->assertSame(['save:unreadable', 'read', 'save:ok'], $events);
		$this->assertSame([3, 10, 100, ReceiptReader::VERSION], array_slice($placeholder, 0, 4));
		$this->assertSame(ReceiptFacts::STATUS_UNREADABLE, $placeholder[4]->status);
		$this->assertSame(['20264711'], $placeholder[4]->nameRefs);
	}

	public function testEntriesLiefertDieMerkmaleNachDateiId(): void {
		$facts = new ReceiptFacts('text', 'ok', ['1234']);
		$this->mapper->method('findAll')->willReturn([5 => ['size' => 1, 'mtime' => 2, 'version' => 1, 'facts' => $facts]]);

		$this->assertSame([5 => $facts], $this->service()->entries());
	}

	public function testGekappterScanLoeschtKeineZeilen(): void {
		$this->mapper->method('findAll')->willReturn([1 => self::row(), 9 => self::row()]);
		$this->mapper->expects($this->never())->method('deleteByFileIds');

		$this->assertSame(['indexed' => 0, 'pending' => 0], $this->service()->refresh([self::file(1)], 20, 10.0, false));
	}

	public function testUeberlappenderLaufMitDoppeltemSchluesselBrichtNichtAb(): void {
		$this->mapper->method('findAll')->willReturn([]);
		$this->reader->method('read')->willReturn(new ReceiptFacts('name', 'ok'));
		$this->mapper->expects($this->exactly(3))->method('save')->willReturnCallback(function (int $id): void {
			if ($id === 1) {
				throw $this->dbException(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
			}
		});

		$this->assertSame(['indexed' => 2, 'pending' => 0], $this->service()->refresh([self::file(1), self::file(2)], 20, 10.0));
	}

	public function testAndereDatenbankfehlerWerdenWeitergereicht(): void {
		$this->mapper->method('findAll')->willReturn([]);
		$this->reader->method('read')->willReturn(new ReceiptFacts('name', 'ok'));
		$this->mapper->method('save')->willThrowException($this->dbException(DbException::REASON_CONNECTION_LOST));

		$this->expectException(DbException::class);
		$this->service()->refresh([self::file(1)], 20, 10.0);
	}

	private function dbException(int $reason): DbException {
		$e = $this->createMock(DbException::class);
		$e->method('getReason')->willReturn($reason);
		return $e;
	}
}
