<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Attachment;
use OCA\Vereinsbuchhaltung\Db\AttachmentMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Prüft den Lesestrom der Belegablage – die eine Stelle, an der die beiden
 * Ablagearten wirklich verschiedene Schnittstellen anbieten.
 *
 * Hintergrund ist Issue #40: der Lesestrom wurde in beiden Zweigen per fopen()
 * auf. Im Nextcloud-Dateibaum gibt es das (OCP\Files\File), in der app-internen
 * Ablage nicht (ISimpleFile kennt nur read()). Der ZIP-Export der Belege lief
 * dadurch bei app-interner Ablage für jeden einzelnen Beleg in einen
 * Undefined-Method-Fehler und meldete alle Belege als fehlend – während das
 * Öffnen einzelner Belege über contentOf() weiter funktionierte und den
 * Fehler damit verdeckte.
 *
 * Deshalb wird hier bewusst gegen beide Ablagearten getestet: ein Test nur für
 * den Nextcloud-Modus wäre grün geblieben.
 */
class AttachmentStorageServiceTest extends TestCase {

	private const JOURNAL_ID = 42;
	private const ATTACHMENT_ID = 7;
	private const FILE_NAME = 'Rechnung Mai 2026.pdf';
	/** Aus ID und bereinigtem Dateinamen – so legt putFile() die Datei im Dateibaum ab. */
	private const NC_FILE_NAME = '7_Rechnung_Mai_2026.pdf';
	private const NC_USER = 'kassenwart';
	private const NC_PATH = 'Vereinsbuchhaltung/Belege';

	private IAppData&MockObject $appData;
	private IRootFolder&MockObject $rootFolder;
	private IConfig&MockObject $config;

	protected function setUp(): void {
		$this->appData = $this->createMock(IAppData::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->config = $this->createMock(IConfig::class);
	}

	/**
	 * App-interne Ablage: die Datei ist eine ISimpleFile, der Strom kommt aus
	 * read(). Genau dieser Fall war in Issue #40 kaputt.
	 */
	public function testAppInterneAblageLiefertLesestrom(): void {
		$this->configureStorage('');

		$file = $this->createMock(ISimpleFile::class);
		$file->method('read')->willReturn($this->memoryStream('BELEG-INHALT'));

		$folder = $this->createMock(ISimpleFolder::class);
		$folder->expects($this->once())
			->method('getFile')
			->with((string)self::ATTACHMENT_ID)
			->willReturn($file);
		$this->appData->method('getFolder')->with('attachments')->willReturn($folder);

		$stream = $this->service()->streamOf($this->attachment());

		$this->assertIsResource($stream);
		$this->assertSame('BELEG-INHALT', stream_get_contents($stream));
		fclose($stream);
	}

	/** Nextcloud-Ablage: die Datei ist eine File, der Strom kommt aus fopen(). */
	public function testNextcloudAblageLiefertLesestrom(): void {
		$this->configureStorage(self::NC_USER);

		$file = $this->createMock(File::class);
		$file->expects($this->once())
			->method('fopen')
			->with('r')
			->willReturn($this->memoryStream('BELEG-INHALT'));

		$this->rootFolder->method('getUserFolder')
			->with(self::NC_USER)
			->willReturn($this->ncUserFolder($file));

		$stream = $this->service()->streamOf($this->attachment());

		$this->assertIsResource($stream);
		$this->assertSame('BELEG-INHALT', stream_get_contents($stream));
		fclose($stream);
	}

	/**
	 * Kein Strom ist ein Fehler, kein stiller Rückgabewert – sonst liefe der
	 * ZIP-Export mit einem false statt einer Datei weiter.
	 */
	public function testNichtLesbarerBelegWirftAusnahme(): void {
		$this->configureStorage('');

		$file = $this->createMock(ISimpleFile::class);
		$file->method('read')->willReturn(false);

		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getFile')->willReturn($file);
		$this->appData->method('getFolder')->willReturn($folder);

		$this->expectException(\RuntimeException::class);
		$this->service()->streamOf($this->attachment());
	}

	/**
	 * Liegt im Dateibaum an der Stelle des Belegs ein Ordner, kommt eine
	 * verständliche Meldung statt eines Aufrufs ins Leere – dieselbe Zusage,
	 * die contentOf() über ncFile() schon gibt.
	 */
	public function testOrdnerStattDateiWirftVerstaendlicheAusnahme(): void {
		$this->configureStorage(self::NC_USER);

		$this->rootFolder->method('getUserFolder')
			->with(self::NC_USER)
			->willReturn($this->ncUserFolder($this->createMock(Folder::class)));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('keine Datei');
		$this->service()->streamOf($this->attachment());
	}

	/**
	 * Baut die Ordnerkette Home -> Vereinsbuchhaltung -> Belege -> <BuchungsID>,
	 * an deren Ende der übergebene Knoten unter dem Belegnamen liegt.
	 */
	private function ncUserFolder(object $leaf): Folder {
		$journalFolder = $this->createMock(Folder::class);
		$journalFolder->method('get')->with(self::NC_FILE_NAME)->willReturn($leaf);

		$parts = explode('/', self::NC_PATH);
		$current = $journalFolder;
		foreach (array_reverse(array_merge($parts, [(string)self::JOURNAL_ID])) as $name) {
			$parent = $this->createMock(Folder::class);
			$parent->method('nodeExists')->with($name)->willReturn(true);
			$parent->method('get')->with($name)->willReturn($current);
			$current = $parent;
		}
		return $current;
	}

	/** Ein Beleg der App-Ablage – ohne Dateiverweis, der Pfad wird berechnet. */
	private function attachment(): Attachment {
		$a = new Attachment();
		$a->setId(self::ATTACHMENT_ID);
		$a->setJournalId(self::JOURNAL_ID);
		$a->setFileName(self::FILE_NAME);
		return $a;
	}

	/** @return resource */
	private function memoryStream(string $content) {
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, $content);
		rewind($stream);
		return $stream;
	}

