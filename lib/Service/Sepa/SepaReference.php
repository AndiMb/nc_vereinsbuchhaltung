<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Sepa;

/**
 * Erzeugt und erkennt die Referenzen, die diese App selbst vergibt:
 * Mandatsreferenz, Message-ID eines Sammeleinzugs und End-to-End-ID einer
 * einzelnen Lastschrift.
 *
 * Erzeugen und Wiedererkennen stehen bewusst in derselben Klasse. Vorher lagen
 * die Muster in {@see \OCA\Vereinsbuchhaltung\Service\SepaReturnDetectionService}
 * als Regex, während zwei andere Klassen die Referenzen zusammenbauten. Ändert
 * jemand das Format – etwa weil eine Bank ein anderes Präfix verlangt – bricht
 * die Rücklastschrift-Erkennung dabei *still*: kein Fehler, nur ab sofort keine
 * Treffer mehr. Hier hält der Test in SepaReferenceTest beide Enden zusammen.
 *
 * Alle Formate bleiben innerhalb der 35 Zeichen aus dem pain.008-Schema und
 * benutzen nur Zeichen, die SEPA in Referenzfeldern erlaubt.
 */
class SepaReference {

	/** M + Datum + 6 Hexstellen, z. B. M20260812-2DE3C1 (16 Zeichen). */
	public const MANDATE_PATTERN = '/\bM\d{8}-[0-9A-F]{6}\b/';
	/** E2E- + Zeitstempel + 8 Hexstellen, z. B. E2E-20260812-214614-EFAB2B2F (28 Zeichen). */
	public const END_TO_END_PATTERN = '/\bE2E-\d{8}-\d{6}-[0-9A-F]{8}\b/';

	/**
	 * Mandatsreferenz. Sie steht später auf dem Kontoauszug des Mitglieds und
	 * ist bewusst kurz gehalten – Kollisionen sind bei sechs Zufalls-Hexstellen
	 * pro Tag praktisch ausgeschlossen, die Eindeutigkeit sichert zusätzlich
	 * der Unique-Index auf der Spalte.
	 */
	public static function mandate(): string {
		return 'M' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
	}

	/** Message-ID der Einreichungsdatei (GrpHdr/MsgId). */
	public static function message(): string {
		return self::withTimestamp('MSG');
	}

	/** End-to-End-ID einer einzelnen Lastschrift; die Bank spiegelt sie bei einer Rückbuchung oft zurück. */
	public static function endToEnd(): string {
		return self::withTimestamp('E2E');
	}

	private static function withTimestamp(string $prefix): string {
		return $prefix . '-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(4)));
	}

	/** Erste Mandatsreferenz in einem Freitext (Verwendungszweck), oder null. */
	public static function findMandate(string $haystack): ?string {
		return preg_match(self::MANDATE_PATTERN, $haystack, $m) === 1 ? $m[0] : null;
	}

	/** Erste End-to-End-ID in einem Freitext, oder null. */
	public static function findEndToEnd(string $haystack): ?string {
		return preg_match(self::END_TO_END_PATTERN, $haystack, $m) === 1 ? $m[0] : null;
	}
}
