<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Attachment;
use OCA\Vereinsbuchhaltung\Db\AttachmentMapper;
use OCA\Vereinsbuchhaltung\Db\Reimbursement;
use OCA\Vereinsbuchhaltung\Db\ReimbursementFile;
use OCA\Vereinsbuchhaltung\Db\ReimbursementFileMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\ReimbursementFileService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Belegdateien eines Auslagenantrags: Prüfung beim Upload, Dubletten-Hinweis
 * und das Anhängen an die Buchung der Auszahlung.
 */
class ReimbursementFileServiceTest extends TestCase {

	/** Kleinstes gültiges PDF-Präfix; finfo erkennt es an der Magic Number. */
	private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

	private AttachmentStorageService&MockObject $storage;
	private ReimbursementFileMapper&MockObject $mapper;
	private AttachmentMapper&MockObject $attachments;

	protected function setUp(): void {
		$this->storage = $this->createMock(AttachmentStorageService::class);
		$this->mapper = $this->createMock(ReimbursementFileMapper::class);
		$this->attachments = $this->createMock(AttachmentMapper::class);
	}

	private function service(): ReimbursementFileService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-01 10:00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new ReimbursementFileService(
			$this->storage,
			$this->mapper,
			$this->attachments,
			new TransactionRunner($this->createMock(IDBConnection::class)),
			$time,
			$l10n,
		);
	}

	private function reimbursement(): Reimbursement {
		$r = new Reimbursement();
		$r->setId(7);
		$r->setExpenseDate('2026-10-15');
		return $r;
	}

	public function testLegtDieDateiMitInhaltsHashUndAusgabejahrAb(): void {
		$this->mapper->method('insert')->willReturnCallback(static function (ReimbursementFile $file): ReimbursementFile {
			$file->setId(3);
			return $file;
		});
		$this->mapper->method('update')->willReturnArgument(0);
		$this->storage->expects($this->once())->method('storeReimbursementFile')
			->with($this->isInstanceOf(ReimbursementFile::class), 2026, self::PDF);

		$file = $this->service()->add($this->reimbursement(), '../../etc/beleg.pdf', self::PDF);

		$this->assertSame('beleg.pdf', $file->getFileName(), 'Pfadteile des Namens gehören nicht in die Ablage');
		$this->assertSame('application/pdf', $file->getMimeType());
		$this->assertSame(strlen(self::PDF), $file->getFileSize());
		$this->assertSame(hash('sha256', self::PDF), $file->getContentHash());
		$this->assertSame(7, $file->getReimbursementId());
	}

	public function testLehntLeereDateienAb(): void {
		$this->mapper->expects($this->never())->method('insert');
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->add($this->reimbursement(), 'leer.pdf', '');
	}

	public function testLehntFalscheDateitypenAb(): void {
		$this->mapper->expects($this->never())->method('insert');
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->add($this->reimbursement(), 'skript.php', '<?php echo "hallo";');
	}

	public function testLehntZuGrosseDateienAb(): void {
		$this->mapper->expects($this->never())->method('insert');
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->add($this->reimbursement(), 'gross.pdf', str_repeat('x', ReimbursementFileService::MAX_SIZE + 1));
	}

	public function testRaeumtDieZeileAufWennDieAblageScheitert(): void {
		$this->mapper->method('insert')->willReturnCallback(static function (ReimbursementFile $file): ReimbursementFile {
			$file->setId(3);
			return $file;
		});
		$this->storage->method('storeReimbursementFile')->willThrowException(new \RuntimeException('Ordner fehlt'));
		$this->mapper->expects($this->once())->method('delete');

		$this->expectException(\RuntimeException::class);
		$this->service()->add($this->reimbursement(), 'beleg.pdf', self::PDF);
	}

	public function testDublettenSuchtDieInhaltsGleichenDateienAndererAntraege(): void {
		$file = new ReimbursementFile();
		$file->setReimbursementId(7);
		$file->setContentHash('abc');
		$other = new ReimbursementFile();
		$other->setReimbursementId(4);
		$this->mapper->expects($this->once())->method('findOtherByContentHash')->with('abc', 7)->willReturn([$other]);

		$this->assertSame([$other], $this->service()->duplicatesOf($file));
	}

	public function testOhneHashGibtEsKeineDubletten(): void {
		$this->mapper->expects($this->never())->method('findOtherByContentHash');
		$this->assertSame([], $this->service()->duplicatesOf(new ReimbursementFile()));
	}

	public function testEntfernenMarkiertDieZeileUndLoeschtDieDateiNachDemCommit(): void {
		$file = new ReimbursementFile();
		$file->setId(3);
		$this->mapper->expects($this->once())->method('update')->with($file);
		$this->storage->expects($this->once())->method('deleteReimbursementFile')->with($file);

		$this->service()->remove($file);

		$this->assertTrue($file->isRemoved());
	}

	public function testAuszahlungHaengtJedeDateiAlsBelegAnDieBuchung(): void {
		$first = new ReimbursementFile();
		$first->setId(3);
		$first->setFileName('a.pdf');
		$first->setMimeType('application/pdf');
		$first->setFileSize(10);
		$second = new ReimbursementFile();
		$second->setId(4);
		$second->setFileName('b.png');
		$second->setMimeType('image/png');
		$second->setFileSize(20);
		$this->mapper->method('findActive')->with(7)->willReturn([$first, $second]);

		$nextId = 100;
		$attachments = [];
		$this->attachments->method('insert')->willReturnCallback(static function (Attachment $a) use (&$nextId, &$attachments): Attachment {
			$a->setId($nextId++);
			$attachments[] = $a;
			return $a;
		});
		$this->attachments->method('update')->willReturnArgument(0);
		$moved = [];
		$this->storage->method('moveReimbursementFileToJournal')->willReturnCallback(
			static function (ReimbursementFile $file, Attachment $attachment) use (&$moved): void {
				$moved[] = [$file->getId(), $attachment->getId()];
			},
		);

		$this->service()->linkToJournal($this->reimbursement(), 42);

		$this->assertCount(2, $attachments);
		$this->assertSame(42, $attachments[0]->getJournalId());
		$this->assertSame(Application::BOOK, $attachments[0]->getUserId());
		$this->assertSame('a.pdf', $attachments[0]->getFileName());
		$this->assertSame('image/png', $attachments[1]->getMimeType());
		$this->assertSame([[3, 100], [4, 101]], $moved);
	}
}