	private function configureStorage(string $user, string $mode = ''): void {
		$this->config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key) use ($user, $mode): string {
				self::assertSame(Application::APP_ID, $app);
				return match ($key) {
					AttachmentStorageService::SETTING_USER => $user,
					AttachmentStorageService::SETTING_PATH => self::NC_PATH,
					AttachmentStorageService::SETTING_MODE => $mode,
					default => '',
				};
			},
		);
	}

	/**
	 * Im Wächter-Modus wird die Datei nur im Wächter-Ordner gesucht: was
	 * hinausgeschoben wurde, gilt als fehlend und bleibt nicht über die App
	 * für alle Leser erreichbar.
	 */
	public function testImWaechterModusZaehltNurDerWaechterOrdner(): void {
		$this->configureStorage(self::NC_USER, AttachmentStorageService::MODE_WATCH);

		$inside = $this->createMock(File::class);
		$watch = $this->createMock(Folder::class);
		$watch->method('getFirstNodeById')->willReturnCallback(
			static fn (int $id): ?File => $id === 99 ? $inside : null,
		);
		$home = $this->createMock(Folder::class);
		$home->method('get')->with(self::NC_PATH)->willReturn($watch);
		// Das ganze Home wird nicht durchsucht – dort läge auch die hinausgeschobene Datei.
		$home->expects($this->never())->method('getFirstNodeById');
		$this->rootFolder->method('getUserFolder')->with(self::NC_USER)->willReturn($home);

		$service = $this->service();
		$this->assertSame($inside, $service->nodeOrNull($this->linked(99)));
		$this->assertNull($service->nodeOrNull($this->linked(100)), 'außerhalb des Wächter-Ordners = fehlend');
		$this->assertFalse($service->exists($this->linked(100)));
	}

	/**
	 * Lesen legt keine Ordner an und fällt für Belege aus der app-internen
	 * Zeit auf diese zurück – sonst entstünden beim Öffnen eines Altbelegs im
	 * Wächter-Ordner leere Buchungsordner, und der Beleg bliebe unlesbar.
	 */
	public function testLesenLegtKeinenOrdnerAnUndFaelltAufAppDataZurueck(): void {
		$this->configureStorage(self::NC_USER, AttachmentStorageService::MODE_WATCH);

		$home = $this->createMock(Folder::class);
		$home->method('nodeExists')->willReturn(false);
		$home->expects($this->never())->method('newFolder');
		$this->rootFolder->method('getUserFolder')->with(self::NC_USER)->willReturn($home);

		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn('ALT-INHALT');
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getFile')->with((string)self::ATTACHMENT_ID)->willReturn($file);
		$this->appData->method('getFolder')->with('attachments')->willReturn($folder);

		$this->assertSame('ALT-INHALT', $this->service()->contentOf($this->attachment()));
	}

	/** Ein Beleg mit Verweis auf eine Datei im Home des Ablage-Nutzers. */
	private function linked(int $fileId): Attachment {
		$a = $this->attachment();
		$a->setFileId($fileId);
		$a->setFileOwner(self::NC_USER);
		return $a;
	}

	/**
	 * Die Ordnerwahl in den Einstellungen bekommt nur Ordner, keine Dateien
	 * und nichts Verstecktes – natürlich sortiert, mit Pfad relativ zum Home.
	 */
	public function testSubfoldersAtListetNurSichtbareOrdnerSortiert(): void {
		$hidden = $this->createMock(Folder::class);
		$hidden->method('getName')->willReturn('.hidden');
		$b = $this->createMock(Folder::class);
		$b->method('getName')->willReturn('belege 10');
		$a = $this->createMock(Folder::class);
		$a->method('getName')->willReturn('Belege 9');
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('x.pdf');

		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$b, $file, $hidden, $a]);
		$home = $this->createMock(Folder::class);
		$home->method('get')->with('Vereinsbuchhaltung')->willReturn($folder);
		$this->rootFolder->method('getUserFolder')->with(self::NC_USER)->willReturn($home);

		$this->assertSame(
			[
				['name' => 'Belege 9', 'path' => 'Vereinsbuchhaltung/Belege 9'],
				['name' => 'belege 10', 'path' => 'Vereinsbuchhaltung/belege 10'],
			],
			$this->service()->subfoldersAt(self::NC_USER, 'Vereinsbuchhaltung'),
		);
	}

	/** Leerer Pfad ist das Home selbst; ein unbekannter Pfad ergibt null statt einer Ausnahme. */
	public function testSubfoldersAtHomeUndUnbekannterPfad(): void {
		$top = $this->createMock(Folder::class);
		$top->method('getName')->willReturn('Dokumente');
		$home = $this->createMock(Folder::class);
		$home->method('getDirectoryListing')->willReturn([$top]);
		$home->method('get')->willThrowException(new \OCP\Files\NotFoundException());
		$this->rootFolder->method('getUserFolder')->willReturn($home);

		$service = $this->service();
		$this->assertSame([['name' => 'Dokumente', 'path' => 'Dokumente']], $service->subfoldersAt(self::NC_USER, ''));
		$this->assertNull($service->subfoldersAt(self::NC_USER, 'gibt-es-nicht'));
	}

	private function service(): AttachmentStorageService {
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willReturn($this->appData);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters),
		);

		return new AttachmentStorageService(
			$factory,
			$this->rootFolder,
			$this->config,
			$this->createMock(AttachmentMapper::class),
			$this->createMock(TransactionRunner::class),
			$l10n,
		);
	}
}
