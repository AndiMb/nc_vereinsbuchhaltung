<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

/**
 * Liest das Übersetzungsbündel einer Sprache aus dem l10n-Verzeichnis.
 *
 * Eigener Service statt einer Handvoll Zeilen im Controller, damit die
 * Prüfung des Sprachcodes testbar bleibt: sie entscheidet darüber, welcher
 * Dateiname aus einem Parameter der Anfrage entsteht.
 *
 * Warum der Umweg über PHP überhaupt nötig ist, steht im
 * {@see \OCA\Vereinsbuchhaltung\Controller\L10nController}.
 */
class TranslationBundle {

	public const EMPTY_BUNDLE = '{"translations":{}}';

	public function __construct(
		private string $directory,
	) {
	}

	/**
	 * Sprachcodes wie "de", "en", "pt_BR" – und sonst nichts. Streng geprüft
	 * statt bereinigt: aus dem Wert wird ein Dateiname, ein durchgereichtes
	 * "../" läse sonst beliebige Dateien aus dem App-Verzeichnis.
	 */
	public function isValidLanguage(string $lang): bool {
		return preg_match('/^[a-z]{2,3}(_[A-Za-z]{2,4})?$/', $lang) === 1;
	}

	/**
	 * Der rohe Dateiinhalt, unverändert durchgereicht: das Bündel ist bereits
	 * fertiges JSON, ein Umweg über json_decode/json_encode kostet bei über
	 * hundert Kilobyte nur Zeit.
	 *
	 * Für Sprachen ohne Übersetzungsdatei gibt es ein leeres Bündel statt eines
	 * Fehlers – die Oberfläche bleibt dann bei den deutschen Quelltexten, und
	 * genau so ist es gemeint.
	 */
	public function read(string $lang): string {
		if (!$this->isValidLanguage($lang)) {
			return self::EMPTY_BUNDLE;
		}
		$path = $this->directory . '/' . $lang . '.json';
		if (!is_file($path)) {
			return self::EMPTY_BUNDLE;
		}
		$content = file_get_contents($path);
		return $content === false || trim($content) === '' ? self::EMPTY_BUNDLE : $content;
	}
}
