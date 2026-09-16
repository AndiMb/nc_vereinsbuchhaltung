<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

/**
 * „Eine Wirksamkeitsregel für alles" (Spec §3.3): Gruppenwechsel,
 * Turnuswechsel, Betragsänderung und Untergrenzen-Erhöhung wirken alle ab der
 * ersten Periode ohne versandte Vorabinfo. Bereits eingezogene *und* bereits
 * vorabinformierte Perioden werden nie neu berechnet – keine Rückrechnung,
 * kein Guthaben, keine Erstattung.
 *
 * Reine Datumsarithmetik ohne Zustand, ohne Datenbank – dasselbe Muster wie
 * {@see ProrataCalculator}/{@see BillingPeriod}/{@see PeriodRule}.
 *
 * Bewusst vereinfachter Anwendungsstand für Issue #68: `prenotified_at` (die
 * Sperrgrenze) wird erst ab Ticket #70 (Einzugszyklus) überhaupt gesetzt –
 * solange keine Forderung je vorabinformiert wurde, liefert diese Klasse für
 * jede Anfrage schlicht das angefragte Datum zurück ("keine Sperre"). Die
 * Regel selbst ist trotzdem schon vollständig und korrekt implementiert,
 * damit #70 sie unverändert übernehmen kann, sobald reale
 * `prenotified_at`-Werte existieren.
 */
final class EffectivityRuleService {

	/** Eine Periode ist gesperrt, sobald für sie eine Vorabinfo versandt wurde. */
	public static function isLocked(?string $prenotifiedAt): bool {
		return $prenotifiedAt !== null;
	}

	/**
	 * Der früheste Tag, ab dem eine Änderung wirken darf: der angefragte Tag,
	 * oder – falls die Zuweisung bereits vorabinformierte Perioden hat – der
	 * erste Tag nach der letzten davon, je nachdem, was später liegt.
	 *
	 * Erwartet die bereits existierenden Perioden(-Forderungen) einer
	 * Zuweisung, aufsteigend nach `periodStart` sortiert. Da eine Vorabinfo
	 * chronologisch fortschreitet, genügt es, vom Anfang der Liste weg
	 * gesperrte Perioden zu zählen, bis die erste ungesperrte kommt – danach
	 * sind laut Spec ohnehin alle folgenden noch änderbar.
	 *
	 * @param list<array{periodStart: string, periodEnd: string, prenotifiedAt: ?string}> $existingPeriods
	 * @throws \InvalidArgumentException bei unmöglichem Datum
	 */
	public static function firstEffectiveDate(array $existingPeriods, string $requestedFrom): string {
		self::assertDate($requestedFrom);
		$blockedUntil = null;
		foreach ($existingPeriods as $period) {
			if (!self::isLocked($period['prenotifiedAt'])) {
				break;
			}
			$blockedUntil = PeriodRule::nextDay($period['periodEnd']);
		}
		if ($blockedUntil === null) {
			return $requestedFrom;
		}
		return $blockedUntil > $requestedFrom ? $blockedUntil : $requestedFrom;
	}

	/** @throws \InvalidArgumentException bei unmöglichem Datum */
	private static function assertDate(string $date): void {
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			throw new \InvalidArgumentException('Kein gültiges Datum: ' . $date);
		}
	}
}
