<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

/**
 * Der Finanzplan im Vergleich mit einer anderen Periode (Issue #114).
 *
 * Wer den Plan für das nächste Jahr aufstellt, will sehen, um wie viel er
 * gegenüber dem Vorjahr – dessen Ist oder dessen Plan – gekürzt oder erhöht
 * wurde. Gerechnet wird immer Plan minus Vergleichswert: eine Erhöhung ist
 * positiv, bezogen auf den Betrag des Vergleichswerts.
 *
 * Wie {@see BudgetTree} statisch und ohne Datenbank. Alle Beträge sind Cent.
 */
final class BudgetComparison {

	public const BASIS_ACTUAL = 'actual';
	public const BASIS_PLAN = 'plan';

	/** Veränderung des Plans gegenüber dem Vergleichswert. */
	public static function delta(int $plan, int $compare): int {
		return $plan - $compare;
	}

	/**
	 * Veränderung in Prozent, auf eine Nachkommastelle gerundet – oder null,
	 * wenn der Vergleichswert 0 ist (ein neues Konto hat keinen Bezugswert).
	 */
	public static function percent(int $plan, int $compare): ?float {
		if ($compare === 0) {
			return null;
		}
		return round(($plan - $compare) / abs($compare) * 100, 1);
	}

	/** Die gewünschte Vergleichsbasis; Unbekanntes fällt auf das Ist zurück. */
	public static function basis(?string $basis): string {
		return $basis === self::BASIS_PLAN ? self::BASIS_PLAN : self::BASIS_ACTUAL;
	}
}
