<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\ReceiptIndex;

/**
 * Merkmale aus Text für die Belegvorschläge: Referenz-Kerne (nur Ziffern einer
 * Rechnungs- oder Belegnummer), Beträge in Cent und Daten.
 *
 * Reine statische Funktionen ohne Zustand, damit Buchungstext, Dateiname und
 * PDF-Text mit derselben Logik gelesen werden.
 */
final class ReceiptFeatures {
	public const MAX_REFS = 300;
	public const MAX_AMOUNTS = 100;
	public const MAX_DATES = 20;

	private const MIN_CORE_DIGITS = 4;
	private const MAX_AMOUNT_CENTS = 999999999;

	// Auch gedruckte IBANs (Viererblöcke mit Leerzeichen) und Kleinschreibung; mindestens 11 Zeichen nach der Prüfziffer.
	private const IBAN = '/(?<![A-Z0-9])[A-Z]{2}\d{2}(?=(?: ?[A-Z0-9]){11})(?: ?[A-Z0-9]{4}){2,7}(?: ?[A-Z0-9]{1,3})?(?![A-Z0-9])/i';
	private const AMOUNT = '/(?<![\d.,])(?:\d{1,3}(?:\.\d{3})+,\d{2}|\d{1,3}(?:,\d{3})+\.\d{2}|\d+,\d{2}|\d+\.\d{2})(?!\d|[.,]\d)/';
	private const DATE = '/(?<![\d.])(?:\d{1,2}\.\d{1,2}\.\d{4}|\d{4}-\d{2}-\d{2}|\d{1,2}\.\d{1,2}\.\d{2})(?!\d)/';
	private const TIME = '/(?<![\d.:])\d{1,2}[.:]\d{2}(?:[.:]\d{2})?(?!\d|[.,]\d)/';

	/**
	 * @return list<string> Kerne, eindeutig, in Reihenfolge des ersten Auftretens
	 */
	public static function refs(string $text): array {
		return array_map('strval', array_keys(self::extractRefs($text)));
	}

	/**
	 * @return array<string,string> Kern => Originalausschnitt
	 */
	public static function refsWithSource(string $text): array {
		$result = [];
		foreach (self::extractRefs($text) as $core => $source) {
			$result[(string)$core] = $source;
		}
		return $result;
	}

	/**
	 * @return list<int> Beträge in Cent, eindeutig, in Reihenfolge des Auftretens
	 */
	public static function amounts(string $text): array {
		$found = [];
		if (preg_match_all(self::AMOUNT, $text, $matches) === false) {
			return [];
		}
		foreach ($matches[0] as $raw) {
			$cents = (int)preg_replace('/\D/', '', $raw);
			if ($cents <= 0 || $cents > self::MAX_AMOUNT_CENTS) {
				continue;
			}
			$found[$cents] = true;
			if (count($found) >= self::MAX_AMOUNTS) {
				break;
			}
		}
		return array_keys($found);
	}

	/**
	 * @return list<string> ISO-Daten, eindeutig, in Reihenfolge des Auftretens
	 */
	public static function dates(string $text, \DateTimeImmutable $today): array {
		$from = $today->modify('-10 years')->format('Y-m-d');
		$to = $today->modify('+1 year')->format('Y-m-d');
		$found = [];
		if (preg_match_all(self::DATE, $text, $matches) === false) {
			return [];
		}
		foreach ($matches[0] as $raw) {
			if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) === 1) {
				[$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
			} elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2,4})$/', $raw, $m) === 1) {
				[$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
				if ($y < 100) {
					$y += 2000;
				}
			} else {
				continue;
			}
			if (!checkdate($mo, $d, $y)) {
				continue;
			}
			$iso = sprintf('%04d-%02d-%02d', $y, $mo, $d);
			if ($iso < $from || $iso > $to) {
				continue;
			}
			$found[$iso] = true;
			if (count($found) >= self::MAX_DATES) {
				break;
			}
		}
		return array_map('strval', array_keys($found));
	}

	/**
	 * @return array<int|string,string> Kern => Originalausschnitt (PHP macht aus Ziffernschlüsseln ints)
	 */
	private static function extractRefs(string $text): array {
		// IBANs, Daten, Beträge und Uhrzeiten durch gleich lange Leerräume ersetzen:
		// so bleiben die Offsets gültig und der Originalausschnitt lässt sich schneiden.
		$cleaned = $text;
		foreach ([self::IBAN, self::DATE, self::AMOUNT, self::TIME] as $pattern) {
			$cleaned = preg_replace_callback(
				$pattern,
				static fn (array $m): string => str_repeat(' ', strlen($m[0])),
				$cleaned,
			) ?? $cleaned;
		}

		$refs = [];
		if (preg_match_all('/\d[\d\/._-]*\d|\d+/', $cleaned, $matches, PREG_OFFSET_CAPTURE) === false) {
			return [];
		}
		foreach ($matches[0] as [$block, $offset]) {
			$source = substr($text, $offset, strlen($block));
			$core = preg_replace('/\D/', '', $block) ?? '';
			if (self::isValidCore($core)) {
				$refs[$core] ??= $source;
			} elseif (preg_match_all('/\d+/', $block, $runs) !== false && count($runs[0]) > 1) {
				// Block verworfen (z. B. Jahr-Kombination): Einzelfolgen bleiben Kandidaten.
				foreach ($runs[0] as $run) {
					if (self::isValidCore($run)) {
						$refs[$run] ??= $run;
					}
				}
			}
			if (count($refs) >= self::MAX_REFS) {
				return array_slice($refs, 0, self::MAX_REFS, true);
			}
		}
		return $refs;
	}

	private static function isValidCore(string $core): bool {
		return strlen($core) >= self::MIN_CORE_DIGITS
			&& preg_match('/^(199\d|20\d\d)$/', $core) !== 1;
	}
}
