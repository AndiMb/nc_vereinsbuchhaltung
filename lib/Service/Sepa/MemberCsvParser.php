<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Sepa;

use OCA\Vereinsbuchhaltung\Service\BillingPeriod;
use OCA\Vereinsbuchhaltung\Service\EmailValidator;
use OCA\Vereinsbuchhaltung\Service\OptionalL10n;
use OCP\IL10N;

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
 * hier lohnt das, weil jede Vereinstabelle anders aussieht. Einzige Ausnahme
 * ist der optionale `IL10N` für die Fehlermeldungen (siehe {@see OptionalL10n}):
 * sie landen in der Vorschau des Imports; ohne ihn bleibt es beim deutschen
 * Quelltext.
 *
 * Erwartete Spalten (Reihenfolge egal, Groß-/Kleinschreibung egal, deutsche
 * und englische Schreibweisen erlaubt; nicht erkannte Spalten werden
 * ignoriert):
 *
 *   Name              Freitext-Zahler – alternativ „Konto" für ein Nextcloud-Konto
 *   Konto             Nextcloud-Benutzername (optional)
 *   Vorname           Person mit genau diesen Feldern (Nachname Pflicht)
 *   Nachname          – ohne Vorname-Spalte bleibt „Nachname" ein Alias für „Name"
 *   Organisation      Name einer Organisation (auch „Firma", „Verein") – gefüllt
 *                     ⇒ Mitgliedstyp Organisation, geht Vorname/Nachname/Name vor
 *   Mitgliedsnummer   optional, harter Dublettenschlüssel (Issue #69)
 *   Eintritt          Datum des Beitritts, darf in der Vergangenheit liegen;
 *                     leer ⇒ Importtag (setzt der Service)
 *   Straße, PLZ, Ort, Telefon   Freitext, getrimmt, leer ⇒ null
 *   E-Mail            für die SEPA-Vorankündigung (optional, aber dringend empfohlen)
 *   IBAN              ohne IBAN entsteht kein Mandat, sondern nur eine Zuweisung
 *   BIC               optional, seit IBAN-only fast nie nötig
 *   Kontoinhaber      optional, sonst Anzeigename des Mitglieds (Issue #69)
 *   Mandat am         Unterschriftsdatum des Mandats – vorhanden ⇒ das Mandat
 *                     wird beim Import sofort aktiviert (Issue #69)
 *   Mandatsreferenz   optional, freie Eingabe aus Fremdsystemen (Issue #69)
 *   Beitragsgruppe    Name einer bestehenden Beitragsgruppe – Pflicht, sobald
 *                     ein Betrag/Standardbeitrag greift (Issue #69)
 *   Betrag            „42,50" oder „42.50" – der MONATSBEITRAG der Zuweisung
 *                     (Spec §3.3 „Der Monatsbeitrag ist das Atom"), unabhängig
 *                     vom Turnus (Issue #69, siehe MemberImportService)
 *   Frequenz          monatlich / vierteljährlich / halbjährlich / jährlich
 *                     (bestimmt nur den Turnus, nicht den Betrag)
 *   Start             Beginn der Zuweisung (validFrom). Dass er nicht in der
 *                     Vergangenheit liegen darf, prüft MemberImportService (der
 *                     Parser kennt kein „heute").
 *
 * Namensregeln je Zeile, in dieser Reihenfolge: (a) Organisation gefüllt ⇒
 * Organisation; (b) Vorname und/oder Nachname gefüllt ⇒ Person mit genau diesen
 * Feldern (ohne Nachname ein Zeilenfehler); (c) sonst Name bzw. Konto wie bisher –
 * ob das eine Person oder eine Organisation ist, entscheidet dann
 * MemberService::splitLabel() im Service.
 *
 * Eine Zeile ganz ohne Mandat/Beitrag ist gültig (Spec §3.1 „Zeile = ein
 * Mitglied mit zwei optionalen, atomaren Blöcken") – anders als vor Issue #69,
 * wo diese Klasse ausschließlich für die Kombi-Erfassung Mandat+Beitrag
 * gedacht war.
 *
 * @phpstan-type ParsedRow array{
 *     line:int, memberUid:?string, memberLabel:?string,
 *     memberType:?string, firstName:?string, lastName:?string, organizationName:?string,
 *     memberNumber:?string, joinedAt:?string, street:?string, postalCode:?string,
 *     city:?string, phone:?string,
 *     email:?string, iban:?string, bic:?string, accountHolder:?string,
 *     signedDate:?string, mandateReference:?string, groupName:?string,
 *     amountCents:?int, frequency:?string, startDate:?string,
 *     errors:string[], warnings:string[],
 * }
 */
class MemberCsvParser {

	use OptionalL10n;

	/**
	 * Spaltenüberschrift → Feld. Der Schlüssel ist bereits normalisiert
	 * (kleingeschrieben, ohne alles außer a–z).
	 */
	private const HEADERS = [
		'name' => 'memberLabel',
		'zahler' => 'memberLabel',
		'mitglied' => 'memberLabel',
		'nachname' => 'lastName',
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
		'surname' => 'lastName',
		'lastname' => 'lastName',
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

		// Neu seit Issue #69 (voller CSV-Import mit Mandats-/Zuweisungs-Block):
		// Mitgliedsnummer (harter Dublettenschlüssel), Kontoinhaber (weicht vom
		// Mitglied ab, z.B. Elternteil zahlt für Kind), Mandatsreferenz (freie
		// Eingabe aus Fremdsystemen) und Beitragsgruppe (löst die Zuweisung auf
		// eine bestehende Gruppe auf).
		'mitgliedsnummer' => 'memberNumber',
		'mitgliednummer' => 'memberNumber',
		'mitgliedsnr' => 'memberNumber',
		'membernumber' => 'memberNumber',
		'memberno' => 'memberNumber',
		'kontoinhaber' => 'accountHolder',
		'accountholder' => 'accountHolder',
		'mandatsreferenz' => 'mandateReference',
		'mandatreferenz' => 'mandateReference',
		'mandatsref' => 'mandateReference',
		'mandatereference' => 'mandateReference',
		'mandateref' => 'mandateReference',
		'beitragsgruppe' => 'groupName',
		'gruppe' => 'groupName',
		'contributiongroup' => 'groupName',
		'group' => 'groupName',

		// Namens- und Stammdatenspalten (Vorname/Nachname/Organisation statt
		// eines Freitextnamens, Anschrift, Telefon, Eintrittsdatum). „Nachname"
		// und seine Verwandten stehen weiter oben als 'lastName' – mapHeader()
		// macht daraus wieder einen Alias für 'memberLabel', solange die Datei
		// keine Vorname-Spalte hat (alte Dateien ändern ihr Ergebnis nicht).
		'vorname' => 'firstName',
		'firstname' => 'firstName',
		'familienname' => 'lastName',
		'organisation' => 'organizationName',
		'organization' => 'organizationName',
		'firma' => 'organizationName',
		'verein' => 'organizationName',
		'company' => 'organizationName',
		'strasse' => 'street',
		'street' => 'street',
		'address' => 'street',
		'plz' => 'postalCode',
		'postleitzahl' => 'postalCode',
		'zip' => 'postalCode',
		'postalcode' => 'postalCode',
		'ort' => 'city',
		'stadt' => 'city',
		'city' => 'city',
		'telefon' => 'phone',
		'tel' => 'phone',
		'phone' => 'phone',
		'eintritt' => 'joinedAt',
		'eintrittsdatum' => 'joinedAt',
		'mitgliedseit' => 'joinedAt',
		'beigetretenam' => 'joinedAt',
		'joined' => 'joinedAt',
		'joinedon' => 'joinedAt',
	];

	/**
	 * Höchstlängen der Mitglieder-Spalten (Migration Version000137): Eine längere
	 * Eingabe wird als Zeilenfehler gemeldet, nicht still gekürzt.
	 */
	private const MAX_LENGTHS = [
		'firstName' => 128,
		'lastName' => 128,
		'organizationName' => 255,
		'street' => 255,
		'postalCode' => 16,
		'city' => 128,
		'phone' => 64,
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

	public function __construct(
		private ?IL10N $l10n = null,
	) {
	}

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
			return ['rows' => [], 'error' => $this->msg('Die Datei ist leer.')];
		}

		$delimiter = $this->detectDelimiter($lines[0]);
		$header = $this->mapHeader(str_getcsv($lines[0], $delimiter, '"', '\\'));
		if ($header === []) {
			return ['rows' => [], 'error' => $this->msg('In der ersten Zeile wurde keine bekannte Spaltenüberschrift gefunden (erwartet z. B. Name, IBAN, Betrag).')];
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
		$warnings = [];
		$memberUid = ($raw['memberUid'] ?? '') !== '' ? $raw['memberUid'] : null;
		$memberLabel = ($raw['memberLabel'] ?? '') !== '' ? $raw['memberLabel'] : null;
		$firstName = ($raw['firstName'] ?? '') !== '' ? $raw['firstName'] : null;
		$lastName = ($raw['lastName'] ?? '') !== '' ? $raw['lastName'] : null;
		$organizationName = ($raw['organizationName'] ?? '') !== '' ? $raw['organizationName'] : null;

		// Namensregeln (siehe Klassendoc): Organisation vor Vorname/Nachname vor
		// Name/Konto. $memberType bleibt null, wenn nur Name/Konto vorliegen –
		// dann entscheidet MemberService::splitLabel() im Service.
		$memberType = null;
		if ($organizationName !== null) {
			$memberType = 'organisation';
			if ($firstName !== null || $lastName !== null) {
				$warnings[] = $this->msg('Bei einer Organisation werden Vorname und Nachname nicht übernommen – es zählt nur der Organisationsname.');
			}
			$firstName = $lastName = null;
			$memberLabel = null;
		} elseif ($firstName !== null || $lastName !== null) {
			$memberType = 'person';
			if ($lastName === null) {
				// „Name;Vorname" ist in deutschen Listen häufig, meint dort aber den
				// Nachnamen – raten wollen wir das nicht, sondern darauf hinweisen.
				$errors[] = $memberLabel !== null
					? $this->msg('Neben „Vorname" wird „Name" nicht als Nachname gelesen – bitte die Spalte „Nachname" verwenden.')
					: $this->msg('Bei einer Person ist der Nachname Pflicht.');
			}
			$memberLabel = null;
		} elseif ($memberUid !== null && $memberLabel !== null) {
			// Beide gesetzt ist kein Fehler des Nutzers, sondern eine typische
			// Tabelle: Anzeigename UND Kontoname. Das Konto gewinnt, der Name
			// ist dann redundant (den liefert Nextcloud selbst).
			$memberLabel = null;
		}
		if ($memberUid === null && $memberLabel === null && $memberType === null) {
			$errors[] = $this->msg('Weder Name noch Nextcloud-Konto angegeben.');
		}
		$lengthChecked = ['firstName' => $firstName, 'lastName' => $lastName, 'organizationName' => $organizationName];

		$email = ($raw['email'] ?? '') !== '' ? $raw['email'] : null;
		if ($email !== null && !EmailValidator::isValid($email)) {
			$errors[] = $this->msg('Keine gültige E-Mail-Adresse: %s', [$email]);
			$email = null;
		}

		// Anschrift und Telefon: Freitext, nur getrimmt (der Rohwert ist es schon).
		$street = ($raw['street'] ?? '') !== '' ? $raw['street'] : null;
		$postalCode = ($raw['postalCode'] ?? '') !== '' ? $raw['postalCode'] : null;
		$city = ($raw['city'] ?? '') !== '' ? $raw['city'] : null;
		$phone = ($raw['phone'] ?? '') !== '' ? $raw['phone'] : null;
		$lengthChecked += ['street' => $street, 'postalCode' => $postalCode, 'city' => $city, 'phone' => $phone];
		foreach ($lengthChecked as $field => $value) {
			if ($value !== null && mb_strlen($value) > self::MAX_LENGTHS[$field]) {
				$errors[] = $this->msg('%1$s ist zu lang (höchstens %2$d Zeichen).', [$this->fieldLabel($field), self::MAX_LENGTHS[$field]]);
			}
		}

		$joinedAt = null;
		if (($raw['joinedAt'] ?? '') !== '') {
			$joinedAt = $this->parseDate($raw['joinedAt']);
			if ($joinedAt === null) {
				$errors[] = $this->msg('Unlesbares Eintrittsdatum: %s', [$raw['joinedAt']]);
			}
		}

		$memberNumber = ($raw['memberNumber'] ?? '') !== '' ? $raw['memberNumber'] : null;
		$accountHolder = ($raw['accountHolder'] ?? '') !== '' ? $raw['accountHolder'] : null;
		$mandateReference = ($raw['mandateReference'] ?? '') !== '' ? $raw['mandateReference'] : null;
		$groupName = ($raw['groupName'] ?? '') !== '' ? $raw['groupName'] : null;

		$iban = ($raw['iban'] ?? '') !== '' ? strtoupper(str_replace(' ', '', $raw['iban'])) : null;
		$bic = ($raw['bic'] ?? '') !== '' ? strtoupper(str_replace(' ', '', $raw['bic'])) : null;

		$signedDate = null;
		if (($raw['signedDate'] ?? '') !== '') {
			$signedDate = $this->parseDate($raw['signedDate']);
			if ($signedDate === null) {
				$errors[] = $this->msg('Unlesbares Mandatsdatum: %s', [$raw['signedDate']]);
			}
		} elseif ($iban !== null) {
			// Das Unterschriftsdatum wandert als DtOfSgntr in jede Einreichung
			// und ist der Nachweis, dass es das Mandat gibt. Ohne Datum kein
			// Mandat – hier zu raten wäre in der Sache falsch.
			$errors[] = $this->msg('Zu einer IBAN gehört das Datum, an dem das Mandat unterschrieben wurde.');
		}

		$amountCents = null;
		$usedDefaultAmount = false;
		if (($raw['amount'] ?? '') !== '') {
			$amountCents = $this->parseAmount($raw['amount']);
			// 0 ist ein gültiger Betrag: beitragsfrei (Ehren-, Passiv- und Fördermitglieder, Pausen).
			if ($amountCents === null || $amountCents < 0) {
				$errors[] = $this->msg('Unlesbarer oder negativer Betrag: %s', [$raw['amount']]);
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
				$errors[] = $this->msg('Unbekannte Zahlungsfrequenz: %s', [$raw['frequency']]);
			}
		} elseif ($usedDefaultAmount) {
			$frequency = $defaultFrequency ?? 'yearly';
		} elseif ($amountCents !== null && $amountCents > 0) {
			// Ein Betrag ohne Frequenz ist fast immer ein Jahresbeitrag; das ist
			// die häufigste Vereinstabelle überhaupt. Bei einer beitragsfreien Zeile
			// bleibt die Frequenz offen: es wird nichts eingezogen, der Turnus der
			// Gruppe genügt (MemberImportService).
			$frequency = 'yearly';
		}

		$startDate = null;
		if (($raw['startDate'] ?? '') !== '') {
			$startDate = $this->parseDate($raw['startDate']);
			if ($startDate === null) {
				$errors[] = $this->msg('Unlesbares Startdatum: %s', [$raw['startDate']]);
			}
		} elseif ($amountCents !== null && $amountCents > 0) {
			// Beitragsfrei (0 €): keine erste Fälligkeit, also auch kein Pflicht-Startdatum
			// (die Zuweisung beginnt dann am Importtag, MemberImportService).
			$errors[] = $this->msg('Zu einem Betrag gehört ein Startdatum (erste Fälligkeit).');
		}

		// Seit Issue #69 (voller CSV-Import) ist eine Zeile ganz ohne Mandat und
		// ohne Beitrag ausdrücklich zulässig – „Zeile = ein Mitglied mit zwei
		// optionalen, atomaren Blöcken" (Spec §3.1). Vorher (reine Mandat+Beitrag-
		// Kombi-Erfassung) war das ein Fehler; ein bloßes Stammdaten-Mitglied
		// gehörte damals noch nicht zum Funktionsumfang dieser Klasse.
		//
		// Ob $groupName zu einer bestehenden Beitragsgruppe passt (oder überhaupt
		// gesetzt sein muss), kann diese von der Datenbank unabhängige Klasse
		// nicht entscheiden – das prüft MemberImportService, mit einem
		// nachsichtigen Fallback (genau eine Gruppe vorhanden ⇒ diese verwenden)
		// statt eines harten Parser-Fehlers, der jede der zahlreichen bereits
		// bestehenden Vorlagen ohne Beitragsgruppen-Spalte ablehnen würde.

		return [
			'line' => $line,
			'memberUid' => $memberUid,
			'memberLabel' => $memberLabel,
			'memberType' => $memberType,
			'firstName' => $firstName,
			'lastName' => $lastName,
			'organizationName' => $organizationName,
			'memberNumber' => $memberNumber,
			'joinedAt' => $joinedAt,
			'street' => $street,
			'postalCode' => $postalCode,
			'city' => $city,
			'phone' => $phone,
			'email' => $email,
			'iban' => $iban,
			'bic' => $bic,
			'accountHolder' => $accountHolder,
			'signedDate' => $signedDate,
			'mandateReference' => $mandateReference,
			'groupName' => $groupName,
			'amountCents' => $amountCents,
			'frequency' => $frequency,
			'startDate' => $startDate,
			'errors' => $errors,
			'warnings' => $warnings,
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
		// Ohne Vorname-Spalte ist „Nachname" (wie vor der Einführung der
		// Vorname-/Nachname-Spalten) nur eine weitere Schreibweise für „Name":
		// alte Dateien liefern so dasselbe Ergebnis wie vorher.
		if (!in_array('firstName', $header, true)) {
			foreach ($header as $index => $field) {
				if ($field === 'lastName') {
					$header[$index] = 'memberLabel';
				}
			}
		}
		return $header;
	}

	/** Spaltenbezeichnung für Fehlermeldungen (als Literal, damit sie übersetzt werden kann). */
	private function fieldLabel(string $field): string {
		return match ($field) {
			'firstName' => $this->msg('Vorname'),
			'lastName' => $this->msg('Nachname'),
			'organizationName' => $this->msg('Organisation'),
			'street' => $this->msg('Straße'),
			'postalCode' => $this->msg('PLZ'),
			'city' => $this->msg('Ort'),
			default => $this->msg('Telefon'),
		};
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
