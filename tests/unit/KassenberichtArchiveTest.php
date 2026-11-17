<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Period;
use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\Export\KassenberichtArchive;
use OCA\Vereinsbuchhaltung\Service\Export\KassenberichtPdfRenderer;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Ablage des Kassenberichts als PDF beim Jahresabschluss (Issue #364).
 */
class KassenberichtArchiveTest extends TestCase {

	/** @var array<string, string> */
	private array $store = ['report_archive_on_close' => '1'];
	private string $storageUser = 'kasse';
	private KassenberichtPdfRenderer&MockObject $renderer;
	private IRootFolder&MockObject $root;
	private AuditService&MockObject $audit;
	/** Zielordner der Datei, siehe {@see home()} */
	private Folder&MockObject $leaf;
	/** @var list<string> Namen der angelegten Ordner */
	private array $created = [];

	protected function setUp(): void {
		$this->renderer = $this->createMock(KassenberichtPdfRenderer::class);
		$this->renderer->method('render')->willReturn('%PDF-1.7 Bericht');
		$this->renderer->method('fileName')->willReturn('Kassenbericht-2026.pdf');
		$this->root = $this->createMock(IRootFolder::class);
		$this->audit = $this->createMock(AuditService::class);
	}

	private function archive(): KassenberichtArchive {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->store[$key] ?? $default);
		$storage = $this->createMock(AttachmentStorageService::class);
		$storage->method('storageUser')->willReturnCallback(fn (): string => $this->storageUser);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []): string => vsprintf($text, $p));
		return new KassenberichtArchive($this->renderer, $storage, $this->root, $config, $this->audit, $l10n);
	}

	private function period(): Period {
		$period = new Period();
		$period->setId(7);
		$period->setLabel('2026');
		return $period;
	}

	/**
	 * Ordnerkette im Home: vorhandene Ordner werden genutzt, fehlende angelegt.
	 *
	 * Der Zielordner landet in $this->leaf, angelegte Ordner in $this->created.
	 *
	 * @param list<string> $existing schon vorhandene Pfadteile
	 */
	private function home(array $existing): Folder {
		$this->leaf = $this->createMock(Folder::class);
		$leaf = $this->leaf;
		$sub = $this->createMock(Folder::class);
		$home = $this->createMock(Folder::class);
		$home->method('nodeExists')->willReturnCallback(static fn (string $name): bool => in_array($name, $existing, true));
		$home->method('get')->willReturn($sub);
		$home->method('newFolder')->willReturnCallback(function (string $name) use ($sub): Folder {
			$this->created[] = $name;
			return $sub;
		});
		$sub->method('nodeExists')->willReturnCallback(static fn (string $name): bool => in_array($name, $existing, true));
		$sub->method('get')->willReturn($leaf);
		$sub->method('newFolder')->willReturnCallback(function (string $name) use ($leaf): Folder {
			$this->created[] = $name;
			return $leaf;
		});
		return $home;
	}

	public function testAusgeschaltetLegtNichtsAb(): void {
		$this->store['report_archive_on_close'] = '0';
		$this->renderer->expects($this->never())->method('render');
		$this->assertNull($this->archive()->storeOnClose('verein', $this->period()));
	}

	public function testOhneAblageNutzerKeinZiel(): void {
		$this->storageUser = '';
		$this->renderer->expects($this->never())->method('render');
		$this->assertNull($this->archive()->storeOnClose('verein', $this->period()));
	}

	public function testLegtPdfImBerichtsordnerAbUndProtokolliert(): void {
		$home = $this->home([]);
		$this->root->method('getUserFolder')->with('kasse')->willReturn($home);
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Kassenbericht-2026.pdf');
		$file->expects($this->once())->method('putContent')->with('%PDF-1.7 Bericht');
		$this->leaf->method('getNonExistingName')->willReturnArgument(0);
		$this->leaf->expects($this->once())->method('newFile')->with('Kassenbericht-2026.pdf')->willReturn($file);
		$this->audit->expects($this->once())->method('log')->with('Kassenbericht abgelegt', 'period', 7, $this->callback(
			static fn (array $d): bool => $d['datei'] === 'Vereinsbuchhaltung/Berichte/Kassenbericht-2026.pdf' && $d['sha256'] === hash('sha256', '%PDF-1.7 Bericht'),
		));

		$path = $this->archive()->storeOnClose('verein', $this->period());

		$this->assertSame('Vereinsbuchhaltung/Berichte/Kassenbericht-2026.pdf', $path);
		$this->assertSame(['Vereinsbuchhaltung', 'Berichte'], $this->created, 'fehlende Ordner werden angelegt');
	}

	/** Erneuter Abschluss nach Wiedereröffnung: die frühere Datei bleibt, die neue bekommt einen freien Namen. */
	public function testVorhandeneDateiWirdNichtUeberschrieben(): void {
		$home = $this->home(['Vereinsbuchhaltung', 'Berichte']);
		$this->root->method('getUserFolder')->willReturn($home);
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Kassenbericht-2026 (2).pdf');
		$this->leaf->method('getNonExistingName')->with('Kassenbericht-2026.pdf')->willReturn('Kassenbericht-2026 (2).pdf');
		$this->leaf->expects($this->once())->method('newFile')->with('Kassenbericht-2026 (2).pdf')->willReturn($file);

		$this->assertSame('Vereinsbuchhaltung/Berichte/Kassenbericht-2026 (2).pdf', $this->archive()->storeOnClose('verein', $this->period()));
		$this->assertSame([], $this->created);
	}

	public function testDateiStattOrdnerImPfadIstEinNutzerfehler(): void {
		$home = $this->createMock(Folder::class);
		$home->method('nodeExists')->willReturn(true);
		$home->method('get')->willReturn($this->createMock(File::class));
		$this->root->method('getUserFolder')->willReturn($home);

		$this->expectException(UserFacingException::class);
		$this->archive()->storeOnClose('verein', $this->period());
	}

	public function testEigenerOrdnerAusDenEinstellungen(): void {
		$this->store['report_folder'] = '/Vorstand/Kasse/';
		$this->assertSame('Vorstand/Kasse', $this->archive()->folder());
	}
}
