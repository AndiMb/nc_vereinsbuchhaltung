<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Statement;

/**
 * Anfangs- und Endsaldo eines Kontos laut Kontoauszug (Kontoabstimmung,
 * Issue #159). Der Umsatz-Import braucht sie nicht und liest sie nicht.
 */
final class StatementBalance {

	/**
	 * @param string|null $ownAccount normalisierte Kontoangabe des Auszugs; null, wenn er keine trägt
	 * @param int $openingCents Saldo vor der ersten Buchung
	 * @param string $openingDate erster Buchungstag bzw. Datum des Anfangssaldos (YYYY-MM-DD)
	 * @param int $closingCents Saldo nach der letzten Buchung
	 * @param string $closingDate letzter Buchungstag bzw. Datum des Endsaldos (YYYY-MM-DD)
	 * @param list<array{date:string, expectedCents:int, actualCents:int}> $gaps Stellen, an denen die Saldenkette nicht aufgeht
	 */
	public function __construct(
		public readonly ?string $ownAccount,
		public readonly int $openingCents,
		public readonly string $openingDate,
		public readonly int $closingCents,
		public readonly string $closingDate,
		public readonly array $gaps = [],
	) {
	}
}
