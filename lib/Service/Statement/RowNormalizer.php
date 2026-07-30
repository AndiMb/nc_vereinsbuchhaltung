<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Statement;

/**
 * Bringt die Rohdaten aller Umsatzquellen auf dieselbe kanonische Zeilenform
 * und berechnet den Dedup-Hash.
 *
 * Warum zentral: der Hash entscheidet, ob ein Umsatz als Dublette gilt. Solange
 * jedes Format seine Felder selbst putzt, weicht die Schreibweise zwischen den
 * Quellen ab (die eine Bank schreibt "DE12 3456", die andere "de123456") und
 * derselbe Umsatz bekäme je Quelle einen anderen Hash. Genau ein Ort für diese
 * Regeln hält die Hashes vergleichbar.
 *
 * Wichtige Einschränkung: über den Hash allein lässt sich ein Umsatz NICHT
 * quellenübergreifend wiedererkennen. Die CSV der Sparkasse trägt im Feld
 * "Auftragskonto" oft nur eine Kontonummer, FinTS und CAMT.053 liefern dort
 * immer die IBAN – aus derselben Buchung entstehen dann zwangsläufig zwei
 * verschiedene Hashes. Diese Lücke schließt der weiche Abgleich in
 * {@see \OCA\Vereinsbuchhaltung\Service\ImportService::matchesExistingRow()}
 * über Datum, Betrag und normalisierten Text.
 */
class RowNormalizer {

	/**
	 * Baut aus den Rohwerten einer Quelle eine kanonische Zeile.
	 *
	 * Erwartete Schlüssel in $raw (alle optional außer bookingDate/amountCents):
	 * ownAccount, bookingDate, valueDate, bookingText, purpose, counterparty,
	 * counterpartyIban, counterpartyBic, amountCents, currency.
	 *
	 * @param array<string, mixed> $raw
	 * @return array<string, mixed>|null null, wenn die Zeile nicht buchbar ist
	 */
	public function build(array $raw): ?array {
		$bookingDate = $this->str($raw['bookingDate'] ?? null);
		$amountCents = isset($raw['amountCents']) ? (int)$raw['amountCents'] : null;

		// Summen-/Leerzeilen und 0,00-EUR-Buchungen (z. B. Kontoabschluss ohne
		// Zinsen) überspringen – Letztere sind in doppelter Buchführung nicht
		// buchbar und würden die Zuordnungsliste nur zumüllen.
		if ($bookingDate === null || $amountCents === null || $amountCents === 0) {
			return null;
		}

		$ownAccount = $this->normalizeOwnAccount($this->str($raw['ownAccount'] ?? null));
		$bookingText = $this->limit($this->str($raw['bookingText'] ?? null), 255);
		$counterparty = $this->limit($this->str($raw['counterparty'] ?? null), 255);

		// Bank-interne Buchungen (Entgeltabschluss, Stornorechnung …) haben keinen
		// Zahlungsbeteiligten. Damit die Zeile in „Zuzuordnen" lesbar bleibt und
		// per Regel kategorisiert werden kann, wird der Buchungstext als Label
		// eingesetzt.
		if (($counterparty === null || $counterparty === '') && $bookingText !== null && $bookingText !== '') {
			$counterparty = $bookingText;
		}

		$row = [
			'ownAccount' => $ownAccount,
			'bookingDate' => $bookingDate,
			'valueDate' => $this->str($raw['valueDate'] ?? null),
			'bookingText' => $bookingText,
			'purpose' => $this->str($raw['purpose'] ?? null),
			'counterparty' => $counterparty,
			'counterpartyIban' => $this->cleanIban($this->str($raw['counterpartyIban'] ?? null), $ownAccount),
			'counterpartyBic' => $this->cleanBic($this->str($raw['counterpartyBic'] ?? null)),
			'amountCents' => $amountCents,
			'currency' => $this->str($raw['currency'] ?? null) ?: 'EUR',
		];
		$row['hash'] = $this->computeHash($row);
		return $row;
	}

	/**
	 * Stabiler Dedup-Hash über die fachlich identifizierenden Felder.
	 * Mehrfach identische Buchungen am selben Tag erhalten denselben Hash und
	 * gelten bewusst als Dublette – seltener Sonderfall, der manuell nachgebucht
	 * werden kann.
	 *
	 * ACHTUNG bei Änderungen an dieser Methode oder an {@see hashText()}: ein
	 * abweichender Hash lässt jeden bereits importierten Umsatz wieder als neu
	 * gelten. Ein erneut eingelesener Auszug erzeugt dann Dubletten in
	 * „Zuzuordnen". Die Zusammensetzung ist deshalb bewusst identisch zu der
	 * Fassung, die bis Version 0.11.2 in CamtCsvParser stand.
	 *
	 * @param array<string, mixed> $row
	 */
	public function computeHash(array $row): string {
		$parts = [
			$row['ownAccount'] ?? '',
			$row['bookingDate'] ?? '',
			(string)($row['amountCents'] ?? ''),
			$this->hashText((string)($row['purpose'] ?? '')),
			$this->hashText((string)($row['counterparty'] ?? '')),
			$row['counterpartyIban'] ?? '',
		];
		return hash('sha256', implode('|', $parts));
	}

