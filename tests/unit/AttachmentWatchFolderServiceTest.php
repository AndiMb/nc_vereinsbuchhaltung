<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Attachment;
use OCA\Vereinsbuchhaltung\Db\AttachmentMapper;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\AttachmentWatchFolderService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Der Ordnerscan des Wächter-Ordners: was zählt als Dokument, was nicht, und
 * wie die Zuordnung zu Buchungen daraus die Zahlen für die Übersicht macht.
 *
 * Der Dateibaum ist gemockt (OCP\Files\Folder/File); echte Ordner samt
 * Umbenennen und Verschieben bleiben den E2E-Tests vorbehalten.
 */
class AttachmentWatchFolderServiceTest extends TestCase {

	private const BOOK = '__verein__';

	private AttachmentStorageService&MockObject $storage;
	private AttachmentMapper&MockObject $mapper;

	protected function setUp(): void {
		$this->storage = $this->createMock(AttachmentStorageService::class);
		$this->storage->method('isWatchMode')->willReturn(true);
		$this->storage->method('storageUser')->willReturn('kassenwart');
		$this->mapper = $this->createMock(AttachmentMapper::class);
	}

	/**
	 * Unterordner werden durchsucht, versteckte Dateien und fremde Typen nicht
	 * mitgezählt, der Pfad relativ zum Wächter-Ordner mitgeliefert.
	 */
	public function testScanLiestUnterordnerUndFiltertFremdeDateien(): void {
		$root = $this->folder([
			$this->file(11, 'Rechnung.pdf', 'application/pdf', 100, 1000),
			$this->file(12, 'Notizen.txt', 'text/plain', 5, 2000),
			$this->file(13, '.DS_Store', 'application/octet-stream', 5, 3000),
			$this->folder([
				$this->file(14, 'Bon.jpg', 'image/jpeg', 50, 4000),
				$this->folder([], 'Lieferant'),
			], '2026'),
		]);
		$this->storage->method('watchFolder')->willReturn($root);

		$scan = $this->service()->scan();

		$this->assertNotNull($scan);
		$this->assertFalse($scan['capped']);
		$this->assertSame(
			[[14, 'Bon.jpg', '2026'], [11, 'Rechnung.pdf', '']],
			array_map(static fn (array $f): array => [$f['fileId'], $f['name'], $f['folder']], $scan['files']),
			'Neueste zuerst, nur Beleg-Typen, Pfad relativ zum Wächter-Ordner',
		);
	}

	/**
	 * Ohne Ordner kein Scan – die Übersicht meldet "nicht gefunden" und
	 * schlägt nicht je Beleg einzeln nach.
	 */
	public function testFehlenderOrdnerErgibtFolderMissing(): void {
		$this->storage->method('watchFolder')->willReturn(null);
		$this->mapper->expects($this->never())->method('findLinked');
		$this->storage->expects($this->never())->method('exists');

		$summary = $this->service()->summary(self::BOOK);

		$this->assertTrue($summary['folderMissing']);
		$this->assertSame(0, $summary['unassigned']);
		$this->assertSame(0, $summary['missing']);
	}

	/**
	 * Zugeordnet ist, worauf ein Beleg per Datei-ID zeigt. Belege, deren ID
	 * weder im Scan noch im Home des Besitzers auftaucht, fehlen.
	 */
	public function testSummaryZaehltUnzugewieseneUndFehlende(): void {
		$root = $this->folder([
			$this->file(21, 'a.pdf', 'application/pdf', 1, 1),
			$this->file(22, 'b.pdf', 'application/pdf', 1, 2),
			$this->file(23, 'c.pdf', 'application/pdf', 1, 3),
		]);
		$this->storage->method('watchFolder')->willReturn($root);
		$linkedToB = $this->linked(22);
		$linkedToGone = $this->linked(99);
		$this->mapper->method('findLinked')->willReturn([$linkedToB, $linkedToGone]);
		// Nur der Beleg außerhalb des Scans wird einzeln nachgeschlagen.
		$this->storage->expects($this->once())
			->method('exists')
			->with($linkedToGone)
			->willReturn(false);

		$summary = $this->service()->summary(self::BOOK);

		$this->assertSame(2, $summary['unassigned']);
		$this->assertSame(1, $summary['missing']);
	}

