<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Attachment;
use OCA\Vereinsbuchhaltung\Db\AttachmentMapper;
use OCA\Vereinsbuchhaltung\Db\Journal;
use OCA\Vereinsbuchhaltung\Db\ReceiptHashMapper;
use OCA\Vereinsbuchhaltung\Db\ReimbursementFileMapper;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\AttachmentWatchFolderService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IL10N;
use OCP\Lock\LockedException;
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
	private ReceiptHashMapper&MockObject $hashes;

	protected function setUp(): void {
		$this->storage = $this->createMock(AttachmentStorageService::class);
		$this->storage->method('isWatchMode')->willReturn(true);
		$this->storage->method('storageUser')->willReturn('kassenwart');
		$this->mapper = $this->createMock(AttachmentMapper::class);
		$this->hashes = $this->createMock(ReceiptHashMapper::class);
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

	/** Der Ordnerbaum zeigt Nummer und Datum der Buchungen; eine verschwundene Buchung bleibt ohne Nummer stehen. */
	public function testWithBookingsErgaenztNummerUndDatum(): void {
		$journal = new Journal();
		$journal->setEntryNo(12);
		$journal->setDate('2026-03-01');

		$files = AttachmentWatchFolderService::withBookings([
			['fileId' => 31, 'journalIds' => []],
			['fileId' => 32, 'journalIds' => [7, 9]],
		], [7 => $journal]);

		$this->assertSame([], $files[0]['bookings']);
		$this->assertSame([
			['journalId' => 7, 'entryNo' => 12, 'date' => '2026-03-01'],
			['journalId' => 9, 'entryNo' => null, 'date' => null],
		], $files[1]['bookings']);
		$this->assertSame([7, 9], $files[1]['journalIds']);
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

	/** Beim selben Nutzer entscheidet der Pfad – auch wenn es den Ordner noch nicht gibt. */
	public function testUeberschneidungBeimSelbenNutzerNachPfad(): void {
		$this->assertTrue($this->service()->foldersOverlap('kassenwart', 'Belege', 'kassenwart', 'Belege/Auszuege'));
		$this->assertFalse($this->service()->foldersOverlap('kassenwart', 'Belege', 'kassenwart', 'Auszuege'));
	}

	/**
	 * Zwei Nutzer, ein Ordner: der eine hat ihn angelegt, der andere sieht ihn
	 * als Freigabe unter anderem Pfad (#220). Die Pfade sagen nichts, die
	 * Datei-ID schon.
	 */
	public function testUeberschneidungUeberFreigabeDerselbeOrdner(): void {
		$rootFolder = $this->homes([
			'buchhaltung' => ['Belege' => 100],
			'admin' => ['Freigaben/Belege' => 100],
		]);
		$this->assertTrue($this->service($rootFolder)->foldersOverlap('buchhaltung', 'Belege', 'admin', 'Freigaben/Belege'));
	}

	/** Der Kontoauszugsordner liegt als Unterordner in der Freigabe des Belegordners – und umgekehrt. */
	public function testUeberschneidungUeberFreigabeVerschachtelt(): void {
		$rootFolder = $this->homes([
			'buchhaltung' => ['Belege' => 100, 'Belege/Auszuege' => 200],
			'admin' => ['Freigaben/Auszuege' => 200],
		]);
		$service = $this->service($rootFolder);
		$this->assertTrue($service->foldersOverlap('buchhaltung', 'Belege', 'admin', 'Freigaben/Auszuege'));
		$this->assertTrue($service->foldersOverlap('admin', 'Freigaben/Auszuege', 'buchhaltung', 'Belege'));
	}

	public function testKeineUeberschneidungZwischenGetrenntenOrdnern(): void {
		$rootFolder = $this->homes([
			'buchhaltung' => ['Belege' => 100],
			'admin' => ['Auszuege' => 200],
		]);
		$this->assertFalse($this->service($rootFolder)->foldersOverlap('buchhaltung', 'Belege', 'admin', 'Auszuege'));
	}

	/** Einen Ordner, den es (noch) nicht gibt, kann nichts treffen. */
	public function testKeineUeberschneidungMitFehlendemOrdner(): void {
		$rootFolder = $this->homes([
			'buchhaltung' => ['Belege' => 100],
			'admin' => [],
		]);
		$this->assertFalse($this->service($rootFolder)->foldersOverlap('buchhaltung', 'Belege', 'admin', 'Auszuege'));
	}

	/**
	 * Ein Dateibaum je Nutzer: Pfad => Datei-ID. Dieselbe ID bei zwei Nutzern
	 * ist derselbe Ordner, gesehen über eine Freigabe.
	 *
	 * @param array<string, array<string, int>> $trees
	 */
	private function homes(array $trees): IRootFolder {
		$homes = [];
		foreach ($trees as $uid => $tree) {
			$base = '/' . $uid . '/files';
			$home = $this->createMock(Folder::class);
			$home->method('get')->willReturnCallback(function (string $path) use ($tree, $base): Folder {
				if (!isset($tree[$path])) {
					throw new \OCP\Files\NotFoundException($path);
				}
				$folder = $this->createMock(Folder::class);
				$folder->method('getId')->willReturn($tree[$path]);
				$folder->method('getPath')->willReturn($base . '/' . $path);
				return $folder;
			});
			$home->method('getFirstNodeById')->willReturnCallback(function (int $id) use ($tree, $base): ?Node {
				$path = array_search($id, $tree, true);
				if ($path === false) {
					return null;
				}
				$node = $this->createMock(Folder::class);
				$node->method('getPath')->willReturn($base . '/' . $path);
				return $node;
			});
			$home->method('getRelativePath')->willReturnCallback(
				static fn (string $path): ?string => str_starts_with($path, $base . '/') ? substr($path, strlen($base)) : null,
			);
			$homes[$uid] = $home;
		}
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturnCallback(static fn (string $uid): Folder => $homes[$uid]);
		return $rootFolder;
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

	/** @param list<int> $journalIds */
	private static function row(int $fileId, string $name, int $mtime, array $journalIds = [], string $folder = '', int $size = 100): array {
		return ['fileId' => $fileId, 'name' => $name, 'folder' => $folder, 'size' => $size, 'mtime' => $mtime, 'mime' => 'application/pdf', 'journalIds' => $journalIds];
	}

	/** @param list<array<string,mixed>> $files */
	private static function verdicts(array $files): array {
		$out = [];
		foreach ($files as $f) {
			$out[$f['fileId']] = $f['verdict'];
		}
		ksort($out);
		return $out;
	}

	/** Hängt eine Kopie an einer Buchung, sind die freien entbehrlich. */
	public function testZugeordneteKopieMachtDieAnderenEntbehrlich(): void {
		$groups = AttachmentWatchFolderService::classifyDuplicates([[
			self::row(1, 'a.pdf', 10),
			self::row(2, 'b.pdf', 20, [7]),
			self::row(3, 'c.pdf', 5),
		]]);

		$this->assertCount(1, $groups);
		$this->assertSame([1 => 'removable', 2 => 'assigned', 3 => 'removable'], self::verdicts($groups[0]['files']));
		$this->assertFalse($groups[0]['doubleBooked']);
	}

	/** Ohne Zuordnung bleibt die älteste Kopie, bei Gleichstand nach Name, dann Ordner. */
	public function testOhneZuordnungBleibtDieAelteste(): void {
		$groups = AttachmentWatchFolderService::classifyDuplicates([
			[self::row(1, 'b.pdf', 10), self::row(2, 'a.pdf', 30), self::row(3, 'c.pdf', 20)],
			[self::row(4, 'x.pdf', 10, [], 'z'), self::row(5, 'x.pdf', 10, [], 'a'), self::row(6, 'w.pdf', 10, [], 'z')],
		]);

		$all = array_merge(...array_map(static fn (array $g): array => $g['files'], $groups));
		$this->assertSame(
			[1 => 'keep', 2 => 'removable', 3 => 'removable', 4 => 'removable', 5 => 'removable', 6 => 'keep'],
			self::verdicts($all),
		);
	}

	/** Gleichstand bei mtime und Name: der Ordner entscheidet. */
	public function testGleichstandEntscheidetDerOrdner(): void {
		$groups = AttachmentWatchFolderService::classifyDuplicates([
			[self::row(4, 'x.pdf', 10, [], 'z'), self::row(5, 'x.pdf', 10, [], 'a')],
		]);

		$this->assertSame([4 => 'removable', 5 => 'keep'], self::verdicts($groups[0]['files']));
	}

	/** Zwei Kopien an verschiedenen Buchungen: vermutlich doppelt gebucht. */
	public function testKopienAnVerschiedenenBuchungenSindDoppeltGebucht(): void {
		$groups = AttachmentWatchFolderService::classifyDuplicates([[
			self::row(1, 'a.pdf', 10, [7]),
			self::row(2, 'b.pdf', 20, [8]),
		]]);

		$this->assertTrue($groups[0]['doubleBooked']);
		$this->assertSame([1 => 'assigned', 2 => 'assigned'], self::verdicts($groups[0]['files']));
	}

	/** Beide Kopien an derselben Buchung: unschön, aber nicht doppelt gebucht. */
	public function testKopienAnDerselbenBuchungSindNichtDoppeltGebucht(): void {
		$groups = AttachmentWatchFolderService::classifyDuplicates([[
			self::row(1, 'a.pdf', 10, [7]),
			self::row(2, 'b.pdf', 20, [7]),
		]]);

		$this->assertFalse($groups[0]['doubleBooked']);
	}

	/** Dieselbe Datei an zwei Buchungen (Sammelrechnung) ist keine Dublette. */
	public function testGleicheDateiAnZweiBuchungenIstKeineDublette(): void {
		$groups = AttachmentWatchFolderService::classifyDuplicates([
			[self::row(1, 'a.pdf', 10, [7, 8])],
			[self::row(2, 'b.pdf', 10, [7]), self::row(2, 'b.pdf', 10, [7])],
		]);

		$this->assertSame([], $groups);
	}

	/** Gruppen mit mehr Entbehrlichem zuerst, dann die größeren. */
	public function testGruppenNachEntbehrlichemUndGroesse(): void {
		$groups = AttachmentWatchFolderService::classifyDuplicates([
			[self::row(1, 'a.pdf', 1, [], '', 10), self::row(2, 'a.pdf', 2, [], '', 10)],
			[self::row(3, 'b.pdf', 1, [], '', 500), self::row(4, 'b.pdf', 2, [], '', 500)],
			[self::row(5, 'c.pdf', 1, [], '', 5), self::row(6, 'c.pdf', 2, [], '', 5), self::row(7, 'c.pdf', 3, [], '', 5)],
		]);

		$this->assertSame([5, 500, 10], array_map(static fn (array $g): int => $g['size'], $groups));
	}

	/**
	 * Ein Ordner-Mock mit Pfad und ID; `$names` sind die Einträge, die es
	 * darin schon gibt, `$children` Unterordner nach Name.
	 *
	 * @param list<string> $names
	 * @param array<array-key, Node> $children nach Name; PHP macht aus "2024" einen int-Schlüssel
	 */
	private function dir(int $id, string $path, array $names = [], array $children = []): Folder&MockObject {
		$f = $this->createMock(Folder::class);
		$f->method('getId')->willReturn($id);
		$f->method('getPath')->willReturn($path);
		$f->method('nodeExists')->willReturnCallback(static fn (string $n): bool => in_array($n, $names, true) || isset($children[$n]));
		$f->method('get')->willReturnCallback(static fn (string $n): Node => $children[$n]);
		return $f;
	}

	/** Eine Datei, die in `$parent` liegt und per ID im Wächter-Ordner gefunden wird. */
	private function movable(int $id, string $name, Folder $parent): File&MockObject {
		$f = $this->file($id, $name, 'application/pdf', 1, 1);
		$f->method('getParent')->willReturn($parent);
		return $f;
	}

	/** @param array<int, File> $files */
	private function watchRoot(Folder&MockObject $root, array $files): void {
		$root->method('getFirstNodeById')->willReturnCallback(static fn (int $id): ?File => $files[$id] ?? null);
		$this->storage->method('watchFolder')->willReturn($root);
	}

	private function linkedFiles(int ...$fileIds): void {
		$this->mapper->method('findLinked')->with(self::BOOK)->willReturn(array_map(static function (int $id): Attachment {
			$a = new Attachment();
			$a->setFileId($id);
			$a->setJournalId(1);
			return $a;
		}, $fileIds));
	}

	/** Fehlende Unterordner werden angelegt, die Datei landet darin. */
	public function testMoveVerschiebtInUnterordnerUndLegtIhnAn(): void {
		$root = $this->dir(1, '/kw/files/Belege');
		$klaeren = $this->dir(2, '/kw/files/Belege/_Klären');
		$root->expects($this->once())->method('newFolder')->with('_Klären')->willReturn($klaeren);
		$datei = $this->movable(11, 'Rechnung.pdf', $root);
		$datei->expects($this->once())->method('move')->with('/kw/files/Belege/_Klären/Rechnung.pdf');
		$this->watchRoot($root, [11 => $datei]);
		$this->linkedFiles();

		$result = $this->service()->move(self::BOOK, [11], '/_Klären/');

		$this->assertSame(['moved' => [11], 'skipped' => []], $result);
	}

	/** Verschachtelte Ziele: vorhandene Ordner werden benutzt, nur der Rest angelegt. */
	public function testMoveLegtNurFehlendeUnterordnerAn(): void {
		$neu = $this->dir(5, '/kw/files/Belege/2024/Klären');
		$jahr = $this->dir(3, '/kw/files/Belege/2024');
		$jahr->expects($this->once())->method('newFolder')->with('Klären')->willReturn($neu);
		$root = $this->dir(1, '/kw/files/Belege', [], ['2024' => $jahr]);
		$root->expects($this->never())->method('newFolder');
		$datei = $this->movable(11, 'Bon.jpg', $root);
		$datei->expects($this->once())->method('move')->with('/kw/files/Belege/2024/Klären/Bon.jpg');
		$this->watchRoot($root, [11 => $datei]);
		$this->linkedFiles();

		$this->assertSame([11], $this->service()->move(self::BOOK, [11], '2024/Klären')['moved']);
	}

	/** Überschrieben wird nie: belegte Namen bekommen „(2)“, „(3)“ … */
	public function testMoveBenenntBeiKonfliktUm(): void {
		$ziel = $this->dir(2, '/kw/files/Belege/_Dubletten', ['Rechnung.pdf', 'Rechnung (2).pdf']);
		$root = $this->dir(1, '/kw/files/Belege', [], ['_Dubletten' => $ziel]);
		$datei = $this->movable(11, 'Rechnung.pdf', $root);
		$datei->expects($this->once())->method('move')->with('/kw/files/Belege/_Dubletten/Rechnung (3).pdf');
		$this->watchRoot($root, [11 => $datei]);
		$this->linkedFiles();

		$this->service()->move(self::BOOK, [11], '_Dubletten');
	}

	/** Ohne Endung wird die Nummer einfach angehängt. */
	public function testMoveBenenntOhneEndungUm(): void {
		$ziel = $this->dir(2, '/kw/files/Belege/x', ['Scan']);
		$root = $this->dir(1, '/kw/files/Belege', [], ['x' => $ziel]);
		$datei = $this->movable(11, 'Scan', $root);
		$datei->expects($this->once())->method('move')->with('/kw/files/Belege/x/Scan (2)');
		$this->watchRoot($root, [11 => $datei]);
		$this->linkedFiles();

		$this->service()->move(self::BOOK, [11], 'x');
	}

	/** Inzwischen zugeordnete Dateien bleiben liegen – jemand hat parallel gebucht. */
	public function testMoveUeberspringtZugeordnete(): void {
		$root = $this->dir(1, '/kw/files/Belege', [], ['_Klären' => $this->dir(2, '/kw/files/Belege/_Klären')]);
		$datei = $this->movable(11, 'Rechnung.pdf', $root);
		$datei->expects($this->never())->method('move');
		$this->watchRoot($root, [11 => $datei]);
		$this->linkedFiles(11);

		$result = $this->service()->move(self::BOOK, [11], '_Klären');

		$this->assertSame(['moved' => [], 'skipped' => [['fileId' => 11, 'reason' => 'assigned']]], $result);
	}

	public function testMoveUeberspringtDateiImZiel(): void {
		$ziel = $this->dir(2, '/kw/files/Belege/_Klären', ['Rechnung.pdf']);
		$root = $this->dir(1, '/kw/files/Belege', [], ['_Klären' => $ziel]);
		$datei = $this->movable(11, 'Rechnung.pdf', $ziel);
		$datei->expects($this->never())->method('move');
		$this->watchRoot($root, [11 => $datei]);
		$this->linkedFiles();

		$this->assertSame([['fileId' => 11, 'reason' => 'alreadyThere']], $this->service()->move(self::BOOK, [11], '_Klären')['skipped']);
	}

	/** Dateien außerhalb des Ordners und gescheiterte Verschiebungen halten den Rest nicht auf. */
	public function testMoveUeberspringtFremdeUndGescheiterte(): void {
		$root = $this->dir(1, '/kw/files/Belege', [], ['_Klären' => $this->dir(2, '/kw/files/Belege/_Klären')]);
		$gesperrt = $this->movable(12, 'Gesperrt.pdf', $root);
		$gesperrt->method('move')->willThrowException(new LockedException('x'));
		$gut = $this->movable(13, 'Gut.pdf', $root);
		$gut->expects($this->once())->method('move');
		$this->watchRoot($root, [12 => $gesperrt, 13 => $gut]);
		$this->linkedFiles();

		$result = $this->service()->move(self::BOOK, [99, 12, 13], '_Klären');

		$this->assertSame([13], $result['moved']);
		$this->assertSame([['fileId' => 99, 'reason' => 'notFound'], ['fileId' => 12, 'reason' => 'failed']], $result['skipped']);
	}

	/** Wurzel des Wächter-Ordners als Ziel: '' und '/'. */
	public function testMoveInDieWurzel(): void {
		$unter = $this->dir(2, '/kw/files/Belege/2024');
		$root = $this->dir(1, '/kw/files/Belege');
		$datei = $this->movable(11, 'Rechnung.pdf', $unter);
		$datei->expects($this->once())->method('move')->with('/kw/files/Belege/Rechnung.pdf');
		$this->watchRoot($root, [11 => $datei]);
		$this->linkedFiles();

		$this->service()->move(self::BOOK, [11], '/');
	}

	/** @return array<string, array{string}> */
	public static function ungueltigeZiele(): array {
		return [
			'Elternordner' => ['../x'],
			'Elternordner mitten drin' => ['a/../b'],
			'Punkt' => ['a/./b'],
			'versteckt' => ['.versteckt'],
			'Doppelpunkt' => ['a:b'],
			'Backslash' => ['a\\b'],
			'leeres Segment' => ['a//b'],
			'Randleerzeichen' => [' a'],
			'Steuerzeichen' => ["a\tb"],
			'zu lang' => [str_repeat('x', 256)],
		];
	}

	/**
	 * @dataProvider ungueltigeZiele
	 */
	public function testMoveLehntUngueltigesZielAb(string $target): void {
		$root = $this->dir(1, '/kw/files/Belege');
		$root->expects($this->never())->method('newFolder');
		$this->watchRoot($root, []);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->move(self::BOOK, [11], $target);
	}

	public function testMoveLehntLeereUndZuGrosseListeAb(): void {
		$this->watchRoot($this->dir(1, '/kw/files/Belege'), []);
		foreach ([[], range(1, AttachmentWatchFolderService::MAX_MOVE + 1)] as $ids) {
			try {
				$this->service()->move(self::BOOK, $ids, 'x');
				$this->fail('Ausnahme erwartet für ' . count($ids) . ' Dateien');
			} catch (\InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	/** Ein Ziel, das eine Datei ist, ist kein Ordner. */
	public function testMoveLehntDateiAlsZielAb(): void {
		$root = $this->dir(1, '/kw/files/Belege', [], ['Rechnung.pdf' => $this->file(5, 'Rechnung.pdf', 'application/pdf', 1, 1)]);
		$this->watchRoot($root, []);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->move(self::BOOK, [11], 'Rechnung.pdf');
	}

	public function testMoveOhneOrdnerScheitert(): void {
		$this->storage->method('watchFolder')->willReturn(null);

		$this->expectException(\RuntimeException::class);
		$this->service()->move(self::BOOK, [11], 'x');
	}

	/** Eine Datei für die Dublettensuche: im Scan und per ID auffindbar, mit Prüfsumme. */
	private function hashed(int $id, int $size, int $mtime, string|\Throwable|null $hash): File&MockObject {
		$f = $this->file($id, "d$id.pdf", 'application/pdf', $size, $mtime);
		if ($hash === null) {
			$f->expects($this->never())->method('hash');
		} elseif ($hash instanceof \Throwable) {
			$f->method('hash')->willThrowException($hash);
		} else {
			$f->expects($this->once())->method('hash')->with('sha256')->willReturn($hash);
		}
		return $f;
	}

	/** @param list<File> $files */
	private function dupRoot(array $files): void {
		$root = $this->folder($files);
		$byId = [];
		foreach ($files as $f) {
			$byId[$f->getId()] = $f;
		}
		$root->method('getFirstNodeById')->willReturnCallback(static fn (int $id): ?File => $byId[$id] ?? null);
		$this->storage->method('watchFolder')->willReturn($root);
	}

	/** Nur gleich große Dateien können gleich sein – nur sie werden gehasht. */
	public function testDuplicatesHashtNurGleichGrosse(): void {
		$this->dupRoot([
			$this->hashed(11, 100, 1000, 'h1'),
			$this->hashed(12, 100, 2000, 'h1'),
			$this->hashed(13, 50, 3000, null),
			$this->hashed(14, 0, 4000, null),
			$this->hashed(15, 0, 5000, null),
		]);
		$this->linkedFiles();
		$this->hashes->method('findAll')->willReturn([]);
		$this->hashes->expects($this->exactly(2))->method('save');

		$result = $this->service()->duplicates(self::BOOK);

		$this->assertFalse($result['folderMissing']);
		$this->assertSame(0, $result['unreadable']);
		$this->assertCount(1, $result['groups']);
		$this->assertSame([11 => 'keep', 12 => 'removable'], self::verdicts($result['groups'][0]['files']));
	}

	/** Passt der gespeicherte Eintrag (Größe und mtime), wird nicht gelesen. */
	public function testDuplicatesNutztCache(): void {
		$this->dupRoot([$this->hashed(11, 100, 1000, null), $this->hashed(12, 100, 2000, null)]);
		$this->linkedFiles(12);
		$this->hashes->method('findAll')->willReturn([
			11 => ['size' => 100, 'mtime' => 1000, 'hash' => 'h1'],
			12 => ['size' => 100, 'mtime' => 2000, 'hash' => 'h1'],
		]);
		$this->hashes->expects($this->never())->method('save');

		$groups = $this->service()->duplicates(self::BOOK)['groups'];

		$this->assertSame([11 => 'removable', 12 => 'assigned'], self::verdicts($groups[0]['files']));
	}

	/** Geänderte Datei: neue mtime macht den Eintrag ungültig. */
	public function testDuplicatesRechnetBeiAenderungNeu(): void {
		$this->dupRoot([$this->hashed(11, 100, 1000, 'neu'), $this->hashed(12, 100, 2000, null)]);
		$this->linkedFiles();
		$this->hashes->method('findAll')->willReturn([
			11 => ['size' => 100, 'mtime' => 999, 'hash' => 'h1'],
			12 => ['size' => 100, 'mtime' => 2000, 'hash' => 'h1'],
		]);
		$this->hashes->expects($this->once())->method('save')->with(11, 100, 1000, 'neu');

		$this->assertSame([], $this->service()->duplicates(self::BOOK)['groups']);
	}

	public function testDuplicatesZaehltUnlesbare(): void {
		$this->dupRoot([
			$this->hashed(11, 100, 1000, 'h1'),
			$this->hashed(12, 100, 2000, new \OCP\Files\NotPermittedException('x')),
		]);
		$this->linkedFiles();
		$this->hashes->method('findAll')->willReturn([]);

		$result = $this->service()->duplicates(self::BOOK);

		$this->assertSame(1, $result['unreadable']);
		$this->assertSame([], $result['groups']);
	}

	/** Nextcloud wirft nicht immer: Common::hash() liefert false, wenn fopen scheitert. */
	public function testDuplicatesZaehltHashFalseAlsUnlesbar(): void {
		$kaputt = $this->file(12, 'd12.pdf', 'application/pdf', 100, 2000);
		$kaputt->method('hash')->willReturn(false);
		$this->dupRoot([$this->hashed(11, 100, 1000, 'h1'), $kaputt]);
		$this->linkedFiles();
		$this->hashes->method('findAll')->willReturn([]);
		$this->hashes->expects($this->once())->method('save')->with(11, 100, 1000, 'h1');

		$result = $this->service()->duplicates(self::BOOK);

		$this->assertSame(1, $result['unreadable']);
		$this->assertSame([], $result['groups']);
	}

	/**
	 * Zwei Suchen gleichzeitig schreiben denselben Eintrag – der Cache ist
	 * nur ein Hinweis, ein Konflikt darf die Suche nicht abbrechen.
	 */
	public function testDuplicatesUeberstehtFehlerBeimSpeichern(): void {
		$this->dupRoot([$this->hashed(11, 100, 1000, 'h1'), $this->hashed(12, 100, 2000, 'h1')]);
		$this->linkedFiles();
		$this->hashes->method('findAll')->willReturn([]);
		$this->hashes->method('save')->willThrowException(new \OCP\DB\Exception('unique constraint'));

		$result = $this->service()->duplicates(self::BOOK);

		$this->assertCount(1, $result['groups']);
		$this->assertSame(0, $result['unreadable']);
	}

	/** Einträge zu Dateien, die nicht mehr im Ordner liegen, fliegen raus. */
	public function testDuplicatesRaeumtVerwaisteEintraegeAuf(): void {
		$this->dupRoot([$this->hashed(11, 100, 1000, null)]);
		$this->linkedFiles();
		$this->hashes->method('findAll')->willReturn([
			11 => ['size' => 100, 'mtime' => 1000, 'hash' => 'h1'],
			77 => ['size' => 100, 'mtime' => 1000, 'hash' => 'h1'],
		]);
		$this->hashes->expects($this->once())->method('deleteByFileIds')->with([77]);

		$this->service()->duplicates(self::BOOK);
	}

	/** Gekappter Scan: was fehlt, ist nicht weg, nur ungelesen. */
	public function testDuplicatesBereinigtNichtBeiKappung(): void {
		$files = [];
		for ($i = 1; $i <= AttachmentWatchFolderService::MAX_FILES + 1; $i++) {
			$files[] = $this->file($i, "b$i.pdf", 'application/pdf', $i, $i);
		}
		$this->dupRoot($files);
		$this->linkedFiles();
		$this->hashes->method('findAll')->willReturn([999999 => ['size' => 1, 'mtime' => 1, 'hash' => 'x']]);
		$this->hashes->expects($this->never())->method('deleteByFileIds');

		$result = $this->service()->duplicates(self::BOOK);

		$this->assertTrue($result['capped']);
	}

	public function testDuplicatesOhneOrdner(): void {
		$this->storage->method('watchFolder')->willReturn(null);
		$this->hashes->expects($this->never())->method('findAll');

		$result = $this->service()->duplicates(self::BOOK);

		$this->assertTrue($result['folderMissing']);
		$this->assertSame([], $result['groups']);
	}

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
			$this->hashes,
			$this->createMock(ReimbursementFileMapper::class),
		);
	}
}
