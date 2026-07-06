<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

/**
 * Parser für das CSV-CAMT-Format deutscher Banken (Sparkasse, Volksbank, …).
 *
 * Typische Kopfzeile (Semikolon-getrennt, oft Windows-1252):
 *   Auftragskonto;Buchungstag;Valutadatum;Buchungstext;Verwendungszweck;
 *   Glaeubiger ID;Mandatsreferenz;Kundenreferenz (End-to-End);Sammlerreferenz;
 *   Lastschrift Ursprungsbetrag;Auslagenersatz Ruecklastschrift;
 *   Beguenstigter/Zahlungspflichtiger;Kontonummer/IBAN;BIC (SWIFT-Code);
 *   Betrag;Waehrung;Info
 *
 * Beträge im deutschen Format (1.234,56), Datum als TT.MM.JJ(JJ).
 */
class CamtCsvParser {

	/**
	 * Synonyme je logischem Feld. Schlüssel werden normalisiert verglichen
	 * (klein, ohne Umlaute/Leer-/Sonderzeichen).
	 *
	 * @var array<string, string[]>
	 */
	private const FIELD_SYNONYMS = [
		'ownAccount' => ['auftragskonto', 'bezeichnungauftragskonto', 'ibanauftragskonto', 'kontonummerauftraggeber', 'iban'],
		'bookingDate' => ['buchungstag', 'buchungsdatum'],
		'valueDate' => ['valutadatum', 'wertstellung', 'valuta'],
		'bookingText' => ['buchungstext', 'umsatzart', 'transaktionstyp'],
		'purpose' => ['verwendungszweck', 'vwz'],
		'counterparty' => ['beguenstigterzahlungspflichtiger', 'beguenstigter', 'zahlungspflichtiger', 'name', 'namezahlungsbeteiligter', 'empfaengerzahlungspflichtiger', 'auftraggeberempfaenger'],
		'counterpartyIban' => ['kontonummeriban', 'kontonummer', 'ibankontonummer', 'kontoiban', 'ibanzahlungsbeteiligter'],
		'counterpartyBic' => ['bicswiftcode', 'bic', 'swift', 'bicswiftcodezahlungsbeteiligter', 'biczahlungsbeteiligter'],
		'amount' => ['betrag', 'umsatz'],
		'currency' => ['waehrung', 'whrg', 'currency'],
	];

