<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\ReceiptIndex;

use OCA\Vereinsbuchhaltung\Db\AttachmentMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Exception\PeriodClosedException;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\AttachmentWatchFolderService;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Belegvorschläge: verbindet Wächter-Ordner, Belegindex und Abgleich.
 *
 * Alle drei Einstiege liefern außerhalb des Wächter-Modus (oder bei fehlendem
 * Ordner) leere Ergebnisse. Vorgeschlagen werden nur offene Dateien, also
 * solche, die an keiner Buchung hängen.
 *
 * @phpstan-import-type Reason from ReceiptMatcher
 * @phpstan-import-type BookingInput from ReceiptMatcher
 * @phpstan-import-type FileInput from ReceiptMatcher
 */
class ReceiptSuggestionService {
	/** Höchstens so viele Buchungen je Listenabgleich. */
	private const MAX_JOURNALS = 100000;

	public function __construct(
		private AttachmentStorageService $storage,
		private AttachmentWatchFolderService $watchFolder,
		private ReceiptIndexService $index,
		private ReceiptMatcher $matcher,
		private JournalMapper $journals,
		private JournalLineMapper $lines,
		private AttachmentMapper $attachments,
		private PeriodService $periods,
		private AuditService $audit,
	) {
	}

	/**
	 * Vorschläge für ein Formular (Buchungsdialog). Liest je Anfrage nur
	 * wenige Dateien; was noch fehlt, steht in `pending`.
	 *
	 * @param array<string, mixed> $form date, amountCents, description, documentRef
	 * @param list<int> $excludeFileIds Dateien, die schon in der Warteliste des Dialogs stehen
	 * @return array{pending:int, suggestions: list<array{fileId:int, name:string, folder:string, mtime:int, level:string, reasons:list<array<string,mixed>>}>}
	 */
	public function forForm(string $userId, array $form, array $excludeFileIds): array {
		$empty = ['pending' => 0, 'suggestions' => []];
		$prepared = $this->prepare($userId);
		if ($prepared === null) {
			return $empty;
		}
		['files' => $files, 'pending' => $pending] = $prepared;

		$exclude = array_flip($excludeFileIds);
		$open = array_filter($files, static fn (array $f): bool => !isset($exclude[$f['fileId']]));
		$booking = [
			'key' => 'form',
			// Ein leeres oder ungültiges Datum liefert der Abgleich als "kein Datumssignal".
			'date' => (string)($form['date'] ?? ''),
			'amountCents' => (int)($form['amountCents'] ?? 0),
			'description' => (string)($form['description'] ?? ''),
			'documentRef' => (string)($form['documentRef'] ?? ''),
		];
		$matches = $this->matcher->match([$booking], $this->fileInputs($open))['form'] ?? [];

		$byId = [];
		foreach ($open as $file) {
			$byId[$file['fileId']] = $file;
		}
		$suggestions = [];
		foreach ($matches as $m) {
			$file = $byId[$m['fileId']];
			$suggestions[] = [
				'fileId' => $m['fileId'],
				'name' => $file['name'],
				'folder' => $file['folder'],
				'mtime' => $file['mtime'],
				'level' => $m['level'],
				'reasons' => $m['reasons'],
			];
		}
		return ['pending' => $pending, 'suggestions' => $suggestions];
	}

	/**
	 * Stufe je Buchung ohne Beleg im Zeitraum (null: alle Zeiträume).
	 *
	 * @return array{pending:int, items: array<int, array{level:string, fileIds:list<int>, reasons: array<int, list<array<string,mixed>>>}>, names: array<int, string>}
	 */
	public function forPeriod(string $userId, ?int $periodId): array {
		$empty = ['pending' => 0, 'items' => [], 'names' => []];
		if (!$this->storage->isWatchMode()) {
			return $empty;
		}
		[$from, $to] = $this->periods->range($userId, $periodId);
		$withReceipt = $this->attachments->countByUser($userId);
		$journals = array_filter(
			$this->journals->findAll($userId, self::MAX_JOURNALS, 0, $from, $to),
			static fn ($j): bool => !isset($withReceipt[$j->getId()]),
		);
		$prepared = $this->prepare($userId);
		if ($prepared === null) {
			return $empty;
		}
		['files' => $files, 'pending' => $pending] = $prepared;
		if ($journals === []) {
			return ['pending' => $pending, 'items' => [], 'names' => []];
		}

		$lines = $this->lines->findByJournals(array_map(static fn ($j): int => $j->getId(), $journals));
		$bookings = [];
		foreach ($journals as $journal) {
			$cents = 0;
			foreach ($lines[$journal->getId()] ?? [] as $line) {
				$cents += $line->getDebitCents();
			}
			if ($cents <= 0) {
				continue;
			}
			$bookings[] = [
				'key' => (string)$journal->getId(),
				'date' => (string)$journal->getDate(),
				'amountCents' => $cents,
				'description' => (string)$journal->getDescription(),
				'documentRef' => (string)$journal->getDocumentRef(),
			];
		}

		$matches = $this->matcher->match($bookings, $this->fileInputs($files));
		$names = [];
		foreach ($files as $file) {
			$names[$file['fileId']] = $file['name'];
		}
		$items = [];
		$usedNames = [];
		foreach ($matches as $key => $suggestions) {
			if ($suggestions === []) {
				continue;
			}
			$reasons = [];
			foreach ($suggestions as $s) {
				$reasons[$s['fileId']] = $s['reasons'];
				$usedNames[$s['fileId']] = $names[$s['fileId']];
			}
			$items[(int)$key] = [
				'level' => $suggestions[0]['level'],
				'fileIds' => array_map(static fn (array $s): int => $s['fileId'], $suggestions),
				'reasons' => $reasons,
			];
		}
		return ['pending' => $pending, 'items' => $items, 'names' => $usedNames];
	}

