<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

/**
 * Eine fertige CSV-Datei: Inhalt und Dateiname gehören zusammen.
 *
 * Der Dateiname trägt das Geschäftsjahr; wer mehrere Jahre exportiert, hat
 * sonst mehrfach "journal.csv" im Download-Ordner liegen.
 */
final class CsvFile {

	public function __construct(
		public readonly string $content,
		public readonly string $fileName,
	) {
	}
}