	/**
	 * Schlüssel für den weichen, hash-unabhängigen Abgleich:
	 * "datum|betragAbsCents|normalisierterText".
	 *
	 * Bewusst ohne Kontobezug – er ist ja gerade die Stelle, an der sich die
	 * Quellen unterscheiden.
	 */
	public function softKey(string $date, int $amountCents, string $text): ?string {
		$norm = $this->normalizeText($text);
		if ($norm === '' || $date === '') {
			return null;
		}
		return $date . '|' . abs($amountCents) . '|' . $norm;
	}

	/**
	 * Vereinheitlicht das eigene Konto.
	 *
	 * Sieht der Wert wie eine IBAN aus, wird er auf deren Normalform gebracht.
	 * Sonst bleiben nur Buchstaben und Ziffern übrig (Großschreibung), damit
	 * "3200015160" und "32000151-60" nicht als zwei verschiedene Konten gelten.
	 * Ein nicht deutbarer Rest wird nicht geraten, sondern verworfen.
	 */
	public function normalizeOwnAccount(?string $value): ?string {
		if ($value === null) {
			return null;
		}
		$v = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $value));
		if ($v === '') {
			return null;
		}
		return $this->limit($v, 40);
	}

	/**
	 * Übernimmt eine Gegenkonto-IBAN nur, wenn sie wie eine echte IBAN aussieht
	 * und nicht das eigene Konto ist. Bank-interne Buchungen tragen in dieser
	 * Spalte oft die eigene Kontonummer oder Nullen ("3200015160", "0000000000")
	 * – die haben als Gegenkonto keinen Wert und werden verworfen.
	 */
	public function cleanIban(?string $value, ?string $ownAccount): ?string {
		if ($value === null) {
			return null;
		}
		$v = strtoupper((string)preg_replace('/\s+/', '', $value));
		if ($v === '' || !preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{6,30}$/', $v)) {
			return null; // keine echte IBAN (reine Kontonummer, Nullen …)
		}
		if ($ownAccount !== null && $ownAccount !== '' && $v === strtoupper($ownAccount)) {
			return null; // eigenes Konto ist kein Gegenkonto
		}
		return $this->limit($v, 40);
	}

	/**
	 * Übernimmt einen BIC nur, wenn er dem BIC-Muster entspricht (4 Buchstaben
	 * Bankcode + 2 Buchstaben Land + 2–5 alphanumerisch). Eine reine Zahl ist
	 * eine BLZ, kein BIC ("85050300") → verworfen.
	 */
	public function cleanBic(?string $value): ?string {
		if ($value === null) {
			return null;
		}
		$v = strtoupper((string)preg_replace('/\s+/', '', $value));
		if (!preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2,5}$/', $v)) {
			return null;
		}
		return $this->limit($v, 16);
	}

	/**
	 * Textform für den Hash: klein, ohne führenden/folgenden Leerraum, innere
	 * Leerräume auf ein Leerzeichen gefaltet. Satzzeichen bleiben erhalten.
	 *
	 * Absichtlich schwächer als {@see normalizeText()}: diese Fassung geht in
	 * den Dedup-Hash ein und darf sich nicht ändern, ohne alle bestehenden
	 * Hashes zu entwerten.
	 */
	public function hashText(string $s): string {
		return preg_replace('/\s+/', ' ', mb_strtolower(trim($s))) ?? '';
	}

	/**
	 * Textform für den weichen Abgleich: klein, ohne Trennzeichen/Leer-/
	 * Sonderzeichen – nur Buchstaben und Ziffern.
	 *
	 * Robuster als {@see hashText()}, damit "Empfänger: Zweck" und
	 * "Empfänger – Zweck" als derselbe Umsatz gelten. Geht bewusst NICHT in den
	 * Hash ein.
	 */
	public function normalizeText(string $s): string {
		return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($s)) ?? '';
	}

	private function limit(?string $value, int $max): ?string {
		if ($value === null) {
			return null;
		}
		return mb_substr($value, 0, $max);
	}

	private function str(mixed $value): ?string {
		if ($value === null) {
			return null;
		}
		$s = trim((string)$value);
		return $s === '' ? null : $s;
	}
}
