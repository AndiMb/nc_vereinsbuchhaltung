<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Attachment;
use OCA\Vereinsbuchhaltung\Db\AttachmentMapper;
use OCA\Vereinsbuchhaltung\Db\Journal;
use OCA\Vereinsbuchhaltung\Db\JournalLine;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Exception\PeriodClosedException;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\AttachmentWatchFolderService;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Service\ReceiptIndex\ReceiptFacts;
use OCA\Vereinsbuchhaltung\Service\ReceiptIndex\ReceiptIndexService;
use OCA\Vereinsbuchhaltung\Service\ReceiptIndex\ReceiptMatcher;
use OCA\Vereinsbuchhaltung\Service\ReceiptIndex\ReceiptSuggestionService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Vorschlagsdienst: Wächter-Modus, Anfragelimit, offene Dateien, Buchungen
 * ohne Beleg und die Sammelbestätigung mit ihren Übersprungsgründen.
 */
class ReceiptSuggestionServiceTest extends TestCase {
	private AttachmentStorageService&MockObject $storage;
	private AttachmentWatchFolderService&MockObject $watchFolder;
	private ReceiptIndexService&MockObject $index;
	private JournalMapper&MockObject $journals;
	private JournalLineMapper&MockObject $lines;
	private AttachmentMapper&MockObject $attachments;
	private PeriodService&MockObject $periods;
	private AuditService&MockObject $audit;

	protected function setUp(): void {
		$this->storage = $this->createMock(AttachmentStorageService::class);
		$this->watchFolder = $this->createMock(AttachmentWatchFolderService::class);
		$this->index = $this->createMock(ReceiptIndexService::class);
		$this->journals = $this->createMock(JournalMapper::class);
		$this->lines = $this->createMock(JournalLineMapper::class);
		$this->attachments = $this->createMock(AttachmentMapper::class);
		$this->periods = $this->createMock(PeriodService::class);
		$this->audit = $this->createMock(AuditService::class);
		$this->storage->method('isWatchMode')->willReturn(true);
	}

	private function service(): ReceiptSuggestionService {
		return new ReceiptSuggestionService(
			$this->storage,
			$this->watchFolder,
			$this->index,
			new ReceiptMatcher(),
			$this->journals,
			$this->lines,
			$this->attachments,
			$this->periods,
			$this->audit,
		);
	}

	/** @param list<int> $journalIds */
	private static function file(int $id, array $journalIds = [], int $mtime = 100): array {
		return ['fileId' => $id, 'name' => "beleg-$id.pdf", 'folder' => 'Eingang', 'size' => 10, 'mtime' => $mtime, 'mime' => 'application/pdf', 'journalIds' => $journalIds];
	}

	/** @param list<array<string,mixed>> $files */
	private function inbox(array $files, bool $capped = false): void {
		$this->watchFolder->method('inbox')->willReturn([
			'folderMissing' => false, 'capped' => $capped, 'limit' => 5000, 'files' => $files, 'missing' => [],
		]);
	}

	/** @param list<int> $amounts */
	private static function facts(array $refs = [], array $amounts = [], array $dates = []): ReceiptFacts {
		return new ReceiptFacts('db', ReceiptFacts::STATUS_OK, $refs, [], $amounts, $dates);
	}

	private static function journal(int $id, string $date, string $description, string $ref = ''): Journal {
		$j = new Journal();
		$j->setId($id);
		$j->setDate($date);
		$j->setDescription($description);
		$j->setDocumentRef($ref);
		return $j;
	}

	private static function line(int $journalId, int $debit, int $credit = 0): JournalLine {
		$l = new JournalLine();
		$l->setJournalId($journalId);
		$l->setDebitCents($debit);
		$l->setCreditCents($credit);
		return $l;
	}

	public function testAusserhalbDesWaechterModusLeer(): void {
		$storage = $this->createMock(AttachmentStorageService::class);
		$storage->method('isWatchMode')->willReturn(false);
		$this->watchFolder->expects($this->never())->method('inbox');
		$this->index->expects($this->never())->method('refresh');
		$this->journals->expects($this->never())->method('find');
		$service = new ReceiptSuggestionService(
			$storage, $this->watchFolder, $this->index, new ReceiptMatcher(), $this->journals,
			$this->lines, $this->attachments, $this->periods, $this->audit,
		);

		$this->assertSame(['pending' => 0, 'suggestions' => []], $service->forForm('u', ['date' => '2026-10-20', 'amountCents' => 100], []));
		$this->assertSame(['pending' => 0, 'items' => [], 'names' => []], $service->forPeriod('u', 1));
		$this->assertSame(['linked' => [], 'skipped' => []], $service->linkPairs('u', [['journalId' => 1, 'fileId' => 2]]));
	}

