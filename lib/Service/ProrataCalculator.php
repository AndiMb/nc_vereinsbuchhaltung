<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

/**
 * Prorata-Mathematik für Beitragsgruppen (Spec §3.3 „Beitragsgruppen &
 * Zuweisungen"): reine Datumsarithmetik ohne Zustand, ohne Datenbank, ohne
 * Nextcloud – dasselbe Muster wie {@see BillingPeriod} und {@see PeriodRule}.
 *
 * Leitsatz aus der Spec: „Der Monatsbeitrag ist das Atom – alle Beträge sind
 * Monatsbeträge, jeder Einzugsbetrag entsteht per Multiplikation
 * (Monatsbeitrag × Turnusmonate); nirgends wird geteilt oder gerundet."
 * Prorata ist monatsgranular: angebrochene Monate zählen an **beiden** Enden
 * voll. Diese Klasse zählt deshalb nur ganze Kalendermonate zwischen zwei
 * Daten (inklusive beider Enden) und multipliziert – nie Tage, nie Division.
 */
final class ProrataCalculator {

	/**
	 * Anzahl der Kalendermonate von $from bis $to, beide Enden inklusive und
	 * jeweils voll gezählt – unabhängig vom Tag im Monat. Der 15. Januar bis
	 * zum 31. Januar zählt genauso als 1 Monat wie der 1. bis zum 31. Januar;
	 * der 20. Januar bis zum 5. März zählt als 3 Monate (Jan, Feb, Mär).
	 *
	 * @param string $from gültiges Datum JJJJ-MM-TT
	 * @param string $to gültiges Datum JJJJ-MM-TT, muss >= $from sein
	 * @throws \InvalidArgumentException bei unmöglichem Datum oder $to < $from
	 */
	public static function monthsSpanned(string $from, string $to): int {
		[$fy, $fm] = self::parse($from);
		[$ty, $tm] = self::parse($to);
		if ($to < $from) {
			throw new \InvalidArgumentException('Ende ' . $to . ' liegt vor dem Anfang ' . $from . '.');
		}
		return ($ty - $fy) * 12 + ($tm - $fm) + 1;
	}

	/**
	 * Prorata-Betrag für den Zeitraum $from bis $to: Monatsbeitrag × Anzahl
	 * voller Monate (siehe {@see monthsSpanned()}) – reine Multiplikation,
	 * nirgends wird geteilt oder gerundet.
	 *
	 * @throws \InvalidArgumentException bei unmöglichem Datum, $to < $from
	 *                                   oder negativem Monatsbeitrag
	 */
	public static function amountCents(int $monthlyAmountCents, string $from, string $to): int {
		if ($monthlyAmountCents < 0) {
			throw new \InvalidArgumentException('Monatsbeitrag darf nicht negativ sein.');
		}
		return $monthlyAmountCents * self::monthsSpanned($from, $to);
	}

	/**
	 * @return array{0:int, 1:int} Jahr, Monat
	 * @throws \InvalidArgumentException bei unmöglichem Datum
	 */
	private static function parse(string $date): array {
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			throw new \InvalidArgumentException('Kein gültiges Datum: ' . $date);
		}
		return [(int)$m[1], (int)$m[2]];
	}
}
