<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Service\Statement\RowNormalizer;
use OCA\Vereinsbuchhaltung\Service\Statement\StatementParser;

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
 *
 * Zuständig ist dieser Parser nur noch für das Auffinden der Spalten und das
 * Deuten von Datum und Betrag. Das Putzen der Felder und der Dedup-Hash liegen
 * in {@see RowNormalizer}, gemeinsam mit allen anderen Umsatzquellen.
 */
class CamtCsvParser implements StatementParser {

	public function __construct(
		private RowNormalizer $normalizer = new RowNormalizer(),
	) {
	}

	public function sourceKey(): string {
		return 'csv';
	}

	/**
	 * CSV ist der Rückfall, wenn kein spezielleres Format greift: eine
	 * Kopfzeile mit erkennbaren Pflichtspalten genügt.
	 */
	public function supports(string $content): bool {
		$firstLine = strtok(ltrim($content, "\xEF\xBB\xBF"), "\r\n");
		if ($firstLine === false || $firstLine === '') {
			return false;
		}
		$map = $this->mapHeader(str_getcsv($firstLine, $this->detectDelimiter($firstLine), '"', '\\'));
		return isset($map['bookingDate'], $map['amount']);
	}

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

		$records = $this->readRecords($content);
		if (count($records) < 2) {
			throw new \RuntimeException('Die Datei enthält keine Buchungszeilen.');
		}

		$map = $this->mapHeader($records[0]);
		if (!isset($map['bookingDate']) || !isset($map['amount'])) {
			throw new \RuntimeException('Pflichtspalten (Buchungstag, Betrag) konnten in der Kopfzeile nicht gefunden werden.');
		}

		$rows = [];
		for ($i = 1; $i < count($records); $i++) {
			$cols = $records[$i];
			if (count($cols) === 1 && trim((string)$cols[0]) === '') {
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
	 * Zerlegt den Dateiinhalt in CSV-Datensätze.
	 *
	 * Bewusst über einen Stream und fgetcsv() statt zuerst an Zeilenumbrüchen
	 * zu trennen: ein Verwendungszweck darf laut CSV einen Zeilenumbruch
	 * enthalten, solange er in Anführungszeichen steht. Beim zeilenweisen
	 * Vorgehen zerriss so ein Feld – die Buchung ging entweder verloren oder
	 * bekam verschobene Spalten, und beides fiel erst beim Abgleich der Salden
	 * auf.
	 *
	 * @return array<int, array<int, string|null>>
	 */
	private function readRecords(string $content): array {
		$firstLine = strtok($content, "\r\n");
		$delimiter = $this->detectDelimiter($firstLine === false ? '' : $firstLine);

		$handle = fopen('php://temp', 'r+');
		if ($handle === false) {
			throw new \RuntimeException('Die Datei konnte nicht gelesen werden.');
		}
		fwrite($handle, $content);
		rewind($handle);

		$records = [];
		while (($cols = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
			// Vollständig leere Zeilen überspringen (fgetcsv liefert [null]).
			if ($cols === [null]) {
				continue;
			}
			$joined = trim(implode('', array_map(static fn ($c) => (string)$c, $cols)));
			if ($joined === '') {
				continue;
			}
			$records[] = $cols;
		}
		fclose($handle);

		return $records;
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
			// fgetcsv liefert für leere Felder null.
			return isset($cols[$idx]) ? trim((string)$cols[$idx]) : null;
		};

		return $this->normalizer->build([
			'ownAccount' => $get('ownAccount'),
			'bookingDate' => $this->parseDate($get('bookingDate')),
			'valueDate' => $this->parseDate($get('valueDate')),
			'bookingText' => $get('bookingText'),
			'purpose' => $get('purpose'),
			'counterparty' => $get('counterparty'),
			'counterpartyIban' => $get('counterpartyIban'),
			'counterpartyBic' => $get('counterpartyBic'),
			'amountCents' => $this->parseAmount($get('amount')),
			'currency' => $get('currency'),
		]);
	}

	/**
	 * @param string[] $header
	 * @return array<string, int>
	 */
	private function mapHeader(array $header): array {
		$normalized = [];
		foreach ($header as $idx => $name) {
			// fgetcsv liefert für leere Felder null.
			$normalized[$idx] = $this->normalizeKey((string)$name);
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
			// checkdate statt einer Bereichsprüfung: sonst käme aus "31.02.2026"
			// das Datum 2026-02-31, das es nicht gibt. Als String ginge es
			// durch, MySQL im Strict Mode lehnt es dagegen erst beim Speichern ab.
			if (!checkdate($month, $day, $year)) {
				return null;
			}
			return sprintf('%04d-%02d-%02d', $year, $month, $day);
		}
		// ISO-Format direkt akzeptieren
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)
			&& checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
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

	private function toUtf8(string $content): string {
		$encoding = mb_detect_encoding($content, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
		if ($encoding === false || $encoding === 'UTF-8') {
			return $content;
		}
		$converted = mb_convert_encoding($content, 'UTF-8', $encoding);
		return $converted === false ? $content : $converted;
	}
}