	public function testForFormLiestHoechstensZwanzigDateienJeAnfrage(): void {
		$this->inbox([self::file(1)], true);
		$this->index->expects($this->once())->method('refresh')
			->with($this->anything(), ReceiptIndexService::REQUEST_MAX_FILES, ReceiptIndexService::REQUEST_MAX_SECONDS, false)
			->willReturn(['indexed' => 1, 'pending' => 7]);
		$this->index->method('entries')->willReturn([]);

		$this->assertSame(['pending' => 1, 'suggestions' => []], $this->service()->forForm('u', ['date' => '2026-10-20', 'amountCents' => 100], []));
	}

	public function testForFormSchliesstZugeordneteUndWartelisteAus(): void {
		$this->inbox([self::file(1), self::file(2, [55]), self::file(3), self::file(4, [], 300)]);
		$this->index->method('refresh')->willReturn(['indexed' => 0, 'pending' => 0]);
		$facts = self::facts(['2826'], [11900], ['2026-10-15']);
		$this->index->method('entries')->willReturn([1 => $facts, 2 => $facts, 3 => $facts, 4 => $facts]);

		$result = $this->service()->forForm('u', [
			'date' => '2026-10-20', 'amountCents' => -11900, 'description' => 'Rechnung 28/26', 'documentRef' => '',
		], [3]);

		// Gleiche Stufe: neuere Datei zuerst; 2 (zugeordnet) und 3 (Warteliste) fehlen.
		$this->assertSame([4, 1], array_column($result['suggestions'], 'fileId'));
		$byId = [];
		foreach ($result['suggestions'] as $s) {
			$byId[$s['fileId']] = $s;
		}
		$this->assertArrayNotHasKey(2, $byId);
		$this->assertArrayNotHasKey(3, $byId);
		$this->assertSame('beleg-4.pdf', $byId[4]['name']);
		$this->assertSame('Eingang', $byId[4]['folder']);
		$this->assertSame(300, $byId[4]['mtime']);
		$this->assertSame('possible', $byId[4]['level']);
		$this->assertNotEmpty($byId[4]['reasons']);
	}

	public function testForFormMitLeeremDatumLiefertKeinDatumssignal(): void {
		$this->inbox([self::file(1)]);
		$this->index->method('refresh')->willReturn(['indexed' => 0, 'pending' => 0]);
		$this->index->method('entries')->willReturn([1 => self::facts([], [11900], [(new \DateTimeImmutable('today'))->format('Y-m-d')])]);

		$result = $this->service()->forForm('u', ['date' => '', 'amountCents' => 11900, 'description' => '', 'documentRef' => ''], []);

		$this->assertSame([], $result['suggestions']);
	}

	public function testForPeriodNurBuchungenOhneBeleg(): void {
		$this->periods->method('range')->with('u', 3)->willReturn(['2026-01-01', '2026-12-31']);
		$this->journals->method('findAll')->willReturn([
			self::journal(10, '2026-10-20', 'Rechnung 28/26'),
			self::journal(11, '2026-10-20', 'Rechnung 28/26'),
		]);
		$this->attachments->method('countByUser')->willReturn([11 => ['count' => 1, 'firstId' => 5]]);
		$this->lines->method('findByJournals')->with([10])->willReturn([
			10 => [self::line(10, 7000), self::line(10, 4900), self::line(10, 0, 11900)],
		]);
		$this->inbox([self::file(1), self::file(2, [99])]);
		$this->index->method('refresh')->willReturn(['indexed' => 0, 'pending' => 4]);
		$facts = self::facts(['2826'], [11900], ['2026-10-15']);
		$this->index->method('entries')->willReturn([1 => $facts, 2 => $facts]);

		$result = $this->service()->forPeriod('u', 3);

		$this->assertSame(0, $result['pending']); // offene Datei 1 ist gelesen, Datei 2 (zugeordnet) zählt nicht
		$this->assertSame([10], array_keys($result['items']));
		$this->assertSame('sure', $result['items'][10]['level']);
		$this->assertSame([1], $result['items'][10]['fileIds']);
		$this->assertSame([1], array_keys($result['items'][10]['reasons']));
		$this->assertContains('amount', array_column($result['items'][10]['reasons'][1], 'kind'));
		$this->assertSame([1 => 'beleg-1.pdf'], $result['names']);
	}

