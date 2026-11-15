<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\ContributionGroup;

/**
 * Betrag einer Beitragsperiode für beide Betragsangaben einer Beitragsgruppe
 * (Issue #270) – reine Rechnung ohne Zustand, wie {@see ProrataCalculator}.
 *
 * - **Monatsbetrag** (Vorgabe): Monatsbeitrag × Kalendermonate, nirgends wird
 *   geteilt oder gerundet (Spec §3.3).
 * - **Jahresbetrag**: ein volles Beitragsjahr kostet exakt den Jahresbetrag.
 *   Im angebrochenen Jahr gilt Jahresbetrag × Monate ÷ 12, kaufmännisch auf
 *   den Cent gerundet – oder, wenn die Gruppe nicht anteilig rechnet, der
 *   volle Jahresbetrag. Das ist die einzige Stelle im Beitragsmodul, an der
 *   geteilt wird.
 */
final class ContributionAmount {

	/**
	 * @param int $monthlyAmountCents Monatsbeitrag; zählt nur ohne Jahresbetrag
	 * @param int|null $annualAmountCents Jahresbetrag, null bei Gruppen mit Monatsbetrag
	 * @param bool $prorate angebrochenes Jahr anteilig berechnen (nur beim Jahresbetrag von Belang)
	 * @param string $from gültiges Datum JJJJ-MM-TT
	 * @param string $to gültiges Datum JJJJ-MM-TT, muss >= $from sein
	 * @throws \InvalidArgumentException bei unmöglichem Datum, $to < $from oder negativem Betrag
	 */
	public static function cents(int $monthlyAmountCents, ?int $annualAmountCents, bool $prorate, string $from, string $to): int {
		if ($annualAmountCents === null) {
			return ProrataCalculator::amountCents($monthlyAmountCents, $from, $to);
		}
		if ($annualAmountCents < 0) {
			throw new \InvalidArgumentException('Jahresbeitrag darf nicht negativ sein.');
		}
		$months = ProrataCalculator::monthsSpanned($from, $to);
		if (!$prorate || $months >= 12) {
			return $annualAmountCents;
		}
		return self::roundedDivision($annualAmountCents * $months, 12);
	}

	/** Betrag der Zuweisung für den Zeitraum, nach der Betragsangabe ihrer Gruppe. */
	public static function forPeriod(Assignment $assignment, ContributionGroup $group, string $from, string $to): int {
		return self::cents(
			(int)$assignment->getMonthlyAmountCents(),
			$assignment->getAnnualAmountCents(),
			$group->prorates(),
			$from,
			$to,
		);
	}

	/**
	 * Gerundeter Monatswert eines Jahresbetrags. Er füllt die Monatsspalten
	 * von Jahresgruppen als Hilfswert (Sortierung, „beitragsfrei“-Prüfung);
	 * gerechnet wird mit dem Jahresbetrag.
	 */
	public static function monthlyFromAnnual(int $annualAmountCents): int {
		return self::roundedDivision($annualAmountCents, 12);
	}

	/** Ganzzahlige Division nicht negativer Werte, halbe Cent aufgerundet. */
	private static function roundedDivision(int $dividend, int $divisor): int {
		return intdiv($dividend * 2 + $divisor, $divisor * 2);
	}
}