	/** Der Eingangskorb nennt zu jeder Datei die Buchungen, an denen sie hängt. */
	public function testInboxNenntDieBuchungenJeDatei(): void {
		$root = $this->folder([
			$this->file(31, 'a.pdf', 'application/pdf', 1, 1),
			$this->file(32, 'b.pdf', 'application/pdf', 1, 2),
		]);
		$this->storage->method('watchFolder')->willReturn($root);
		$this->mapper->method('findLinked')->willReturn([$this->linked(32, 7), $this->linked(32, 8)]);

		$inbox = $this->service()->inbox(self::BOOK);

		$this->assertSame([32, 31], array_column($inbox['files'], 'fileId'));
		$this->assertSame([[7, 8], []], array_column($inbox['files'], 'journalIds'));
		$this->assertSame([], $inbox['missing']);
		$this->assertSame(AttachmentWatchFolderService::MAX_FILES, $inbox['limit']);
	}

	/** Die Obergrenze bricht den Scan ab und wird gemeldet. */
	public function testScanStopptAnDerObergrenze(): void {
		$files = [];
		for ($i = 1; $i <= AttachmentWatchFolderService::MAX_FILES + 5; $i++) {
			$files[] = $this->file($i, "b$i.pdf", 'application/pdf', 1, $i);
		}
		$this->storage->method('watchFolder')->willReturn($this->folder($files));

		$scan = $this->service()->scan();

		$this->assertTrue($scan['capped']);
		$this->assertCount(AttachmentWatchFolderService::MAX_FILES, $scan['files']);
	}

	/** Verknüpfen nimmt nur, was im Ordnerbaum liegt und ein Beleg-Typ ist. */
	public function testLinkLehntFremdeDateienAb(): void {
		$root = $this->createMock(Folder::class);
		$root->method('getFirstNodeById')->willReturnCallback(fn (int $id): ?File => match ($id) {
			41 => $this->file(41, 'Liste.xlsx', 'application/vnd.ms-excel', 1, 1),
			default => null,
		});
		$this->storage->method('watchFolder')->willReturn($root);

		$service = $this->service();
		try {
			$service->link(self::BOOK, 1, 41);
			$this->fail('Fremder Typ darf nicht verknüpft werden');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('PDF', $e->getMessage());
		}
		try {
			$service->link(self::BOOK, 1, 42);
			$this->fail('Datei außerhalb des Ordners darf nicht verknüpft werden');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('nicht im Wächter-Ordner', $e->getMessage());
		}
	}

	/** Beim Verknüpfen kommen Name, Typ und Größe aus dem Knoten; den Verweis setzt die Ablage. */
	public function testLinkUebernimmtDieDateidaten(): void {
		$root = $this->createMock(Folder::class);
		$file = $this->file(51, 'Rechnung Mai.pdf', 'application/pdf', 4321, 1);
		$root->method('getFirstNodeById')->with(51)->willReturn($file);
		$this->storage->method('watchFolder')->willReturn($root);
		$this->storage->expects($this->once())->method('attach')->with($this->isInstanceOf(Attachment::class), $file);
		$this->mapper->method('insert')->willReturnArgument(0);

		$attachment = $this->service()->link(self::BOOK, 9, 51);

		$this->assertSame(9, $attachment->getJournalId());
		$this->assertSame('Rechnung Mai.pdf', $attachment->getFileName());
		$this->assertSame('application/pdf', $attachment->getMimeType());
		$this->assertSame(4321, $attachment->getFileSize());
	}

