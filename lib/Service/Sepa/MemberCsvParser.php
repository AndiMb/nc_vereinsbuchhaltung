<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Sepa;

use OCA\Vereinsbuchhaltung\Service\BillingPeriod;
use OCA\Vereinsbuchhaltung\Service\EmailValidator;

/**
 * Liest eine Mitgliederliste als CSV: Zahler, Bankverbindung und Beitrag in
 * einer Zeile.
 *
 * Der Anlass ist schlicht: Mandat und Beitrag mussten bisher für jedes
 * Mitglied einzeln über zwei getrennte Formulare angelegt werden, in denen
 * der Zahler jeweils erneut auszuwählen war. Für einen Chor mit 200
 * Mitgliedern ist das kein Arbeitsablauf, sondern ein Nachmittag.
 *
 * Bewusst ohne Nextcloud-Abhängigkeiten, damit sich das Format ohne laufende
 * Instanz prüfen lässt (siehe tests/unit/MemberCsvParserTest.php) – gerade
 * hier lohnt das, weil jede Vereinstabelle anders aussieht.
 *
 * Erwartete Spalten (Reihenfolge egal, Groß-/Kleinschreibung egal, deutsche
 * und englische Schreibweisen erlaubt; nicht erkannte Spalten werden
 * ignoriert):
 *
 *   Name        Freitext-Zahler – alternativ „Konto" für ein Nextcloud-Konto
 *   Konto       Nextcloud-Benutzername (optional)
 *   E-Mail      für die SEPA-Vorankündigung (optional, aber dringend empfohlen)
 *   IBAN        ohne IBAN entsteht kein Mandat, sondern nur ein Beitrag
 *   BIC         optional, seit IBAN-only fast nie nötig
 *   Mandat am   Unterschriftsdatum des Mandats
 *   Betrag      „42,50" oder „42.50"
 *   Frequenz    monatlich / vierteljährlich / halbjährlich / jährlich
 *   Start       erste Fälligkeit des Beitrags
 *
 * @phpstan-type ParsedRow array{
 *     line:int, memberUid:?string, memberLabel:?string, email:?string,
 *     iban:?string, bic:?string, signedDate:?string, amountCents:?int,
 *     frequency:?string, startDate:?string, errors:string[],
 * }
 */
class MemberCsvParser {

	/**
	 * Spaltenüberschrift → Feld. Der Schlüssel ist bereits normalisiert
	 * (kleingeschrieben, ohne alles außer a–z).
	 */
	private const HEADERS = [
		'name' => 'memberLabel',
		'zahler' => 'memberLabel',
		'mitglied' => 'memberLabel',
		'nachname' => 'memberLabel',
		'konto' => 'memberUid',
		'nutzer' => 'memberUid',
		'benutzer' => 'memberUid',
		'nextcloudkonto' => 'memberUid',
		'uid' => 'memberUid',
		'email' => 'email',
		'mail' => 'email',
		'emailadresse' => 'email',
		'iban' => 'iban',
		'bic' => 'bic',
		'mandatam' => 'signedDate',
		'mandat' => 'signedDate',
		'mandatsdatum' => 'signedDate',
		'unterschriebenam' => 'signedDate',
		'unterschrieben' => 'signedDate',
		'betrag' => 'amount',
		'beitrag' => 'amount',
		'beitragshoehe' => 'amount',
		'frequenz' => 'frequency',
		'zahlungsfrequenz' => 'frequency',
		'intervall' => 'frequency',
		'turnus' => 'frequency',
		'start' => 'startDate',
		'startdatum' => 'startDate',
		'beginn' => 'startDate',
		'erstefaelligkeit' => 'startDate',
		// Englische Spaltennamen: die Oberfläche gibt es auf Englisch, die
		// Mitgliederliste kommt dann auch mit englischen Überschriften. Ohne
		// diese Einträge blieben Mandatsdatum, Betrag und Frequenz ungelesen –
		// und eine Zeile mit IBAN, aber ohne erkanntes Mandatsdatum lehnt der
		// Import ganz ab (siehe parseRow()).
		'member' => 'memberLabel',
		'payer' => 'memberLabel',
		'surname' => 'memberLabel',
		'lastname' => 'memberLabel',
		'user' => 'memberUid',
		'username' => 'memberUid',
		'login' => 'memberUid',
		'account' => 'memberUid',
		'nextcloudaccount' => 'memberUid',
		'emailaddress' => 'email',
		'mandate' => 'signedDate',
		'mandateon' => 'signedDate',
		'mandatedate' => 'signedDate',
		'signed' => 'signedDate',
		'signedon' => 'signedDate',
		'signeddate' => 'signedDate',
		'amount' => 'amount',
		'fee' => 'amount',
		'membershipfee' => 'amount',
		'frequency' => 'frequency',
		'interval' => 'frequency',
		'paymentfrequency' => 'frequency',
		'startdate' => 'startDate',
		'firstdue' => 'startDate',
		'firstduedate' => 'startDate',
	];

