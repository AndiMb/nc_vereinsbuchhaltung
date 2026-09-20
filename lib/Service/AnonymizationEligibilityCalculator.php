<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

/**
 * Reine Fristberechnung der DSGVO-Anonymisierung (Spec §3.8, Issue #78,
 * T30) – bewusst ohne jede Datenbankabhängigkeit, damit sie ohne Mock-Aufwand
 * direkt testbar ist (gleiches Prinzip wie {@see MandateExpiryCalculator}/
 * {@see ProrataCalculator}).
 *
 * **Frist (fest im Code, keine Vereinseinstellung):** anonymisierungsreif =
 * 10 Jahre nach dem Ende des Kalenderjahres der letzten zugehörigen Buchung.
 * Eine Buchung im Jahr Y macht ein Mitglied ab dem 1. Januar des Jahres
 * Y+11 anonymisierungsreif (die Aufbewahrungsfrist läuft die vollen
 * Kalenderjahre Y+1 bis einschließlich Y+10, siehe HGB §257 Abs. 4-Logik,
 * auf die die 10-Jahres-Zahl selbst zurückgeht).
 *
 * **Dominanz gegenüber der SEPA-Untergrenze (Spec §8: „≥14 Monate nach
 * Erlöschen"):** diese Klasse berechnet zwar auch {@see sepaFloorDate()},
 * verwendet sie aber NIE, um früher zu anonymisieren. Die SEPA-Untergrenze
 * ist lediglich der Beleg dafür, dass ein Mandat überhaupt schon länger als
 * die bankrechtlich gebotene Mindestfrist beendet sein darf – sie kann die
 * 10-Jahres-Grenze weder verkürzen noch verlängern. {@see isEligible()}
 * prüft ausschließlich die 10-Jahres-Grenze; die SEPA-Untergrenze bleibt in
 * dieser Klasse ein reiner Beobachtungswert für Tests/Dokumentation der
 * Dominanz (siehe tests/unit/AnonymizationEligibilityCalculatorTest.php).
 */
final class AnonymizationEligibilityCalculator {

	public const RETENTION_YEARS = 10;
	public const SEPA_MANDATE_FLOOR_MONTHS = 14;

	/**
	 * Ab wann die 10-Jahres-Frist erfüllt ist: der 1. Januar des Jahres nach
	 * Ablauf der zehn vollen Kalenderjahre seit dem Buchungsjahr.
	 *
	 * @param string $lastBookingDate ISO-Datum (JJJJ-MM-TT) der letzten
	 *                                zugehörigen Buchung
	 */
	public static function tenYearCutoffDate(string $lastBookingDate): string {
		$bookingYear = (int)substr($lastBookingDate, 0, 4);
		return sprintf('%04d-01-01', $bookingYear + self::RETENTION_YEARS + 1);
	}

	/**
	 * Rein informativ (siehe Klassendoc „Dominanz"): der früheste Zeitpunkt,
	 * ab dem die SEPA-Aufbewahrungsuntergrenze für sich genommen erfüllt
	 * wäre. Fließt NICHT in {@see isEligible()} ein.
	 */
	public static function sepaFloorDate(string $mandateEndedAt): string {
		return (new \DateTimeImmutable(substr($mandateEndedAt, 0, 10)))
			->modify('+' . self::SEPA_MANDATE_FLOOR_MONTHS . ' months')
			->format('Y-m-d');
	}

	/**
	 * Ob ein Mitglied mit dieser letzten Buchung heute anonymisierungsreif
	 * ist – einzig maßgeblich ist die 10-Jahres-Grenze (siehe Klassendoc).
	 */
	public static function isEligible(string $lastBookingDate, string $today): bool {
		return $today >= self::tenYearCutoffDate($lastBookingDate);
	}
}
