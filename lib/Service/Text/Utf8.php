<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Text;

use OCP\IL10N;

/**
 * Text aus Formularen, der gespeichert und später als JSON ausgeliefert wird.
 */
final class Utf8 {
	/**
	 * Der Text, wenn er gültiges UTF-8 ist. Gespeichert ließe sich ungültiges
	 * UTF-8 nicht mehr als JSON ausliefern, und eine Liste mit so einem Eintrag
	 * bräche für alle Einträge ab.
	 *
	 * @param ?IL10N $l10n übersetzt die Meldung; ohne bleibt der deutsche Quelltext
	 * @throws \InvalidArgumentException
	 */
	public static function wellFormed(string $text, ?IL10N $l10n): string {
		if (!mb_check_encoding($text, 'UTF-8')) {
			$message = 'Der Text enthält ungültige Zeichen.';
			throw new \InvalidArgumentException($l10n !== null ? $l10n->t($message) : $message);
		}
		return $text;
	}
}