	/** Beschriftung → Schlüssel; die englischen Schlüssel gelten ebenfalls. */
	private const FREQUENCIES = [
		'monatlich' => 'monthly',
		'monat' => 'monthly',
		'vierteljaehrlich' => 'quarterly',
		'quartalsweise' => 'quarterly',
		'quartal' => 'quarterly',
		'halbjaehrlich' => 'semiannual',
		'halbjahr' => 'semiannual',
		'jaehrlich' => 'yearly',
		'jahr' => 'yearly',
		// Englische Beschriftungen; die Schlüssel selbst (monthly, quarterly,
		// semiannual, yearly) erkennt parseFrequency() ohnehin über
		// BillingPeriod::FREQUENCY_MONTHS.
		'month' => 'monthly',
		'quarter' => 'quarterly',
		'quarterly' => 'quarterly',
		'halfyearly' => 'semiannual',
		'semiannually' => 'semiannual',
		'annual' => 'yearly',
		'annually' => 'yearly',
		'year' => 'yearly',
	];

	/**
	 * @param int|null $defaultAmountCents Standard-Beitrag (Einstellungen ->
	 *                                     Beiträge & SEPA), fuer Zeilen mit Start-Datum, aber ohne eigenen
	 *                                     Betrag - siehe parseRow(). Null bedeutet: kein Standardbeitrag
	 *                                     hinterlegt, Verhalten wie zuvor.
	 * @param string|null $defaultFrequency Frequenz dazu, siehe BillingPeriod::FREQUENCY_MONTHS.
	 * @return array{rows: list<ParsedRow>, error: ?string} `error` ist gesetzt,
	 *                                                      wenn schon die Datei als Ganzes unbrauchbar ist (keine Kopfzeile,
	 *                                                      keine erkennbare Spalte) – dann ist `rows` leer.
	 */
	public function parse(string $csv, ?int $defaultAmountCents = null, ?string $defaultFrequency = null): array {
		$lines = $this->splitLines($csv);
		if ($lines === []) {
			return ['rows' => [], 'error' => 'Die Datei ist leer.'];
		}

		$delimiter = $this->detectDelimiter($lines[0]);
		$header = $this->mapHeader(str_getcsv($lines[0], $delimiter, '"', '\\'));
		if ($header === []) {
			return ['rows' => [], 'error' => 'In der ersten Zeile wurde keine bekannte Spaltenüberschrift gefunden (erwartet z. B. Name, IBAN, Betrag).'];
		}

		$rows = [];
		foreach ($lines as $index => $line) {
			if ($index === 0 || trim($line) === '') {
				continue;
			}
			$rows[] = $this->parseRow(str_getcsv($line, $delimiter, '"', '\\'), $header, $index + 1, $defaultAmountCents, $defaultFrequency);
		}
		return ['rows' => $rows, 'error' => null];
	}

