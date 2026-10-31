<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\ReceiptIndex;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\ReceiptIndexMapper;
use OCA\Vereinsbuchhaltung\Service\AttachmentWatchFolderService;
use OCP\DB\Exception as DbException;
use Psr\Log\LoggerInterface;

/**
 * Hält den Belegindex (Tabelle vbh_receipt_index) aktuell: liest neue und
 * geänderte Dateien des Wächter-Ordners mit dem {@see ReceiptReader}, in
 * Häppchen mit Datei- und Zeitlimit, und räumt verschwundene Dateien ab.
 */
class ReceiptIndexService {
	public const REQUEST_MAX_FILES = 20;
	public const REQUEST_MAX_SECONDS = 2.0;
	public const JOB_MAX_FILES = 300;
	public const JOB_MAX_SECONDS = 60.0;

	/** @var \Closure(): float */
	private \Closure $clock;

	/** @param ?\Closure(): float $clock Sekunden, für Tests; sonst microtime(true) */
	public function __construct(
		private ReceiptIndexMapper $mapper,
		private ReceiptReader $reader,
		private AttachmentWatchFolderService $watchFolder,
		private ?LoggerInterface $logger = null,
		?\Closure $clock = null,
	) {
		$this->clock = $clock ?? static fn (): float => microtime(true);
	}

	/**
	 * @param list<array{fileId:int, name:string, size:int, mtime:int, mime:string}> $scanFiles
	 * @param bool $complete false, wenn der Scan gekappt war: dann fehlende Dateien nicht als verschwunden behandeln
	 * @return array{indexed:int, pending:int} gelesene Dateien dieses Laufs, noch ungelesene
	 */
	public function refresh(array $scanFiles, int $maxFiles, float $maxSeconds, bool $complete = true): array {
		$start = ($this->clock)();
		$known = $this->mapper->findAll();

		$present = [];
		foreach ($scanFiles as $file) {
			$present[$file['fileId']] = true;
		}
		$gone = $complete ? array_values(array_diff(array_keys($known), array_keys($present))) : [];
		if ($gone !== []) {
			$this->mapper->deleteByFileIds($gone);
		}

		$indexed = 0;
		$pending = 0;
		foreach ($scanFiles as $file) {
			$id = $file['fileId'];
			$row = $known[$id] ?? null;
			if ($row !== null
				&& $row['size'] === $file['size']
				&& $row['mtime'] === $file['mtime']
				&& $row['version'] === ReceiptReader::VERSION) {
				continue;
			}
			if ($indexed >= $maxFiles || ($this->clock)() - $start >= $maxSeconds) {
				$pending++;
				continue;
			}
			try {
				// Zuerst ein Platzhalter: ein Absturz beim Lesen (Speicher, Zeitlimit) lässt sich nicht
				// abfangen und würde sonst in jeder Anfrage dieselbe Datei erneut zu Fall bringen.
				$this->mapper->save($id, $file['size'], $file['mtime'], ReceiptReader::VERSION, ReceiptReader::nameOnly($file['name']));
				$this->mapper->save($id, $file['size'], $file['mtime'], ReceiptReader::VERSION, $this->readFile($file));
			} catch (DbException $e) {
				// Ein überlappender Lauf war schneller: die Zeile steht schon.
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
			}
			$indexed++;
		}

		return ['indexed' => $indexed, 'pending' => $pending];
	}

	/** @return array<int, ReceiptFacts> nach Datei-ID */
	public function entries(): array {
		return array_map(static fn (array $row): ReceiptFacts => $row['facts'], $this->mapper->findAll());
	}

	/** @param array{fileId:int, name:string, size:int, mtime:int, mime:string} $file */
	private function readFile(array $file): ReceiptFacts {
		try {
			return $this->reader->read(
				$file['name'],
				$file['mime'],
				$file['size'],
				fn (): string => $this->watchFolder->fileInFolder($file['fileId'])->getContent(),
			);
		} catch (\Throwable $e) {
			// Datei zwischen Scan und Lesen verschwunden o. Ä.: als unlesbar
			// vermerken, damit sie nicht in jedem Lauf erneut versucht wird.
			$this->logger?->debug('Beleg {file} nicht indexierbar: {message}', [
				'app' => Application::APP_ID,
				'file' => $file['name'],
				'message' => $e->getMessage(),
				'exception' => $e,
			]);
			return new ReceiptFacts(ReceiptFacts::SOURCE_NAME, ReceiptFacts::STATUS_UNREADABLE);
		}
	}
}
