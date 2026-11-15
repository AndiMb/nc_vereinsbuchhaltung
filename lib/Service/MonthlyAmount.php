<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCP\IL10N;

/**
 * Grenzen für Monatsbeiträge und Untergrenzen von Zuweisungen und
 * Beitragsgruppen.
 *
 * Die Forderungserzeugung multipliziert den Monatsbeitrag mit bis zu zwölf
 * Monaten und schreibt das Ergebnis in eine 32-Bit-Spalte. Ein Wert, der
 * dort nicht hineinpasst, ließ den täglichen Lauf abbrechen – für alle
 * Mitglieder nach diesem einen.
 */
final class MonthlyAmount {
	/** 1 Mio. € im Monat: mal zwölf Monate bleibt das unter 2^31 Cent. */
	public const MAX_CENTS = 100_000_000;

	/**
	 * Euro aus einer Anfrage in Cent. Werte außerhalb des zulässigen Bereichs
	 * werden auf knapp daneben begrenzt, damit die Umwandlung in int nie
	 * überläuft; {@see assertValid()} lehnt sie dann ab.
	 */
	public static function centsFromEuro(float $euro): int {
		if (is_nan($euro)) {
			return -1;
		}
		return (int)round(max(-1.0, min($euro, self::MAX_CENTS / 100 + 1)) * 100);
	}

	/** @throws \InvalidArgumentException bei einem negativen oder zu hohen Betrag */
	public static function assertValid(int $cents, IL10N $l10n): void {
		if ($cents < 0) {
			throw new \InvalidArgumentException($l10n->t('Beträge dürfen nicht negativ sein.'));
		}
		if ($cents > self::MAX_CENTS) {
			throw new \InvalidArgumentException($l10n->t('Der Monatsbeitrag darf höchstens %s € betragen.', [number_format(self::MAX_CENTS / 100, 2, ',', '.')]));
		}
	}
}