	/**
	 * @param list<string> $values
	 * @param array<int, string> $header Spaltenindex → Feldname
	 * @return ParsedRow
	 */
	private function parseRow(array $values, array $header, int $line, ?int $defaultAmountCents = null, ?string $defaultFrequency = null): array {
		$raw = [];
		foreach ($header as $index => $field) {
			$raw[$field] = isset($values[$index]) ? trim((string)$values[$index]) : '';
		}

		$errors = [];
		$memberUid = ($raw['memberUid'] ?? '') !== '' ? $raw['memberUid'] : null;
		$memberLabel = ($raw['memberLabel'] ?? '') !== '' ? $raw['memberLabel'] : null;
		if ($memberUid !== null && $memberLabel !== null) {
			// Beide gesetzt ist kein Fehler des Nutzers, sondern eine typische
			// Tabelle: Anzeigename UND Kontoname. Das Konto gewinnt, der Name
			// ist dann redundant (den liefert Nextcloud selbst).
			$memberLabel = null;
		}
		if ($memberUid === null && $memberLabel === null) {
			$errors[] = 'Weder Name noch Nextcloud-Konto angegeben.';
		}

		$email = ($raw['email'] ?? '') !== '' ? $raw['email'] : null;
		if ($email !== null && !EmailValidator::isValid($email)) {
			$errors[] = sprintf('Keine gültige E-Mail-Adresse: %s', $email);
			$email = null;
		}

		$iban = ($raw['iban'] ?? '') !== '' ? strtoupper(str_replace(' ', '', $raw['iban'])) : null;
		$bic = ($raw['bic'] ?? '') !== '' ? strtoupper(str_replace(' ', '', $raw['bic'])) : null;

		$signedDate = null;
		if (($raw['signedDate'] ?? '') !== '') {
			$signedDate = $this->parseDate($raw['signedDate']);
			if ($signedDate === null) {
				$errors[] = sprintf('Unlesbares Mandatsdatum: %s', $raw['signedDate']);
			}
		} elseif ($iban !== null) {
			// Das Unterschriftsdatum wandert als DtOfSgntr in jede Einreichung
			// und ist der Nachweis, dass es das Mandat gibt. Ohne Datum kein
			// Mandat – hier zu raten wäre in der Sache falsch.
			$errors[] = 'Zu einer IBAN gehört das Datum, an dem das Mandat unterschrieben wurde.';
		}

		$amountCents = null;
		$usedDefaultAmount = false;
		if (($raw['amount'] ?? '') !== '') {
			$amountCents = $this->parseAmount($raw['amount']);
			if ($amountCents === null || $amountCents <= 0) {
				$errors[] = sprintf('Unlesbarer oder nicht positiver Betrag: %s', $raw['amount']);
				$amountCents = null;
			}
		} elseif ($defaultAmountCents !== null && ($raw['startDate'] ?? '') !== '') {
			// Standardbeitrag (Einstellungen -> Beiträge & SEPA): eine Zeile mit
			// Start-Datum, aber ohne eigenen Betrag, zahlt den üblichen Satz -
			// sonst müsste er in jeder Zeile wiederholt werden. Ohne Start-Datum
			// wäre aus einer reinen Mandatszeile ("nur IBAN") ungefragt ein
			// Beitrag geworden.
			$amountCents = $defaultAmountCents;
			$usedDefaultAmount = true;
		}

		$frequency = null;
		if (($raw['frequency'] ?? '') !== '') {
			$frequency = $this->parseFrequency($raw['frequency']);
			if ($frequency === null) {
				$errors[] = sprintf('Unbekannte Zahlungsfrequenz: %s', $raw['frequency']);
			}
		} elseif ($usedDefaultAmount) {
			$frequency = $defaultFrequency ?? 'yearly';
		} elseif ($amountCents !== null) {
			// Ein Betrag ohne Frequenz ist fast immer ein Jahresbeitrag; das ist
			// die häufigste Vereinstabelle überhaupt.
			$frequency = 'yearly';
		}

		$startDate = null;
		if (($raw['startDate'] ?? '') !== '') {
			$startDate = $this->parseDate($raw['startDate']);
			if ($startDate === null) {
				$errors[] = sprintf('Unlesbares Startdatum: %s', $raw['startDate']);
			}
		} elseif ($amountCents !== null) {
			$errors[] = 'Zu einem Betrag gehört ein Startdatum (erste Fälligkeit).';
		}

		if ($iban === null && $amountCents === null && $errors === []) {
			$errors[] = 'Zeile enthält weder eine IBAN noch einen Beitrag – nichts anzulegen.';
		}

		return [
			'line' => $line,
			'memberUid' => $memberUid,
			'memberLabel' => $memberLabel,
			'email' => $email,
			'iban' => $iban,
			'bic' => $bic,
			'signedDate' => $signedDate,
			'amountCents' => $amountCents,
			'frequency' => $frequency,
			'startDate' => $startDate,
			'errors' => $errors,
		];
	}

