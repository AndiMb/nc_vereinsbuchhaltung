<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Statement;

/**
 * Parser für MT940 (SWIFT-Kontoauszug, im Onlinebanking oft „.sta" oder
 * „Kontoauszug MT940").
 *
 * Aufbau: zeilenweise Felder `:NN:`, eine Buchung besteht aus `:61:`
 * (Datum, Betrag, Richtung) und dem folgenden `:86:` (Verwendungszweck,
 * Zahlungsbeteiligter). Ein Feld läuft bis zur nächsten Zeile, die mit `:`
 * beginnt.
 *
 * Der Parser wird doppelt gebraucht: für hochgeladene MT940-Dateien und – in
 * der geplanten zweiten Stufe – für die Antwort des FinTS-Umsatzabrufs, der
 * genau dieses Format liefert.
 */
class Mt940Parser implements StatementParser {

	public function __construct(
		private RowNormalizer $normalizer = new RowNormalizer(),
	) {
	}

	public function sourceKey(): string {
		return 'mt940';
	}

	public function supports(string $content): bool {
		$head = substr(ltrim($content), 0, 2048);
		// :20: (Auftragsreferenz) eröffnet jeden Auszug, :61: ist die Buchung.
		return (bool)preg_match('/^:20:/m', $head) || (bool)preg_match('/^:61:/m', $head);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function parse(string $content): array {
		$fields = $this->fields($content);

		$rows = [];
		$ownAccount = null;
		$pending = null;

		foreach ($fields as [$tag, $value]) {
			if ($tag === '25') {
				$ownAccount = $this->ownAccount($value);
				continue;
			}
			if ($tag === '61') {
				// Eine Buchung ohne folgendes :86: ist zulässig (selten, aber
				// erlaubt) – die vorherige also abschließen, bevor die neue beginnt.
				if ($pending !== null) {
					$rows[] = $pending;
				}
				$pending = $this->parseEntry($value, $ownAccount);
				continue;
			}
			if ($tag === '86' && $pending !== null) {
				$pending = $this->applyDetails($pending, $value);
				$rows[] = $pending;
				$pending = null;
			}
		}
		if ($pending !== null) {
			$rows[] = $pending;
		}

		$out = [];
		foreach ($rows as $raw) {
			$row = $this->normalizer->build($raw);
			if ($row !== null) {
				$out[] = $row;
			}
		}

		if ($out === []) {
			throw new \RuntimeException('Die MT940-Datei enthält keine lesbaren Buchungen (:61:).');
		}
		return $out;
	}

	/**
	 * Zerlegt den Auszug in Felder. Fortsetzungszeilen (alles, was nicht mit
	 * `:NN:` beginnt) gehören zum vorherigen Feld – vor allem lange
	 * Verwendungszwecke sind so umbrochen.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function fields(string $content): array {
		$lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
		$fields = [];
		$current = null;

		foreach ($lines as $line) {
			if (preg_match('/^:(\d{2}[A-Z]?):(.*)$/', $line, $m)) {
				if ($current !== null) {
					$fields[] = $current;
				}
				// :28C: und :28: sind dasselbe Feld – der Buchstabe unterscheidet
				// nur die Fassung und wird für die Auswertung nicht gebraucht.
				$current = [substr($m[1], 0, 2), $m[2]];
				continue;
			}
			if ($current !== null) {
				$current[1] .= "\n" . $line;
			}
		}
		if ($current !== null) {
			$fields[] = $current;
		}
		return $fields;
	}

	/**
	 * :25: trägt entweder eine IBAN oder "BLZ/Kontonummer".
	 * Bei der zweiten Form wird der Kontoteil genommen, weil die CSV-Exporte
	 * derselben Bank dort ebenfalls nur die Kontonummer führen.
	 */
	private function ownAccount(string $value): ?string {
		$v = trim(explode("\n", $value)[0]);
		if ($v === '') {
			return null;
		}
		if (preg_match('/[A-Z]{2}\d{2}[A-Z0-9]{10,}/i', $v, $m)) {
			return $m[0];
		}
		if (str_contains($v, '/')) {
			$parts = explode('/', $v);
			return trim(end($parts));
		}
		return $v;
	}

	/**
	 * :61: – Valutadatum (JJMMTT), optionales Buchungsdatum (MMTT), Richtung,
	 * Betrag, Geschäftsvorfall.
	 *
	 * @return array<string, mixed>|null
	 */
	private function parseEntry(string $value, ?string $ownAccount): ?array {
		$line = trim(explode("\n", $value)[0]);
		if (!preg_match('/^(\d{6})(\d{4})?(RC|RD|C|D)([A-Z])?([\d.,]+)([A-Z][A-Za-z0-9]{3})?(.*)$/', $line, $m)) {
			return null;
		}

		$valueDate = $this->dateFromYymmdd($m[1]);
		$bookingDate = $m[2] !== '' ? $this->bookingDate($m[2], $m[1]) : $valueDate;

		$cents = $this->toCents($m[5]);
		if ($cents === null) {
			return null;
		}
		$mark = $m[3];
		// D = Belastung. R davor kennzeichnet eine Stornobuchung, die die
		// ursprüngliche Richtung umkehrt: RC storniert eine Gutschrift und ist
		// damit eine Belastung.
		$negative = str_ends_with($mark, 'D');
		if (str_starts_with($mark, 'R')) {
			$negative = !$negative;
		}

		return [
			'ownAccount' => $ownAccount,
			'bookingDate' => $bookingDate,
			'valueDate' => $valueDate,
			'bookingText' => null,
			'purpose' => null,
			'counterparty' => null,
			'counterpartyIban' => null,
			'counterpartyBic' => null,
			'amountCents' => $negative ? -$cents : $cents,
			'currency' => null,
		];
	}

	/**
	 * :86: – Mehrzweckfeld, unterteilt in Schlüssel `?NN`.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function applyDetails(array $row, string $value): array {
		$text = str_replace(["\r", "\n"], '', $value);
		$sub = $this->subfields($text);

		if ($sub === []) {
			// Ohne Schlüssel ist das Feld reiner Freitext (kommt bei manchen
			// Instituten vor) – dann ist er der Verwendungszweck.
			$row['purpose'] = trim($text) !== '' ? trim($text) : null;
			return $row;
		}

		$row['bookingText'] = $sub['00'] ?? null;

		$purpose = '';
		foreach (array_keys($sub) as $key) {
			$n = (int)$key;
			// ?20–?29 und ?60–?63 sind die Verwendungszweckzeilen.
			if (($n >= 20 && $n <= 29) || ($n >= 60 && $n <= 63)) {
				$purpose .= $sub[$key];
			}
		}
		$row['purpose'] = trim($purpose) !== '' ? trim($purpose) : null;

		$name = trim(($sub['32'] ?? '') . ($sub['33'] ?? ''));
		$row['counterparty'] = $name !== '' ? $name : null;
		$row['counterpartyBic'] = $sub['30'] ?? null;
		$row['counterpartyIban'] = $sub['31'] ?? null;

		return $row;
	}

	/**
	 * @return array<string, string> Schlüssel ohne '?', z. B. '20' => 'Beitrag'
	 */
	private function subfields(string $text): array {
		if (!str_contains($text, '?')) {
			return [];
		}
		$out = [];
		// Der Geschäftsvorfallcode vor dem ersten '?' (z. B. "166") wird nicht
		// gebraucht; preg_split liefert ihn als erstes, leeres Segment mit.
		foreach (preg_split('/\?(?=\d{2})/', $text) ?: [] as $chunk) {
			if (!preg_match('/^(\d{2})(.*)$/s', $chunk, $m)) {
				continue;
			}
			$key = $m[1];
			// Mehrfach auftretende Schlüssel (?20 kommt in manchen Auszügen
			// wiederholt vor) werden angehängt statt überschrieben.
			$out[$key] = ($out[$key] ?? '') . $m[2];
		}
		return $out;
	}

	private function dateFromYymmdd(string $v): ?string {
		$year = 2000 + (int)substr($v, 0, 2);
		$month = (int)substr($v, 2, 2);
		$day = (int)substr($v, 4, 2);
		return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
	}

	/**
	 * Das Buchungsdatum trägt nur Monat und Tag; das Jahr kommt vom Valutadatum.
	 * Über den Jahreswechsel kann die Buchung im Vorjahr liegen (Buchung 30.12.,
	 * Valuta 02.01.) – dann eine Jahresgrenze zurück.
	 */
	private function bookingDate(string $mmdd, string $valueYymmdd): ?string {
		$year = 2000 + (int)substr($valueYymmdd, 0, 2);
		$valueMonth = (int)substr($valueYymmdd, 2, 2);
		$month = (int)substr($mmdd, 0, 2);
		$day = (int)substr($mmdd, 2, 2);

		if ($month === 12 && $valueMonth === 1) {
			$year--;
		} elseif ($month === 1 && $valueMonth === 12) {
			$year++;
		}
		return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
	}

	private function toCents(string $value): ?int {
		// MT940 nutzt das Komma als Dezimaltrenner; Tausenderpunkte kommen vor.
		$v = str_replace('.', '', trim($value));
		$v = str_replace(',', '.', $v);
		if (!is_numeric($v)) {
			return null;
		}
		return (int)round(((float)$v) * 100);
	}
}
