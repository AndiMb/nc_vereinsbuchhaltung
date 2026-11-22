<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCP\IL10N;

/**
 * Grenzen für Beiträge und Untergrenzen von Zuweisungen und Beitragsgruppen,
 * als Monats- oder als Jahresbetrag (Issue #270).
 *
 * Die Forderungserzeugung multipliziert den Monatsbeitrag mit bis zu zwölf
 * Monaten und schreibt das Ergebnis in eine 32-Bit-Spalte. Ein Wert, der
 * dort nicht hineinpasst, ließ den täglichen Lauf abbrechen – für alle
 * Mitglieder nach diesem einen.
 */
final class MonthlyAmount {
	/** 1 Mio. € im Monat: mal zwölf Monate bleibt das unter 2^31 Cent. */
	public const MAX_CENTS = 100_000_000;

	/** Dieselbe Grenze als Jahresbetrag. */
	public const MAX_ANNUAL_CENTS = 12 * self::MAX_CENTS;

	/**
	 * Euro aus einer Anfrage in Cent. Werte außerhalb des zulässigen Bereichs
	 * werden auf knapp daneben begrenzt, damit die Umwandlung in int nie
	 * überläuft; {@see assertValid()} lehnt sie dann ab.
	 */
	public static function centsFromEuro(float $euro): int {
		if (is_nan($euro)) {
			return -1;
		}
		return (int)round(max(-1.0, min($euro, self::MAX_ANNUAL_CENTS / 100 + 1)) * 100);
	}

	/**
	 * @param bool $annual ob `$cents` ein Jahresbetrag ist
	 * @throws \InvalidArgumentException bei einem negativen oder zu hohen Betrag
	 */
	public static function assertValid(int $cents, IL10N $l10n, bool $annual = false): void {
		if ($cents < 0) {
			throw new \InvalidArgumentException($l10n->t('Beträge dürfen nicht negativ sein.'));
		}
		$max = $annual ? self::MAX_ANNUAL_CENTS : self::MAX_CENTS;
		if ($cents > $max) {
			// Ohne Währungszeichen: die Grenze ist eine Zahl, die Währung führt der Verein (#202).
			$limit = number_format($max / 100, 2, ',', '.');
			throw new \InvalidArgumentException($annual
				? $l10n->t('Der Jahresbeitrag darf höchstens %s betragen.', [$limit])
				: $l10n->t('Der Monatsbeitrag darf höchstens %s betragen.', [$limit]));
		}
	}
}