	/**
	 * @dataProvider pfadpaare
	 */
	public function testNested(string $a, string $b, bool $erwartet): void {
		$this->assertSame($erwartet, AttachmentWatchFolderService::nested($a, $b));
	}

	public static function pfadpaare(): array {
		return [
			'gleich' => ['Belege', 'Belege', true],
			'unterordner' => ['Belege/2026', 'Belege', true],
			'oberordner' => ['Belege', 'Belege/2026', true],
			'nur praefix im namen' => ['Belege2', 'Belege', false],
			'geschwister' => ['Belege', 'Auszuege', false],
			'mit schraegstrichen' => ['/Belege/', 'Belege/2026/', true],
		];
	}

	private function linked(int $fileId, int $journalId = 1): Attachment {
		$a = new Attachment();
		$a->setJournalId($journalId);
		$a->setFileId($fileId);
		$a->setFileOwner('kassenwart');
		return $a;
	}

	private function file(int $id, string $name, string $mime, int $size, int $mtime): File&MockObject {
		$f = $this->createMock(File::class);
		$f->method('getId')->willReturn($id);
		$f->method('getName')->willReturn($name);
		$f->method('getMimetype')->willReturn($mime);
		$f->method('getSize')->willReturn($size);
		$f->method('getMTime')->willReturn($mtime);
		return $f;
	}

	/** @param list<File|Folder> $children */
	private function folder(array $children, string $name = 'Belege'): Folder&MockObject {
		$f = $this->createMock(Folder::class);
		$f->method('getName')->willReturn($name);
		$f->method('getDirectoryListing')->willReturn($children);
		return $f;
	}

	/** Dieselbe Datei ein zweites Mal an dieselbe Buchung ergibt keine zweite Zeile. */
	public function testLinkLehntDoppelteVerknuepfungAb(): void {
		$root = $this->createMock(Folder::class);
		$root->method('getFirstNodeById')->with(51)->willReturn($this->file(51, 'Rechnung.pdf', 'application/pdf', 1, 1));
		$this->storage->method('watchFolder')->willReturn($root);
		$schonDa = new Attachment();
		$schonDa->setFileId(51);
		$this->mapper->method('findByJournal')->with(9, self::BOOK)->willReturn([$schonDa]);
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('bereits');
		$this->service()->link(self::BOOK, 9, 51);
	}

	/**
	 * Der Backfill sucht in der bisherigen Ablage (Nutzer und Pfad von vor dem
	 * Umschalten) und trägt diesen Nutzer als Besitzer ein – nicht den des
	 * neuen Wächter-Ordners.
	 */
	public function testBackfillSuchtInDerAltenAblage(): void {
		$alt = new Attachment();
		$alt->setId(7);
		$alt->setJournalId(42);
		$alt->setFileName('Rechnung.pdf');
		$this->mapper->method('findUnlinked')->with(self::BOOK)->willReturn([$alt]);
		$this->storage->method('getNcFilePath')->with(7, 42, 'Rechnung.pdf', 'Alt/Belege')->willReturn('Alt/Belege/42/7_Rechnung.pdf');

		$datei = $this->file(77, '7_Rechnung.pdf', 'application/pdf', 1, 1);
		$home = $this->createMock(Folder::class);
		$home->method('get')->with('Alt/Belege/42/7_Rechnung.pdf')->willReturn($datei);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('altnutzer')->willReturn($home);

		$this->storage->expects($this->once())->method('attach')->with($alt, $datei, 'altnutzer');
		$this->mapper->expects($this->once())->method('update')->with($alt);

		$this->assertSame(1, $this->service($rootFolder)->backfillFileIds(self::BOOK, 'altnutzer', 'Alt/Belege'));
	}

	private function service(?IRootFolder $rootFolder = null): AttachmentWatchFolderService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters),
		);
		return new AttachmentWatchFolderService(
			$this->storage,
			$this->mapper,
			$rootFolder ?? $this->createMock(IRootFolder::class),
			$l10n,
		);
	}
}