	/**
	 * Verknüpft bestätigte Paare. Paare, die nicht mehr passen, werden
	 * übersprungen: `assigned` (Datei inzwischen zugeordnet), `notFound`
	 * (Datei oder Buchung weg), `locked` (Geschäftsjahr festgeschrieben).
	 *
	 * @param list<array{journalId:int, fileId:int}> $pairs
	 * @return array{linked: list<array{journalId:int, fileId:int}>, skipped: list<array{journalId:int, fileId:int, reason:'assigned'|'notFound'|'locked'}>}
	 */
	public function linkPairs(string $userId, array $pairs): array {
		$result = ['linked' => [], 'skipped' => []];
		if (!$this->storage->isWatchMode()) {
			return $result;
		}
		$inbox = $this->watchFolder->inbox($userId);
		/** @var array<int, list<int>> $journalIdsByFile */
		$journalIdsByFile = [];
		foreach ($inbox['files'] as $file) {
			$journalIdsByFile[$file['fileId']] = $file['journalIds'];
		}

		foreach ($pairs as $pair) {
			$journalId = $pair['journalId'];
			$fileId = $pair['fileId'];
			$skip = static function (string $reason) use (&$result, $journalId, $fileId): void {
				$result['skipped'][] = ['journalId' => $journalId, 'fileId' => $fileId, 'reason' => $reason];
			};

			try {
				$journal = $this->journals->find($journalId, $userId);
			} catch (DoesNotExistException) {
				$skip('notFound');
				continue;
			}
			try {
				$this->periods->assertOpen($userId, (string)$journal->getDate());
			} catch (PeriodClosedException) {
				$skip('locked');
				continue;
			}
			if (!isset($journalIdsByFile[$fileId])) {
				$skip('notFound');
				continue;
			}
			if ($journalIdsByFile[$fileId] !== []) {
				$skip('assigned');
				continue;
			}
			try {
				$attachment = $this->watchFolder->link($userId, $journalId, $fileId);
			} catch (\RuntimeException) {
				// link() lehnt ab: Datei nicht mehr im Ordner (oder falscher Typ) oder zwischenzeitlich verknüpft.
				try {
					$this->watchFolder->fileInFolder($fileId);
					$skip('assigned');
				} catch (\RuntimeException) {
					$skip('notFound');
				}
				continue;
			}
			$this->audit->log('Beleg verknüpft', 'attachment', $attachment->getId(), [
				'journalId' => $journalId,
				'fileName' => $attachment->getFileName(),
			]);
			$journalIdsByFile[$fileId][] = $journalId;
			$result['linked'][] = ['journalId' => $journalId, 'fileId' => $fileId];
		}
		return $result;
	}

	/**
	 * Scan, Indexlauf mit Anfragelimit, offene Dateien (`files`). Null, wenn es nichts zu tun gibt.
	 *
	 * @return array{files: list<array{fileId:int, name:string, folder:string, size:int, mtime:int, mime:string, journalIds:list<int>}>, pending:int}|null
	 */
	private function prepare(string $userId): ?array {
		if (!$this->storage->isWatchMode()) {
			return null;
		}
		$inbox = $this->watchFolder->inbox($userId);
		if ($inbox['folderMissing'] || $inbox['files'] === []) {
			return null;
		}
		// Offene Dateien zuerst: sie stehen beim begrenzten Lesen vorn an.
		$all = $inbox['files'];
		usort($all, static fn (array $a, array $b): int => ($a['journalIds'] !== []) <=> ($b['journalIds'] !== []));
		$refreshed = $this->index->refresh(
			$all,
			ReceiptIndexService::REQUEST_MAX_FILES,
			ReceiptIndexService::REQUEST_MAX_SECONDS,
			!$inbox['capped'],
		);
		$open = array_values(array_filter($inbox['files'], static fn (array $f): bool => $f['journalIds'] === []));
		// Nur offene Dateien zählen: zugeordnete, noch ungelesene würden den Hinweis „wird noch gelesen“ ewig stehen lassen.
		$pending = 0;
		if ($refreshed['pending'] > 0) {
			$entries = $this->index->entries();
			$pending = count(array_filter($open, static fn (array $f): bool => !isset($entries[$f['fileId']])));
		}
		return ['files' => $open, 'pending' => $pending];
	}

	/**
	 * Offene Dateien mit Indexeintrag in der Eingabeform des Abgleichs.
	 *
	 * @param array<array{fileId:int, mtime:int}> $files
	 * @return list<FileInput>
	 */
	private function fileInputs(array $files): array {
		$entries = $this->index->entries();
		$out = [];
		foreach ($files as $file) {
			if (isset($entries[$file['fileId']])) {
				$out[] = ['fileId' => $file['fileId'], 'mtime' => $file['mtime'], 'facts' => $entries[$file['fileId']]];
			}
		}
		return $out;
	}
}
