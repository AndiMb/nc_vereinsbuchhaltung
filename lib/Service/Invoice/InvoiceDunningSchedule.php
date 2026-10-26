<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Invoice;

/**
 * Wann die nächste Mahnstufe einer Rechnung fällig wird (Epic #168, Stufe 3).
 *
 * Stufe 1 (Zahlungserinnerung) folgt dem Zahlungsziel nach einer Anzahl Tage,
 * Stufe 2 (erste Mahnung) der Erinnerung und Stufe 3 (zweite, letzte Mahnung)
 * der ersten Mahnung. Danach mahnt die App nicht mehr: der Verein entscheidet,
 * wie es weitergeht (Inkasso, Mahnbescheid, Verzicht).
 */
final class InvoiceDunningSchedule {

	public const MAX_LEVEL = 3;

	private function __construct() {
	}

	/**
	 * @param string $dueDate Zahlungsziel (Y-m-d)
	 * @param int $lastLevel zuletzt gesendete Stufe, 0 wenn noch nie gemahnt wurde
	 * @param string|null $lastSentDate Datum dieser Mahnung (Y-m-d)
	 * @param array{1: int, 2: int, 3: int} $days Tage je Stufe
	 * @return array{level: int, dueOn: string}|null null, wenn die letzte Stufe schon erreicht ist
	 */
	public static function next(string $dueDate, int $lastLevel, ?string $lastSentDate, array $days): ?array {
		if ($lastLevel >= self::MAX_LEVEL) {
			return null;
		}
		$level = $lastLevel + 1;
		$base = $level === 1 ? $dueDate : ($lastSentDate ?? $dueDate);
		$dueOn = (new \DateTimeImmutable(substr($base, 0, 10)))->modify('+' . $days[$level] . ' days')->format('Y-m-d');
		return ['level' => $level, 'dueOn' => $dueOn];
	}
}