	/**
	 * @param list<string> $columns
	 * @return array<int, string> Spaltenindex → Feldname
	 */
	private function mapHeader(array $columns): array {
		$header = [];
		foreach ($columns as $index => $name) {
			$key = $this->normalizeKey((string)$name);
			if (isset(self::HEADERS[$key])) {
				$header[$index] = self::HEADERS[$key];
			}
		}
		return $header;
	}

	/**
	 * Umlaute werden aufgelöst, alles Übrige entfernt: „Zahlungs-Frequenz",
	 * „zahlungsfrequenz" und „Zahlungsfrequenz " sind dieselbe Spalte.
	 */
	private function normalizeKey(string $name): string {
		$name = strtr(mb_strtolower(trim($name)), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
		// BOM und andere unsichtbare Zeichen aus Tabellenkalkulationen
		return (string)preg_replace('/[^a-z]/', '', $name);
	}

	private function parseFrequency(string $value): ?string {
		$key = $this->normalizeKey($value);
		if (isset(self::FREQUENCIES[$key])) {
			return self::FREQUENCIES[$key];
		}
		return isset(BillingPeriod::FREQUENCY_MONTHS[$key]) ? $key : null;
	}

	/** Akzeptiert JJJJ-MM-TT und TT.MM.JJJJ – beides kommt aus Vereinstabellen. */
	private function parseDate(string $value): ?string {
		$value = trim($value);
		if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m)) {
			[$year, $month, $day] = [(int)$m[1], (int)$m[2], (int)$m[3]];
		} elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m)) {
			[$year, $month, $day] = [(int)$m[3], (int)$m[2], (int)$m[1]];
		} else {
			return null;
		}
		return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
	}

	/**
	 * Wie CamtCsvParser::parseAmount(). Das Minuszeichen wird ausdrücklich
	 * mitgeführt, obwohl ein negativer Beitrag unsinnig ist: würde es hier
	 * einfach wegfallen, machte der Import aus „-42,50" klaglos eine Forderung
	 * über 42,50 €. Lieber ein negativer Wert, den der Aufrufer beanstandet.
	 */
	private function parseAmount(string $value): ?int {
		$value = trim($value);
		$negative = str_starts_with($value, '-');
		$v = (string)preg_replace('/[^0-9,.]/', '', $value);
		if ($v === '') {
			return null;
		}
		if (str_contains($v, ',')) {
			$v = str_replace(['.', ','], ['', '.'], $v);
		}
		if (!is_numeric($v)) {
			return null;
		}
		$cents = (int)round(((float)$v) * 100);
		return $negative ? -$cents : $cents;
	}

	/** @return list<string> */
	private function splitLines(string $csv): array {
		$csv = str_replace("\xEF\xBB\xBF", '', $csv);
		$lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];
		return array_values(array_filter($lines, static fn (string $l): bool => trim($l) !== ''));
	}

	/** Semikolon ist in deutschen Tabellen der Normalfall, Komma der Ausnahmefall. */
	private function detectDelimiter(string $headerLine): string {
		return substr_count($headerLine, ';') >= substr_count($headerLine, ',') ? ';' : ',';
	}
}
