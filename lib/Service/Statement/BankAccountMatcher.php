<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Statement;

use OCA\Vereinsbuchhaltung\Db\Account;

/**
 * Findet das Geldkonto, zu dem das eigene Konto eines Umsatzes gehört.
 *
 * Am Geldkonto steht nur die IBAN, der Auszug nennt das eigene Konto aber
 * nicht immer so: MT940 führt im Feld :25: oft "BLZ/Kontonummer", die CSV
 * mancher Bank im Feld „Auftragskonto" nur die Kontonummer (Issue #154).
 * Eine deutsche IBAN enthält beides – Stelle 5–12 die BLZ, Stelle 13–22 die
 * zehnstellige Kontonummer –, deshalb genügt die IBAN am Geldkonto auch für
 * diese Auszüge.
 *
 * Verglichen wird in Stufen, die erste Stufe mit Treffern entscheidet:
 *  1. die IBAN genau,
 *  2. die Kontonummer (mit führenden Nullen auf zehn Stellen aufgefüllt),
 *  3. die BLZ – für Auszüge, die nur die BLZ mitbringen.
 *
 * Ob ein Treffer eindeutig ist, entscheidet der Aufrufer: mehr als ein
 * Kandidat heißt, der Auszug passt zu mehreren Geldkonten.
 */
class BankAccountMatcher {

	public function __construct(
		private RowNormalizer $normalizer = new RowNormalizer(),
	) {
	}

	/**
	 * @param Account[] $bankAccounts
	 * @return Account[] alle Geldkonten der ersten Stufe mit Treffern; leer ohne Treffer
	 */
	public function candidates(?string $ownAccount, array $bankAccounts): array {
		$wanted = $this->normalizer->normalizeOwnAccount($ownAccount);
		if ($wanted === null) {
			return [];
		}

		$byIban = array_filter($bankAccounts, fn (Account $a): bool => $this->iban($a) === $wanted);
		if ($byIban !== []) {
			return array_values($byIban);
		}

		$digits = $this->digits($wanted);
		if ($digits === null) {
			return [];
		}

		$number = str_pad($digits, 10, '0', STR_PAD_LEFT);
		$byNumber = array_filter($bankAccounts, fn (Account $a): bool => $this->germanParts($a)[1] === $number);
		if ($byNumber !== []) {
			return array_values($byNumber);
		}

		if (strlen($digits) !== 8) {
			return [];
		}
		return array_values(array_filter($bankAccounts, fn (Account $a): bool => $this->germanParts($a)[0] === $digits));
	}

	/**
	 * Ob der Wert überhaupt auf ein bestimmtes Konto zeigt. Eine bloße
	 * Bezeichnung wie „Girokonto" (CSV-Spalte „Bezeichnung Auftragskonto")
	 * tut das nicht – sie gilt wie ein fehlender Wert.
	 */
	public function identifiesAccount(?string $ownAccount): bool {
		$wanted = $this->normalizer->normalizeOwnAccount($ownAccount);
		return $wanted !== null && preg_match('/\d/', $wanted) === 1;
	}

	/**
	 * Die Kontonummer aus einem rein numerischen Wert; ein angehängtes
	 * Währungskürzel ("0648489890EUR") wird abgeschnitten.
	 */
	private function digits(string $wanted): ?string {
		if (preg_match('/^(\d{1,10})(?:[A-Z]{3})?$/', $wanted, $m) !== 1) {
			return null;
		}
		return $m[1];
	}

	private function iban(Account $account): ?string {
		return $this->normalizer->normalizeOwnAccount($account->getIban());
	}

	/** @return array{0: ?string, 1: ?string} [BLZ, Kontonummer] einer deutschen IBAN, sonst [null, null] */
	private function germanParts(Account $account): array {
		$iban = $this->iban($account);
		if ($iban === null || preg_match('/^DE\d{2}(\d{8})(\d{10})$/', $iban, $m) !== 1) {
			return [null, null];
		}
		return [$m[1], $m[2]];
	}
}
