<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit\Support;

/**
 * Legt ein im Test erzeugtes PDF zum Ansehen ab, wenn die Umgebungsvariable
 * VBH_KEEP_PDF auf einen vorhandenen Ordner zeigt. Den Text eines PDFs kann der
 * Test nicht lesen (Glyphennummern); das Layout prüft ein Blick auf die Datei.
 */
final class KeepPdf {
	public static function to(string $name, string $bytes): void {
		$dir = getenv('VBH_KEEP_PDF');
		if (is_string($dir) && $dir !== '' && is_dir($dir)) {
			file_put_contents($dir . '/' . $name, $bytes);
		}
	}
}