	/**
	 * Liest CSV-Inhalt und liefert eine Liste normalisierter Buchungen.
	 *
	 * @return array<int, array<string, mixed>>
	 * @throws \RuntimeException bei unbrauchbarer Datei
	 */
	public function parse(string $content): array {
		$content = $this->toUtf8($content);
		$content = preg_replace('/^\xEF\xBB\xBF/', '', $content); // BOM entfernen

		$lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
		$lines = array_values(array_filter($lines, static fn ($l) => trim($l) !== ''));
		if (count($lines) < 2) {
			throw new \RuntimeException('Die Datei enthält keine Buchungszeilen.');
		}

		$delimiter = $this->detectDelimiter($lines[0]);
		$header = str_getcsv($lines[0], $delimiter, '"', '\\');
		$map = $this->mapHeader($header);

		if (!isset($map['bookingDate']) || !isset($map['amount'])) {
			throw new \RuntimeException('Pflichtspalten (Buchungstag, Betrag) konnten in der Kopfzeile nicht gefunden werden.');
		}

		$rows = [];
		for ($i = 1; $i < count($lines); $i++) {
			$cols = str_getcsv($lines[$i], $delimiter, '"', '\\');
			if (count($cols) === 1 && trim($cols[0]) === '') {
				continue;
			}
			$row = $this->buildRow($cols, $map);
			if ($row !== null) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * @param string[] $cols
	 * @param array<string, int> $map
	 * @return array<string, mixed>|null
	 */
	private function buildRow(array $cols, array $map): ?array {
		$get = static function (string $field) use ($cols, $map): ?string {
			if (!isset($map[$field])) {
				return null;
			}
			$idx = $map[$field];
			return isset($cols[$idx]) ? trim($cols[$idx]) : null;
		};

		$bookingDate = $this->parseDate($get('bookingDate'));
		$amountCents = $this->parseAmount($get('amount'));
		if ($bookingDate === null || $amountCents === null) {
			return null; // Summen-/Leerzeilen überspringen
		}

		$row = [
			'ownAccount' => $get('ownAccount'),
			'bookingDate' => $bookingDate,
			'valueDate' => $this->parseDate($get('valueDate')),
			'bookingText' => $this->limit($get('bookingText'), 255),
			'purpose' => $get('purpose'),
			'counterparty' => $this->limit($get('counterparty'), 255),
			'counterpartyIban' => $this->limit($get('counterpartyIban'), 40),
			'counterpartyBic' => $this->limit($get('counterpartyBic'), 16),
			'amountCents' => $amountCents,
			'currency' => $get('currency') ?: 'EUR',
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
	 * @param array<string, mixed> $row
	 */
	public function computeHash(array $row): string {
		$parts = [
			$row['ownAccount'] ?? '',
			$row['bookingDate'] ?? '',
			(string)($row['amountCents'] ?? ''),
			$this->normalizeText((string)($row['purpose'] ?? '')),
			$this->normalizeText((string)($row['counterparty'] ?? '')),
			$row['counterpartyIban'] ?? '',
		];
		return hash('sha256', implode('|', $parts));
	}

	/**
	 * @param string[] $header
	 * @return array<string, int>
	 */
	private function mapHeader(array $header): array {
		$normalized = [];
		foreach ($header as $idx => $name) {
			$normalized[$idx] = $this->normalizeKey($name);
		}
		$map = [];
		foreach (self::FIELD_SYNONYMS as $field => $synonyms) {
			foreach ($synonyms as $syn) {
				foreach ($normalized as $idx => $col) {
					if ($col === $syn && !isset($map[$field])) {
						$map[$field] = $idx;
						break 2;
					}
				}
			}
		}
		return $map;
	}

	private function detectDelimiter(string $headerLine): string {
		$candidates = [';', ',', "\t"];
		$best = ';';
		$bestCount = -1;
		foreach ($candidates as $d) {
			$count = substr_count($headerLine, $d);
			if ($count > $bestCount) {
				$bestCount = $count;
				$best = $d;
			}
		}
		return $best;
	}

	private function parseDate(?string $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		// TT.MM.JJJJ oder TT.MM.JJ
		if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})$/', $value, $m)) {
			$day = (int)$m[1];
			$month = (int)$m[2];
			$year = (int)$m[3];
			if ($year < 100) {
				$year += 2000;
			}
			if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
				return null;
			}
			return sprintf('%04d-%02d-%02d', $year, $month, $day);
		}
		// ISO-Format direkt akzeptieren
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
			return $value;
		}
		return null;
	}

	/**
	 * Wandelt "1.234,56" oder "-1234,56" oder "1234.56" in Cent (int) um.
	 */
	private function parseAmount(?string $value): ?int {
		if ($value === null || $value === '') {
			return null;
		}
		$v = trim($value);
		$negative = false;
		if (str_starts_with($v, '-')) {
			$negative = true;
		}
		$v = preg_replace('/[^0-9,.]/', '', $v) ?? '';
		if ($v === '') {
			return null;
		}
		if (str_contains($v, ',')) {
			// deutsches Format: Punkt = Tausender, Komma = Dezimal
			$v = str_replace('.', '', $v);
			$v = str_replace(',', '.', $v);
		}
		if (!is_numeric($v)) {
			return null;
		}
		$cents = (int)round(((float)$v) * 100);
		return $negative ? -abs($cents) : $cents;
	}

	private function normalizeKey(string $name): string {
		$name = $this->toUtf8($name);
		$name = mb_strtolower($name);
		$name = strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
		return preg_replace('/[^a-z0-9]/', '', $name) ?? '';
	}

	private function normalizeText(string $text): string {
		$text = mb_strtolower(trim($text));
		return preg_replace('/\s+/', ' ', $text) ?? '';
	}

	private function limit(?string $value, int $max): ?string {
		if ($value === null) {
			return null;
		}
		return mb_substr($value, 0, $max);
	}

	private function toUtf8(string $content): string {
		$encoding = mb_detect_encoding($content, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
		if ($encoding === false || $encoding === 'UTF-8') {
			return $content;
		}
		$converted = mb_convert_encoding($content, 'UTF-8', $encoding);
		return $converted === false ? $content : $converted;
	}
}
