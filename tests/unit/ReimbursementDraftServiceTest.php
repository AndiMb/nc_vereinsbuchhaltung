<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\Reimbursement;
use OCA\Vereinsbuchhaltung\Db\ReimbursementDraft;
use OCA\Vereinsbuchhaltung\Db\ReimbursementDraftFile;
use OCA\Vereinsbuchhaltung\Db\ReimbursementDraftFileMapper;
use OCA\Vereinsbuchhaltung\Db\ReimbursementDraftMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\ReimbursementDraftCleaner;
use OCA\Vereinsbuchhaltung\Service\ReimbursementDraftService;
use OCA\Vereinsbuchhaltung\Service\ReimbursementService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Entwürfe von Auslagenanträgen (Issue #356): Speichern ohne Pflichtfelder,
 * Belege behalten/entfernen, Einreichen über den gewöhnlichen Antragsweg.
 */
class ReimbursementDraftServiceTest extends TestCase {

	private const PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";

	private ReimbursementDraftMapper&MockObject $drafts;
	private ReimbursementDraftFileMapper&MockObject $files;
	private ReimbursementService&MockObject $reimbursements;
	private ReimbursementDraftCleaner&MockObject $cleaner;
	private ReimbursementDraftService $service;

	protected function setUp(): void {
		$this->drafts = $this->createMock(ReimbursementDraftMapper::class);
		$this->files = $this->createMock(ReimbursementDraftFileMapper::class);
		$this->reimbursements = $this->createMock(ReimbursementService::class);
		$this->cleaner = $this->createMock(ReimbursementDraftCleaner::class);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-16 10:00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		$this->service = new ReimbursementDraftService(
			$this->drafts,
			$this->files,
			$this->reimbursements,
			new TransactionRunner($this->createMock(IDBConnection::class)),
			$time,
			$l10n,
			$this->cleaner,
		);

		$nextId = 100;
		$this->files->method('insert')->willReturnCallback(static function (ReimbursementDraftFile $file) use (&$nextId): ReimbursementDraftFile {
			$file->setId($nextId++);
			return $file;
		});
	}

	/** @param array<string, mixed> $overrides */
	private static function fields(array $overrides = []): array {
		return array_merge([
			'amountCents' => null, 'expenseDate' => null, 'purpose' => null, 'invoiceNumber' => null,
			'supplier' => null, 'iban' => null, 'accountHolder' => null,
		], $overrides);
	}

	private static function draft(int $id, int $memberId = 7): ReimbursementDraft {
		$draft = new ReimbursementDraft();
		$draft->setId($id);
		$draft->setMemberId($memberId);
		$draft->setCreatedAt('2026-11-15 09:00:00');
		$draft->setUpdatedAt('2026-11-15 09:00:00');
		return $draft;
	}

	private static function draftFile(int $id, int $draftId, string $name = 'beleg.pdf'): ReimbursementDraftFile {
		$file = new ReimbursementDraftFile();
		$file->setId($id);
		$file->setDraftId($draftId);
		$file->setFileName($name);
		$file->setMimeType('application/pdf');
		$file->setFileSize(10);
		$file->setUploadedAt('2026-11-15 09:00:00');
		return $file;
	}

	public function testNeuerEntwurfBrauchtKeinePflichtfelder(): void {
		$this->drafts->expects($this->once())->method('insert')->willReturnCallback(function (ReimbursementDraft $draft): ReimbursementDraft {
			$this->assertSame(7, $draft->getMemberId());
			$this->assertNull($draft->getAmountCents());
			$this->assertNull($draft->getIban());
			$this->assertSame('Fahrt zum Turnier', $draft->getPurpose());
			$draft->setId(5);
			return $draft;
		});
		$this->drafts->method('findForMember')->willReturnCallback(fn (int $id, int $memberId) => self::draft($id, $memberId));
		$this->files->method('findByDraft')->willReturn([]);
		$this->cleaner->expects($this->never())->method('store');

		$result = $this->service->save(null, 7, self::fields(['purpose' => '  Fahrt zum Turnier ']), [], []);

		$this->assertSame(5, $result['id']);
		$this->assertSame([], $result['files']);
	}

	public function testBelegeLiegenInDerEntwurfsablage(): void {
		$this->drafts->method('insert')->willReturnCallback(static function (ReimbursementDraft $draft): ReimbursementDraft {
			$draft->setId(5);
			return $draft;
		});
		$this->drafts->method('findForMember')->willReturnCallback(fn (int $id, int $memberId) => self::draft($id, $memberId));
		$this->files->method('findByDraft')->willReturn([]);
		$this->cleaner->expects($this->once())->method('store')->with(100, self::PDF);

		$this->service->save(null, 7, self::fields(), [], [['name' => 'quittung.pdf', 'content' => self::PDF]]);
	}

	public function testFalscherDateitypWirdAbgelehnt(): void {
		$this->drafts->expects($this->never())->method('insert');
		$this->expectException(\InvalidArgumentException::class);
		$this->service->save(null, 7, self::fields(), [], [['name' => 'notiz.txt', 'content' => 'Hallo']]);
	}

	public function testUngueltigesDatumWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->save(null, 7, self::fields(['expenseDate' => '2026-02-30']), [], []);
	}

	public function testNichtBehalteneBelegeVerschwinden(): void {
		$draft = self::draft(5);
		$this->drafts->method('findForMember')->willReturn($draft);
		$this->drafts->method('update')->willReturnArgument(0);
		$this->files->method('findByDraft')->willReturnOnConsecutiveCalls(
			[self::draftFile(11, 5), self::draftFile(12, 5)],
			[self::draftFile(11, 5)],
		);
		$this->files->expects($this->once())->method('delete')->with($this->callback(static fn (ReimbursementDraftFile $f): bool => $f->getId() === 12));
		$this->cleaner->expects($this->once())->method('purge')->with([12]);

		$result = $this->service->save(5, 7, self::fields(['amountCents' => 1250]), [11], []);

		$this->assertCount(1, $result['files']);
	}

	public function testFremderEntwurfIstNichtAuffindbar(): void {
		$this->drafts->method('findForMember')->willThrowException(new DoesNotExistException(''));
		$this->drafts->expects($this->never())->method('update');
		$this->expectException(DoesNotExistException::class);
		$this->service->save(5, 8, self::fields(), [], []);
	}

	public function testZuVieleBelege(): void {
		$this->drafts->method('findForMember')->willReturn(self::draft(5));
		$this->drafts->method('update')->willReturnArgument(0);
		$this->files->method('findByDraft')->willReturn(array_map(static fn (int $i) => self::draftFile($i, 5), range(1, ReimbursementService::MAX_FILES)));
		$this->expectException(\InvalidArgumentException::class);
		$this->service->save(5, 7, self::fields(), range(1, ReimbursementService::MAX_FILES), [['name' => 'x.pdf', 'content' => self::PDF]]);
	}

	public function testEinreichenNimmtBehalteneBelegeMitUndLoeschtDenEntwurf(): void {
		$draft = self::draft(5);
		$this->drafts->method('findForMember')->willReturn($draft);
		$this->files->method('findByDraft')->willReturn([self::draftFile(11, 5, 'a.pdf'), self::draftFile(12, 5, 'b.pdf')]);
		$this->cleaner->method('content')->willReturnMap([[11, 'INHALT-A'], [12, 'INHALT-B']]);

		$member = new Member();
		$member->setId(7);
		$created = new Reimbursement();
		$created->setId(99);
		$this->reimbursements->expects($this->once())->method('submit')->with(
			$member, 1250, '2026-11-10', 'Bälle', null, null, 'DE02120300000000202051', 'Max', true,
			[['name' => 'a.pdf', 'content' => 'INHALT-A'], ['name' => 'neu.pdf', 'content' => self::PDF]],
			'max',
		)->willReturn($created);
		$this->files->expects($this->exactly(2))->method('delete');
		$this->drafts->expects($this->once())->method('delete')->with($draft);
		$this->cleaner->expects($this->once())->method('purge')->with([11, 12]);

		$result = $this->service->submit(5, $member, self::fields([
			'amountCents' => 1250, 'expenseDate' => '2026-11-10', 'purpose' => 'Bälle',
			'iban' => 'DE02120300000000202051', 'accountHolder' => 'Max',
		]), true, [11], [['name' => 'neu.pdf', 'content' => self::PDF]], 'max');

		$this->assertSame(99, $result->getId());
	}

	public function testScheiterndesEinreichenLaesstDenEntwurfStehen(): void {
		$this->drafts->method('findForMember')->willReturn(self::draft(5));
		$this->files->method('findByDraft')->willReturn([]);
		$this->reimbursements->method('submit')->willThrowException(new \InvalidArgumentException('Bitte bestätigen Sie die Erklärung.'));
		$this->drafts->expects($this->never())->method('delete');
		$this->files->expects($this->never())->method('delete');
		$this->cleaner->expects($this->never())->method('purge');

		$member = new Member();
		$member->setId(7);
		$this->expectException(\InvalidArgumentException::class);
		$this->service->submit(5, $member, self::fields(['amountCents' => 100]), false, [], [], 'max');
	}
}
