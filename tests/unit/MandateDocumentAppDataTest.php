<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\MandateDocumentService;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\AppConfigStub;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Mandatsnachweise in der app-internen Ablage (Issue #111): nach dem
 * Einspielen einer Sicherung gibt es die Nextcloud-Datei der Quellinstanz
 * nicht mehr; der Nachweis liegt dann app-intern unter der Mandats-ID, und
 * `document_file_id = 0` sagt das.
 */
class MandateDocumentAppDataTest extends TestCase {

	/** @var array<string, string> Dateiname → Inhalt im app-internen Ordner `mandates` */
	private array $stored = [];
	private bool $folderExists = true;
	private string $storageUser = '';
	private ?File $node = null;

	private function service(): MandateDocumentService {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('fileExists')->willReturnCallback(fn (string $name): bool => isset($this->stored[$name]));
		$folder->method('getFile')->willReturnCallback(function (string $name): ISimpleFile {
			if (!isset($this->stored[$name])) {
				throw new NotFoundException();
			}
			$file = $this->createMock(ISimpleFile::class);
			$file->method('getContent')->willReturnCallback(fn (): string => $this->stored[$name]);
			$file->method('putContent')->willReturnCallback(function (string $content) use ($name): void {
				$this->stored[$name] = $content;
			});
			$file->method('delete')->willReturnCallback(function () use ($name): void {
				unset($this->stored[$name]);
			});
			return $file;
		});
		$folder->method('newFile')->willReturnCallback(function (string $name, $content = null) use ($folder): ISimpleFile {
			$this->stored[$name] = (string)$content;
			return $folder->getFile($name);
		});
		$folder->method('delete')->willReturnCallback(function (): void {
			$this->stored = [];
			$this->folderExists = false;
		});

		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturnCallback(function (string $name) use ($folder): ISimpleFolder {
			$this->assertSame('mandates', $name);
			if (!$this->folderExists) {
				throw new NotFoundException();
			}
			return $folder;
		});
		$appData->method('newFolder')->willReturnCallback(function (string $name) use ($folder): ISimpleFolder {
			$this->assertSame('mandates', $name);
			$this->folderExists = true;
			return $folder;
		});
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willReturn($appData);

		$storage = $this->createMock(AttachmentStorageService::class);
		$storage->method('storageUser')->willReturnCallback(fn (): string => $this->storageUser);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')->willReturnCallback(fn (): ?File => $this->node);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new MandateDocumentService($root, AppConfigStub::create($this), $storage, $l10n, $factory);
	}

	private function mandate(int $id, ?int $fileId): Mandate {
		$mandate = new Mandate();
		$mandate->setId($id);
		$mandate->setMandateReference('M-' . $id);
		$mandate->setDocumentFileId($fileId);
		return $mandate;
	}

	public function testAppInternerNachweisIstVorhandenUndLesbar(): void {
		$service = $this->service();
		$service->storeInAppData(21, '%PDF-1.4 mandat');

		$this->assertSame(['21' => '%PDF-1.4 mandat'], $this->stored);
		$mandate = $this->mandate(21, MandateDocumentService::APPDATA_FILE_ID);
		$this->assertTrue($service->hasDocument($mandate));
		$this->assertSame('%PDF-1.4 mandat', $service->contentOrNull($mandate));
	}

	/** Die Zielinstanz hat nach dem Einspielen noch keinen Ablage-Nutzer – der Nachweis muss trotzdem da sein. */
	public function testOhneAblageNutzerLesbar(): void {
		$this->storageUser = '';
		$service = $this->service();
		$service->storeInAppData(21, 'inhalt');

		$this->assertFalse($service->isConfigured());
		$this->assertSame('inhalt', $service->contentOrNull($this->mandate(21, 0)));
	}

	public function testFehlendeAppDateiGiltAlsNichtVorhanden(): void {
		$service = $this->service();
		$mandate = $this->mandate(22, 0);

		$this->assertFalse($service->hasDocument($mandate));
		$this->assertNull($service->contentOrNull($mandate));
		$this->assertNull($service->documentOrNull($mandate));

		$this->folderExists = false;
		$this->assertFalse($service->hasDocument($mandate));
	}

	public function testDownloadNenntNameUndTyp(): void {
		$service = $this->service();
		$service->storeInAppData(21, "%PDF-1.4\n%mandat");
		$service->storeInAppData(22, "\x89PNG\r\n\x1a\n bild");
		$service->storeInAppData(23, 'irgendwas');

		$this->assertSame(['content' => "%PDF-1.4\n%mandat", 'name' => 'M-21.pdf', 'mimeType' => 'application/pdf'], $service->documentOrNull($this->mandate(21, 0)));
		$this->assertSame('M-22.png', $service->documentOrNull($this->mandate(22, 0))['name']);
		$this->assertSame('image/png', $service->documentOrNull($this->mandate(22, 0))['mimeType']);
		$this->assertSame(['content' => 'irgendwas', 'name' => 'M-23', 'mimeType' => 'application/octet-stream'], $service->documentOrNull($this->mandate(23, 0)));
	}

	public function testErneutesAblegenUeberschreibt(): void {
		$service = $this->service();
		$service->storeInAppData(21, 'alt');
		$service->storeInAppData(21, 'neu');
		$this->assertSame(['21' => 'neu'], $this->stored);
	}

	public function testEinzelnenUndAlleLoeschen(): void {
		$service = $this->service();
		$service->storeInAppData(21, 'a');
		$service->storeInAppData(22, 'b');

		$service->deleteFromAppData(21);
		$service->deleteFromAppData(99); // gibt es nicht – kein Fehler
		$this->assertSame(['22' => 'b'], $this->stored);

		$service->deleteAppDataFiles();
		$this->assertSame([], $this->stored);
		$service->deleteAppDataFiles(); // Ordner schon weg – kein Fehler
	}

	public function testNachweisImNutzerordnerWieBisher(): void {
		$this->storageUser = 'alice';
		$node = $this->createMock(File::class);
		$node->method('getContent')->willReturn('aus dem Ordner');
		$node->method('getName')->willReturn('M-21_mandat.pdf');
		$node->method('getMimeType')->willReturn('application/pdf');
		$this->node = $node;
		$service = $this->service();
		$mandate = $this->mandate(21, 4711);

		$this->assertTrue($service->hasDocument($mandate));
		$this->assertSame($node, $service->nodeOrNull($mandate));
		$this->assertSame(['content' => 'aus dem Ordner', 'name' => 'M-21_mandat.pdf', 'mimeType' => 'application/pdf'], $service->documentOrNull($mandate));

		// Kein Nachweis hinterlegt.
		$this->assertFalse($service->hasDocument($this->mandate(21, null)));
		$this->assertNull($service->documentOrNull($this->mandate(21, null)));
		// Ein app-interner Nachweis ist keine Datei im Dateibaum.
		$this->assertNull($service->nodeOrNull($this->mandate(21, 0)));
	}
}
