<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

use OCA\Vereinsbuchhaltung\Db\AttachmentMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\FiscalYear;
use OCP\ITempManager;

/**
 * Alle Belege eines Geschäftsjahres als ZIP – für die Kassenprüfung.
 *
 * Ordner je Buchung: "NNNN_Datum_Beschreibung/<BelegID>_<Dateiname>". Eine
 * nicht auffindbare Datei bricht den Export nicht ab, sondern landet in
 * fehlende_dateien.txt: ein fehlender Beleg ist ein Befund für die Prüfung,
 * kein Grund, die anderen dreihundert nicht auszuliefern.
 */
class AttachmentArchive {

	public function __construct(
		private JournalMapper $journalMapper,
		private AttachmentMapper $attachmentMapper,
		private AttachmentStorageService $storageService,
		private ITempManager $tempManager,
	) {
	}

	/**
	 * Baut das Archiv und gibt den Pfad der fertigen Datei zurück.
	 * Der Temp-Ordner wird von Nextcloud am Ende der Anfrage aufgeräumt.
	 *
	 * @throws \RuntimeException wenn sich das Archiv nicht anlegen lässt
	 */
	public function build(string $userId, ?int $year = null): string {
		[$from, $to] = FiscalYear::range($year);

		$zipPath = $this->tempManager->getTemporaryFile('.zip');
		$zip = new \ZipArchive();
		if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			throw new \RuntimeException('ZIP-Datei konnte nicht erstellt werden.');
		}

		$count = 0;
		$problems = [];
		$journals = $this->journalMapper->findAll($userId, 100000, 0, $from, $to);
		// Belegliste gebündelt laden statt je Buchung.
		$attsByJournal = $this->attachmentMapper->findByJournals(array_map(
			static fn ($j): int => $j->getId(),
			$journals,
		));

		foreach ($journals as $journal) {
			$atts = $attsByJournal[$journal->getId()] ?? [];
			if ($atts === []) {
				continue;
			}
			$folder = self::safeName(sprintf(
				'%04d_%s_%s',
				(int)($journal->getEntryNo() ?? 0),
				(string)$journal->getDate(),
				(string)$journal->getDescription(),
			), 80);
			foreach ($atts as $att) {
				$entryName = $folder . '/' . $att->getId() . '_' . self::safeName($att->getFileName(), 100);
				try {
					$localPath = $this->spoolToTempFile($att->getId(), $att->getJournalId(), $att->getFileName());
				} catch (\Throwable) {
					$problems[] = sprintf(
						'Buchung #%s (%s): Datei "%s" (Beleg %d) nicht gefunden.',
						(string)($journal->getEntryNo() ?? '?'),
						(string)$journal->getDate(),
						$att->getFileName(),
						$att->getId(),
					);
					continue;
				}
				$zip->addFile($localPath, $entryName);
				$count++;
			}
		}

		if ($count === 0) {
			$zip->addFromString('hinweis.txt', "Keine Belege im gewählten Zeitraum gefunden.\n");
		}
		if ($problems !== []) {
			$zip->addFromString('fehlende_dateien.txt', implode("\n", $problems) . "\n");
		}
		$zip->close();

		return $zipPath;
	}

	public static function fileName(?int $year): string {
		return 'belege_' . (FiscalYear::isSelected($year) ? (string)$year : 'alle_jahre') . '.zip';
	}

	/**
	 * Schreibt einen Beleg in eine lokale Temp-Datei und gibt deren Pfad zurück.
	 *
	 * Der Umweg über die Platte ist Absicht. Der Beleg liegt je nach Einstellung
	 * im Nextcloud-Dateibaum eines Nutzers und ist damit nicht zwingend eine
	 * lokale Datei, die ZipArchive::addFile() öffnen könnte. Ihn stattdessen mit
	 * getContent() in eine Variable zu holen, sprengte bei einem Jahr voller
	 * PDFs (bis 20 MB je Beleg) das memory_limit – ausgerechnet in der Funktion,
	 * die die Kassenprüfung braucht. stream_copy_to_stream() kopiert blockweise
	 * und hält nie mehr als einen Puffer im Speicher.
	 *
	 * Die Temp-Dateien müssen bis zum ZipArchive::close() bestehen bleiben –
	 * erst dort liest ZipArchive die per addFile() angemeldeten Dateien.
	 *
	 * @throws \RuntimeException wenn der Beleg nicht lesbar ist
	 */
	private function spoolToTempFile(int $id, int $journalId, string $fileName): string {
		$source = $this->storageService->getFileStream($id, $journalId, $fileName);
		try {
			$target = $this->tempManager->getTemporaryFile('.beleg');
			$sink = fopen($target, 'wb');
			if ($sink === false) {
				throw new \RuntimeException('Zwischendatei für den Beleg konnte nicht angelegt werden.');
			}
			try {
				if (stream_copy_to_stream($source, $sink) === false) {
					throw new \RuntimeException('Beleg konnte nicht gelesen werden.');
				}
			} finally {
				fclose($sink);
			}
		} finally {
			if (is_resource($source)) {
				fclose($source);
			}
		}
		return $target;
	}

	/**
	 * Dateisystem-tauglicher Name für ZIP-Einträge (Umlaute bleiben erhalten).
	 *
	 * Pfadtrenner und Steuerzeichen werden ersetzt: eine Buchungsbeschreibung
	 * ist freier Text und könnte sonst aus dem vorgesehenen Ordner ausbrechen.
	 */
	public static function safeName(string $s, int $maxLen = 48): string {
		$s = preg_replace('/[\\\\\/:*?"<>|[:cntrl:]]/u', '_', $s) ?? '_';
		$s = trim(preg_replace('/\s+/u', ' ', $s) ?? '', ' ._');
		if ($s === '') {
			$s = '_';
		}
		return mb_substr($s, 0, $maxLen);
	}
}
