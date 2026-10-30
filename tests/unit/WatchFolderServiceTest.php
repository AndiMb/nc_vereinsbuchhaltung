<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\ImportService;
use OCA\Vereinsbuchhaltung\Service\RevisionService;
use OCA\Vereinsbuchhaltung\Service\WatchFolderService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Der Wachordner selbst hängt an Nextclouds Dateizugriff und wird auf der
 * Testinstanz geprüft. Testbar sind hier die Namenslogik – sie entscheidet
 * darüber, ob eine bereits verarbeitete Datei überschrieben wird – und mit
 * gemocktem Dateibaum, welche Dateien der Import überhaupt anfasst.
 */
class WatchFolderServiceTest extends TestCase {

	public function testZeitstempelKommtVorDieDateiendung(): void {
		$this->assertSame(
			'auszug_20260730-101500.csv',
			WatchFolderService::stampedName('auszug.csv', '20260730-101500'),
		);
	}

	public function testMehrfachePunkteNutzenDenLetzten(): void {
		$this->assertSame(
			'auszug.2026-01_20260730-101500.xml',
			WatchFolderService::stampedName('auszug.2026-01.xml', '20260730-101500'),
		);
	}

	public function testOhneEndungWirdAngehaengt(): void {
		$this->assertSame(
			'auszug_20260730-101500',
			WatchFolderService::stampedName('auszug', '20260730-101500'),
		);
	}

	/** Ein führender Punkt kennzeichnet eine versteckte Datei, keine Endung. */
	public function testVersteckteDateiBehaeltIhrenNamen(): void {
		$this->assertSame(
			'.auszug_20260730-101500',
			WatchFolderService::stampedName('.auszug', '20260730-101500'),
		);
	}

	/**
	 * PDFs und Bilder sind Belege, nie Kontoauszüge: der Import kennt nur CSV,
	 * MT940 und CAMT. Liegen sie im Wachordner – etwa weil er doch mit dem
	 * Belegordner zusammenfällt (#220) –, bleiben sie unangetastet, statt mit
	 * einer Fehlerdatei nach „fehler/" zu wandern.
	 */
	public function testBelegeBleibenLiegen(): void {
		$beleg = $this->createMock(File::class);
		$beleg->method('getName')->willReturn('Rechnung2026.pdf');
		$beleg->method('getMimetype')->willReturn('application/pdf');
		$beleg->method('getSize')->willReturn(4096);
		$beleg->expects($this->never())->method('move');
		$beleg->expects($this->never())->method('getContent');

		$auszug = $this->createMock(File::class);
		$auszug->method('getName')->willReturn('auszug.csv');
		$auszug->method('getMimetype')->willReturn('text/csv');
		$auszug->method('getSize')->willReturn(512);
		$auszug->method('getContent')->willReturn('Buchungstag;Betrag');

		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$beleg, $auszug]);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFolder')->willReturn($this->createMock(Folder::class));
		$home = $this->createMock(Folder::class);
		$home->method('nodeExists')->with('Kontoauszuege')->willReturn(true);
		$home->method('get')->with('Kontoauszuege')->willReturn($folder);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('buchhaltung')->willReturn($home);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnMap([
			['vereinsbuchhaltung', WatchFolderService::SETTING_USER, '', 'buchhaltung'],
			['vereinsbuchhaltung', WatchFolderService::SETTING_PATH, '', 'Kontoauszuege'],
		]);
		$import = $this->createMock(ImportService::class);
		$import->expects($this->once())->method('commit')->willReturn(['format' => 'csv', 'new' => 0]);

		$service = new WatchFolderService(
			$config,
			$rootFolder,
			$import,
			$this->createMock(AuditService::class),
			$this->createMock(RevisionService::class),
			$this->createMock(LoggerInterface::class),
		);

		$this->assertSame(['auszug.csv'], array_column($service->run(), 'file'));
	}
}
