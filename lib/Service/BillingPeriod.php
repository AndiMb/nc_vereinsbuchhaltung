<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

/**
 * Fortschreibung der Beitragsfälligkeit. Reine Datumsarithmetik ohne Zustand –
 * ausgelagert aus dem {@see MembershipFeeService}, damit sie sich ohne
 * Datenbank und DI-Container prüfen lässt (siehe FiscalYear für dasselbe
 * Muster). Bewusst ohne Verweis auf die Entität: die Unit-Tests laufen ohne
 * Nextcloud, und Entitäten erben von OCP.
 *
 * Ein Fehler hier fällt nicht als Fehlermeldung auf, sondern als Beitrag, der
 * einen Monat zu spät oder gar nicht eingezogen wird.
 */
class BillingPeriod {

	/** Anzahl Monate je Zahlungsfrequenz – zugleich die Liste der erlaubten Werte. */
	public const FREQUENCY_MONTHS = [
		'monthly' => 1,
		'quarterly' => 3,
		'semiannual' => 6,
		'yearly' => 12,
	];

	/**
	 * Zählt Monate ab $date weiter, ohne bei kurzen Monaten überzulaufen
	 * (31. Januar + 1 Monat -> 28./29. Februar, nicht 3. März).
	 *
	 * @param string $date gültiges Datum JJJJ-MM-TT; der Aufrufer prüft das
	 *                     mit checkdate(), sonst liefe createFromFormat() still über
	 * @param string $frequency Schlüssel aus self::FREQUENCY_MONTHS
	 * @param string|null $anchor Datum, dessen Tag der Stichtag ist – in aller
	 *                            Regel das Startdatum des Beitrags. Ohne Anker rechnet die Methode
	 *                            vom übergebenen Tag weiter, und ein kurzer Monat verschiebt den
	 *                            Stichtag dauerhaft nach vorn: aus dem 31. Januar würde über den
	 *                            Februar der 28., und dabei bliebe es für alle Folgemonate. Wer
	 *                            zum Monatsletzten bucht, soll das weiterhin tun.
	 * @throws \InvalidArgumentException bei unbekannter Frequenz oder unmöglichem Datum
	 */
	public static function next(string $date, string $frequency, ?string $anchor = null): string {
		if (!isset(self::FREQUENCY_MONTHS[$frequency])) {
			throw new \InvalidArgumentException('Unbekannte Zahlungsfrequenz: ' . $frequency);
		}
		$parts = self::parse($date);
		$day = $anchor !== null ? self::parse($anchor)[2] : $parts[2];

		$months = self::FREQUENCY_MONTHS[$frequency];
		// Über den Monatsersten rechnen: "+1 month" auf dem 31. Januar landete
		// sonst im März, weil PHP den 31. Februar weiterschiebt.
		$dt = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $parts[0], $parts[1])))->modify("+{$months} month");
		return $dt->setDate((int)$dt->format('Y'), (int)$dt->format('m'), min($day, (int)$dt->format('t')))->format('Y-m-d');
	}

	/**
	 * Wie viele Perioden bis einschließlich $today fällig sind – also wie viele
	 * offene Posten noch fehlen, wenn `next_due_date` in der Vergangenheit
	 * liegt.
	 *
	 * Der Tageslauf erzeugt bewusst nur einen Posten je Beitrag und Lauf, damit
	 * ein rückwirkend angelegter Beitrag nicht auf einen Schlag zwei Jahrgänge
	 * Forderungen erzeugt. Die Kehrseite: der Rückstand arbeitet sich nur
	 * langsam ab, und ohne diese Zahl sähe das niemand.
	 *
	 * @param string|null $anchor Stichtag wie bei {@see next()} – in aller Regel
	 *                            das Startdatum des Beitrags, damit ein kurzer Monat die Zählung
	 *                            nicht verschiebt
	 * @param int $limit Sicherheitsnetz gegen Endlosschleifen bei kaputten Daten
	 * @throws \InvalidArgumentException bei unbekannter Frequenz oder unmöglichem Datum
	 */
	public static function dueCount(string $nextDueDate, string $frequency, string $today, ?string $anchor = null, int $limit = 240): int {
		self::parse($today);
		$count = 0;
		$cursor = $nextDueDate;
		while ($cursor <= $today && $count < $limit) {
			$count++;
			$cursor = self::next($cursor, $frequency, $anchor);
		}
		return $count;
	}

	/**
	 * @return array{0:int, 1:int, 2:int} Jahr, Monat, Tag
	 * @throws \InvalidArgumentException wenn es den Tag nicht gibt
	 */
	private static function parse(string $date): array {
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			throw new \InvalidArgumentException('Kein gültiges Datum: ' . $date);
		}
		return [(int)$m[1], (int)$m[2], (int)$m[3]];
	}
}
