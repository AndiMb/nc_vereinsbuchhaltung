<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCP\IL10N;

/**
 * Formale IBAN-Prüfung (Länderkürzel + Längenrahmen), bewusst ohne
 * Prüfsummenrechnung: eine formal gültige, aber fremde IBAN würde sie ebenso
 * durchlassen, und eine zu strenge Prüfung sperrt am Ende jemanden mit einem
 * ausländischen Konto oder Mandat aus. Ursprünglich nur in AccountService,
 * jetzt auch von SepaMandateService genutzt.
 */
class IbanValidator {

	public function __construct(
		private IL10N $l10n,
	) {
	}

	/**
	 * Prüft und vereinheitlicht eine IBAN (Geldkonto oder SEPA-Mandat).
	 *
	 * Gespeichert wird in derselben Normalform, die auch der Kontoauszugs-
	 * Import benutzt (Großbuchstaben, ohne Leerzeichen). Nur so trifft ein
	 * Vergleich mit dem Feld „eigenes Konto" einer importierten Bankbuchung –
	 * wer „DE12 3456 …" einträgt und die Bank „DE123456…" liefert, hätte
	 * sonst zwei Werte, die für den Menschen gleich aussehen und für die App
	 * nicht.
	 *
	 * @throws \InvalidArgumentException wenn $iban gesetzt, aber ungültig formatiert ist
	 */
	public function validate(?string $iban): ?string {
		if ($iban === null) {
			return null;
		}
		// Leerzeichen sind in Anzeige/Papierform üblich (Bankleitzahl-Gruppen),
		// beim Vergleich mit importierten Kontoauszügen stören sie aber.
		$normalized = strtoupper((string)preg_replace('/\s+/', '', $iban));
		if ($normalized === '') {
			return null;
		}
		if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{6,30}$/', $normalized)) {
			throw new \InvalidArgumentException(
				$this->l10n->t('Das sieht nicht nach einer IBAN aus: %s (erwartet wird z. B. DE12 5001 0517 0648 4898 90).', [$iban])
			);
		}
		return $normalized;
	}
}