	public function testLinkSuggestedUeberspringtZugeordneteUndFehlendeDateien(): void {
		$this->journals->method('find')->willReturn(self::journal(10, '2026-10-20', 'x'));
		$this->inbox([self::file(1), self::file(2, [77])]);
		$attachment = new Attachment();
		$attachment->setId(500);
		$attachment->setFileName('beleg-1.pdf');
		$this->watchFolder->method('link')->willReturnCallback(
			static function (string $u, int $journalId, int $fileId) use ($attachment): Attachment {
				if ($fileId === 1) {
					return $attachment;
				}
				throw new \RuntimeException('nope');
			},
		);
		$this->audit->expects($this->once())->method('log')
			->with('Beleg verknüpft', 'attachment', 500, ['journalId' => 10, 'fileName' => 'beleg-1.pdf']);

		$result = $this->service()->linkPairs('u', [
			['journalId' => 10, 'fileId' => 1],
			['journalId' => 10, 'fileId' => 2],
			['journalId' => 10, 'fileId' => 3],
		]);

		$this->assertSame([['journalId' => 10, 'fileId' => 1]], $result['linked']);
		$this->assertSame([
			['journalId' => 10, 'fileId' => 2, 'reason' => 'assigned'],
			['journalId' => 10, 'fileId' => 3, 'reason' => 'notFound'],
		], $result['skipped']);
	}

	public function testLinkSuggestedUeberspringtUnbekannteBuchungen(): void {
		$this->journals->method('find')->willThrowException(new DoesNotExistException(''));
		$this->inbox([self::file(1)]);
		$this->watchFolder->expects($this->never())->method('link');

		$result = $this->service()->linkPairs('u', [['journalId' => 10, 'fileId' => 1]]);

		$this->assertSame([['journalId' => 10, 'fileId' => 1, 'reason' => 'notFound']], $result['skipped']);
	}

	public function testLinkSuggestedMeldetVerschwundeneDateiBeiLinkFehlerAlsNotFound(): void {
		$this->journals->method('find')->willReturn(self::journal(10, '2026-10-20', 'x'));
		$this->inbox([self::file(1), self::file(2)]);
		$this->watchFolder->method('link')->willThrowException(new \RuntimeException('weg'));
		// Datei 1 ist inzwischen aus dem Ordner verschwunden, Datei 2 liegt noch da.
		$this->watchFolder->method('fileInFolder')->willReturnCallback(function (int $id) {
			if ($id === 1) {
				throw new \RuntimeException('nicht im Ordner');
			}
			return $this->createMock(\OCP\Files\File::class);
		});

		$result = $this->service()->linkPairs('u', [['journalId' => 10, 'fileId' => 1], ['journalId' => 10, 'fileId' => 2]]);

		$this->assertSame([
			['journalId' => 10, 'fileId' => 1, 'reason' => 'notFound'],
			['journalId' => 10, 'fileId' => 2, 'reason' => 'assigned'],
		], $result['skipped']);
	}

	public function testPendingZaehltNurOffeneUngeleseneDateien(): void {
		// Datei 2 ist zugeordnet und ungelesen, Datei 3 offen und ungelesen, Datei 1 offen und gelesen.
		$this->inbox([self::file(1), self::file(2, [9]), self::file(3)]);
		$this->index->method('refresh')->willReturn(['indexed' => 0, 'pending' => 2]);
		$this->index->method('entries')->willReturn([1 => self::facts()]);

		$this->assertSame(1, $this->service()->forForm('u', ['date' => '2026-10-20', 'amountCents' => 100], [])['pending']);
	}

	public function testLinkSuggestedUeberspringtGesperrteBuchungen(): void {
		$this->journals->method('find')->willReturn(self::journal(10, '2025-10-20', 'x'));
		$this->inbox([self::file(1)]);
		$this->periods->method('assertOpen')->willThrowException(new PeriodClosedException('zu'));
		$this->watchFolder->expects($this->never())->method('link');

		$result = $this->service()->linkPairs('u', [['journalId' => 10, 'fileId' => 1]]);

		$this->assertSame([], $result['linked']);
		$this->assertSame([['journalId' => 10, 'fileId' => 1, 'reason' => 'locked']], $result['skipped']);
	}
}
