<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\InvoiceBatch;

use OCA\Vereinsbuchhaltung\Service\EmailValidator;
use OCA\Vereinsbuchhaltung\Service\InvoiceCalculator;
use OCA\Vereinsbuchhaltung\Service\OptionalL10n;
use OCP\IL10N;

/**
 * Sammelrechnungen (#243, docs/superpowers/specs/2026-11-01-sammelrechnungen-design.md):
 * macht aus den Zeilen einer Tabelle Rechnungsgruppen mit Empfänger und
 * Positionen.
 *
 * Aufbau „eine Zeile pro Position“. Welche Zeilen zu einer Rechnung gehören:
 * 1. Spalte „Rechnung“ gefüllt → dieses Kennzeichen;
 * 2. sonst Name gefüllt → der Empfänger (Name + PLZ + Ort), auch wenn seine
 *    Zeilen nicht nebeneinander stehen;
 * 3. sonst (Name leer) → die Rechnung der vorigen Zeile.
 *
 * Kennt keine Datenbank: Vorlagen, Konten und Kostenstellen kommen als
 * Nachschlage-Tabellen herein. Die Positionen haben das Format, das
 * {@see \OCA\Vereinsbuchhaltung\Service\InvoiceService::createDraft()} erwartet.
 */
class BatchSheetParser {

	use OptionalL10n;

	/** Spalte => erkannte Überschriften (klein, ohne Leer- und Sonderzeichen, ß → ss). */
	private const COLUMNS = [
		'invoice' => ['rechnung', 'rechnungnr', 'rechnungsnr', 'rechnungsnummer', 'gruppe', 'invoice', 'group'],
		'name' => ['name', 'empfänger', 'empfaenger', 'recipient', 'rechnungsempfänger'],
		'name2' => ['name2', 'zusatz', 'namenszusatz', 'adresszusatz'],
		'kind' => ['art', 'typ', 'kind', 'type'],
		'street' => ['strasse', 'street', 'adresse', 'anschrift', 'strasseundhausnummer'],
		'postalCode' => ['plz', 'postleitzahl', 'zip', 'postalcode', 'postcode'],
		'city' => ['ort', 'stadt', 'wohnort', 'city'],
		'country' => ['land', 'country'],
		'email' => ['email', 'mail', 'emailadresse'],
		'customerNo' => ['kundennummer', 'kundennr', 'customerno', 'customernumber'],
		'item' => ['position', 'leistung', 'bezeichnung', 'artikel', 'item', 'positionsbezeichnung'],
		'description' => ['beschreibung', 'details', 'description'],
		'template' => ['vorlage', 'positionsvorlage', 'template'],
		'quantity' => ['menge', 'anzahl', 'quantity', 'qty'],
		'unit' => ['einheit', 'unit'],
		'price' => ['einzelpreis', 'preis', 'betrag', 'price', 'unitprice', 'amount'],
		'discount' => ['rabatt', 'discount'],
		'taxRate' => ['steuersatz', 'ust', 'mwst', 'umsatzsteuer', 'steuer', 'vatrate', 'taxrate'],
		'account' => ['erlöskonto', 'erloeskonto', 'konto', 'account', 'revenueaccount'],
		'costCenter' => ['kostenstelle', 'costcenter'],
		'deliveryDate' => ['leistungsdatum', 'datum', 'date', 'deliverydate', 'servicedate'],
	];

	/** Einheiten in Worten → UN/ECE-Code (InvoiceService::UNIT_CODES). */
	private const UNITS = [
		'stück' => 'C62', 'stk' => 'C62', 'st' => 'C62', 'stueck' => 'C62', 'piece' => 'C62', 'pieces' => 'C62', 'pcs' => 'C62', 'x' => 'C62',
		'stunde' => 'HUR', 'stunden' => 'HUR', 'std' => 'HUR', 'h' => 'HUR', 'hour' => 'HUR', 'hours' => 'HUR',
		'tag' => 'DAY', 'tage' => 'DAY', 'day' => 'DAY', 'days' => 'DAY',
		'monat' => 'MON', 'monate' => 'MON', 'month' => 'MON', 'months' => 'MON',
		'jahr' => 'ANN', 'jahre' => 'ANN', 'year' => 'ANN', 'years' => 'ANN',
		'pauschal' => 'LS', 'pauschale' => 'LS', 'psch' => 'LS', 'lumpsum' => 'LS',
		'km' => 'KMT', 'kilometer' => 'KMT',
		'm' => 'MTR', 'meter' => 'MTR',
		'm²' => 'MTK', 'm2' => 'MTK', 'qm' => 'MTK',
		'l' => 'LTR', 'liter' => 'LTR',
		'kg' => 'KGM', 'kilogramm' => 'KGM',
		'prozent' => 'P1', '%' => 'P1', 'percent' => 'P1',
	];
	private const UNIT_CODES = ['C62', 'HUR', 'DAY', 'MON', 'ANN', 'LS', 'KMT', 'MTR', 'MTK', 'LTR', 'KGM', 'P1'];

	private const KINDS = [
		'person' => 'person', 'privat' => 'person', 'privatperson' => 'person',
		'organisation' => 'organisation', 'organization' => 'organisation', 'firma' => 'organisation', 'verein' => 'organisation', 'unternehmen' => 'organisation', 'company' => 'organisation',
		'behörde' => 'behoerde', 'behoerde' => 'behoerde', 'amt' => 'behoerde', 'authority' => 'behoerde',
	];

	private const COUNTRIES = [
		'deutschland' => 'DE', 'germany' => 'DE', 'österreich' => 'AT', 'oesterreich' => 'AT', 'austria' => 'AT',
		'schweiz' => 'CH', 'switzerland' => 'CH', 'niederlande' => 'NL', 'netherlands' => 'NL', 'frankreich' => 'FR', 'france' => 'FR',
		'belgien' => 'BE', 'belgium' => 'BE', 'luxemburg' => 'LU', 'luxembourg' => 'LU', 'italien' => 'IT', 'italy' => 'IT',
		'polen' => 'PL', 'poland' => 'PL', 'tschechien' => 'CZ', 'dänemark' => 'DK', 'denmark' => 'DK',
	];

	/**
	 * @param array<string, array<string, mixed>> $templates Vorlagen, Schlüssel = Name klein
	 * @param array<int|string, int> $accounts Kontonummer => Konto-ID (PHP macht numerische Nummern zu int-Schlüsseln)
	 * @param array<string, int> $costCenters Code oder Name klein => Kostenstellen-ID
	 */
	public function __construct(
		private array $templates,
		private array $accounts,
		private array $costCenters,
		private ?IL10N $l10n = null,
	) {
	}

	/**
	 * @param array<int, list<string>> $rows Zeilennummer => Zellen; die erste Zeile ist der Kopf
	 * @return array{groups: list<array<string, mixed>>, errors: list<string>}
	 * @throws \InvalidArgumentException wenn Kopf oder Daten fehlen
	 */
	public function parse(array $rows): array {
		if ($rows === []) {
			throw new \InvalidArgumentException($this->msg('Die Tabelle ist leer.'));
		}
		$headerLine = array_key_first($rows);
		$map = $this->columnMap($rows[$headerLine]);
		if (!isset($map['name']) || (!isset($map['item']) && !isset($map['template']))) {
			throw new \InvalidArgumentException($this->msg('Die Tabelle braucht in der ersten Zeile mindestens die Spalten „Name“ und „Position“ (oder „Vorlage“).'));
		}
		unset($rows[$headerLine]);
		if ($rows === []) {
			throw new \InvalidArgumentException($this->msg('Die Tabelle enthält unter der Kopfzeile keine Daten.'));
		}

		/** @var array<string, array<string, mixed>> $groups */
		$groups = [];
		$errors = [];
		$previousKey = null;
		foreach ($rows as $lineNo => $cells) {
			$get = static fn (string $col): string => isset($map[$col]) ? trim((string)($cells[$map[$col]] ?? '')) : '';
			$name = $get('name');
			$invoiceKey = $get('invoice');
			if ($invoiceKey !== '') {
				$key = 'r:' . mb_strtolower($invoiceKey);
			} elseif ($name !== '') {
				$key = 'n:' . self::norm($name) . '|' . self::norm($get('postalCode')) . '|' . self::norm($get('city'));
			} elseif ($previousKey !== null) {
				$key = $previousKey;
			} else {
				$errors[] = $this->msg('Zeile %d: Kein Empfänger – in der ersten Zeile einer Rechnung muss ein Name stehen.', [$lineNo]);
				continue;
			}
			$previousKey = $key;

			if (!isset($groups[$key])) {
				$groups[$key] = ['key' => $key, 'lines' => [], 'recipient' => null, 'items' => [], 'errors' => [], 'warnings' => []];
			}
			$group = &$groups[$key];
			$group['lines'][] = $lineNo;

			if ($name !== '') {
				if ($group['recipient'] === null) {
					$group['recipient'] = $this->recipient($get, $lineNo, $group['errors']);
				} elseif (self::norm($name) !== self::norm((string)$group['recipient']['name'])) {
					$group['errors'][] = $this->msg('Zeile %1$d: Anderer Name als in der ersten Zeile dieser Rechnung („%2$s“).', [$lineNo, (string)$group['recipient']['name']]);
				}
			}

			$item = $this->item($get, $lineNo, $group['errors']);
			if ($item !== null) {
				$group['items'][] = $item;
			}
			unset($group);
		}

		$out = [];
		foreach ($groups as $group) {
			if ($group['recipient'] === null) {
				$group['errors'][] = $this->msg('Zeile %d: Kein Empfänger – in der ersten Zeile einer Rechnung muss ein Name stehen.', [$group['lines'][0]]);
				$group['recipient'] = self::emptyRecipient();
			}
			if ($group['items'] === [] && $group['errors'] === []) {
				$group['errors'][] = $this->msg('Zeile %d: Keine Position.', [$group['lines'][0]]);
			}
			$r = $group['recipient'];
			if ($r['street'] === null || $r['postalCode'] === null || $r['city'] === null) {
				$group['warnings'][] = $this->msg('Anschrift unvollständig – zum Festschreiben sind Straße, PLZ und Ort nötig.');
			}
			$group['totalCents'] = self::total($group['items']);
			$out[] = $group;
		}
		return ['groups' => $out, 'errors' => $errors];
	}

	/**
	 * @param list<string> $header
	 * @return array<string, int> Spalte => Index
	 */
	private function columnMap(array $header): array {
		$map = [];
		foreach ($header as $index => $title) {
			$normalized = self::headerKey((string)$title);
			foreach (self::COLUMNS as $col => $aliases) {
				if (!isset($map[$col]) && in_array($normalized, $aliases, true)) {
					$map[$col] = $index;
					break;
				}
			}
		}
		return $map;
	}

	/**
	 * @param callable(string): string $get
	 * @param list<string> $errors
	 * @return array<string, string|null>
	 */
	private function recipient(callable $get, int $lineNo, array &$errors): array {
		$blank = static fn (string $v): ?string => $v === '' ? null : $v;
		$kind = 'person';
		$kindText = $get('kind');
		if ($kindText !== '') {
			$kind = self::KINDS[mb_strtolower($kindText)] ?? null;
			if ($kind === null) {
				$errors[] = $this->msg('Zeile %1$d: Unbekannte Art „%2$s“ (Person, Organisation oder Behörde).', [$lineNo, $kindText]);
				$kind = 'person';
			}
		}
		$country = 'DE';
		$countryText = $get('country');
		if ($countryText !== '') {
			$lower = mb_strtolower($countryText);
			if (isset(self::COUNTRIES[$lower])) {
				$country = self::COUNTRIES[$lower];
			} elseif (preg_match('/^[A-Za-z]{2}$/', $countryText) === 1) {
				$country = strtoupper($countryText);
			} else {
				$errors[] = $this->msg('Zeile %1$d: Unbekanntes Land „%2$s“ (zweistelliger Ländercode, z. B. DE).', [$lineNo, $countryText]);
			}
		}
		$email = $get('email');
		if ($email !== '' && !EmailValidator::isValid($email)) {
			$errors[] = $this->msg('Zeile %1$d: Ungültige E-Mail-Adresse „%2$s“.', [$lineNo, $email]);
			$email = '';
		}
		return [
			'kind' => $kind,
			'name' => mb_substr($get('name'), 0, 255),
			'name2' => $blank(mb_substr($get('name2'), 0, 255)),
			'street' => $blank(mb_substr($get('street'), 0, 255)),
			'postalCode' => $blank(mb_substr($get('postalCode'), 0, 16)),
			'city' => $blank(mb_substr($get('city'), 0, 128)),
			'country' => $country,
			'email' => $blank($email),
			'customerNo' => $blank(mb_substr($get('customerNo'), 0, 64)),
		];
	}

	/** @return array<string, string|null> */
	private static function emptyRecipient(): array {
		return ['kind' => 'person', 'name' => '', 'name2' => null, 'street' => null, 'postalCode' => null, 'city' => null, 'country' => 'DE', 'email' => null, 'customerNo' => null];
	}

	/**
	 * Eine Position im Format von InvoiceService::createDraft(); null bei Fehler.
	 *
	 * @param callable(string): string $get
	 * @param list<string> $errors
	 * @return array<string, mixed>|null
	 */
	private function item(callable $get, int $lineNo, array &$errors): ?array {
		$before = count($errors);
		$fail = function (string $text, array $params = []) use (&$errors, $lineNo): void {
			$errors[] = $this->msg('Zeile %d:', [$lineNo]) . ' ' . $this->msg($text, $params);
		};

		$template = null;
		$templateName = $get('template');
		if ($templateName !== '') {
			$template = $this->templates[mb_strtolower($templateName)] ?? null;
			if ($template === null) {
				$fail('Die Vorlage „%s“ gibt es nicht.', [$templateName]);
			}
		}

		$name = $get('item');
		if ($name === '' && $template !== null) {
			$name = (string)$template['name'];
		}
		if ($name === '' && $template === null && $templateName === '') {
			$fail('Die Bezeichnung der Position fehlt.');
		}
		$description = $get('description');
		if ($description === '' && $template !== null) {
			$description = (string)($template['description'] ?? '');
		}

		$priceCents = null;
		$priceText = $get('price');
		if ($priceText !== '') {
			$price = self::number($priceText);
			if ($price === null) {
				$fail('„%s“ ist kein Preis.', [$priceText]);
			} else {
				$priceCents = (int)round($price * 100);
			}
		} elseif ($template !== null) {
			$priceCents = (int)$template['unitPriceCents'];
		} elseif ($templateName === '') {
			$fail('Der Einzelpreis fehlt.');
		}

		$quantityE4 = 10000;
		$quantityText = $get('quantity');
		if ($quantityText !== '') {
			$quantity = self::number($quantityText);
			if ($quantity === null || $quantity <= 0) {
				$fail('„%s“ ist keine gültige Menge.', [$quantityText]);
			} else {
				$quantityE4 = (int)round($quantity * 10000);
			}
		}

		$unitCode = $template !== null ? (string)($template['unitCode'] ?? 'C62') : 'C62';
		$unitText = $get('unit');
		if ($unitText !== '') {
			$code = in_array(strtoupper($unitText), self::UNIT_CODES, true) ? strtoupper($unitText) : (self::UNITS[mb_strtolower(rtrim($unitText, '.'))] ?? null);
			if ($code === null) {
				$fail('Unbekannte Einheit „%s“.', [$unitText]);
			} else {
				$unitCode = $code;
			}
		}

		$discountBp = 0;
		$discountText = $get('discount');
		if ($discountText !== '') {
			$discount = self::percent($discountText);
			if ($discount === null || $discount < 0 || $discount > 100) {
				$fail('„%s“ ist kein gültiger Rabatt.', [$discountText]);
			} else {
				$discountBp = (int)round($discount * 100);
			}
		}

		$tax = [];
		$taxText = $get('taxRate');
		if ($taxText !== '') {
			$rate = self::percent($taxText);
			if ($rate === null || $rate < 0 || $rate > 25) {
				$fail('„%s“ ist kein gültiger Steuersatz.', [$taxText]);
			} else {
				$rateBp = (int)round($rate * 100);
				$tax = $rateBp === 0 ? ['taxCategory' => 'E', 'taxRateBp' => 0] : ['taxCategory' => 'S', 'taxRateBp' => $rateBp];
			}
		} elseif ($template !== null && isset($template['taxCategory'])) {
			$tax = ['taxCategory' => (string)$template['taxCategory'], 'taxRateBp' => (int)($template['taxRateBp'] ?? 0)];
		}

		$accountId = $template !== null && isset($template['accountId']) ? (int)$template['accountId'] : null;
		$accountText = $get('account');
		if ($accountText !== '') {
			$accountId = $this->accounts[$accountText] ?? null;
			if ($accountId === null) {
				$fail('Das Erlöskonto %s gibt es nicht.', [$accountText]);
			}
		}

		$costCenterId = null;
		$costText = $get('costCenter');
		if ($costText !== '') {
			$costCenterId = $this->costCenters[mb_strtolower($costText)] ?? null;
			if ($costCenterId === null) {
				$fail('Die Kostenstelle „%s“ gibt es nicht.', [$costText]);
			}
		}

		$deliveryDate = null;
		$dateText = $get('deliveryDate');
		if ($dateText !== '') {
			$deliveryDate = self::date($dateText);
			if ($deliveryDate === null) {
				$fail('„%s“ ist kein gültiges Datum.', [$dateText]);
			}
		}

		if (count($errors) > $before) {
			return null;
		}
		return [
			'name' => mb_substr($name, 0, 255),
			'description' => $description === '' ? null : mb_substr($description, 0, 2000),
			'quantityE4' => $quantityE4,
			'unitCode' => $unitCode,
			'unitPriceCents' => $priceCents ?? 0,
			'discountBp' => $discountBp,
			'accountId' => $accountId,
			'costCenterId' => $costCenterId,
			'deliveryDate' => $deliveryDate,
		] + $tax;
	}

	/** Summe der Nettobeträge (ohne Steuer) für die Vorschau. @param list<array<string, mixed>> $items */
	private static function total(array $items): int {
		$sum = 0;
		foreach ($items as $item) {
			$sum += InvoiceCalculator::lineNet((int)$item['quantityE4'], (int)$item['unitPriceCents'], (int)$item['discountBp']);
		}
		return $sum;
	}

	/** Überschrift → Vergleichsschlüssel: klein, ß → ss, nur Buchstaben und Ziffern. */
	private static function headerKey(string $title): string {
		$title = str_replace('ß', 'ss', mb_strtolower(trim($title)));
		$title = (string)preg_replace('/\(.*?\)/u', '', $title);
		return (string)preg_replace('/[^\p{L}\p{N}]+/u', '', $title);
	}

	private static function norm(string $value): string {
		return (string)preg_replace('/\s+/u', ' ', mb_strtolower(trim($value)));
	}

	/**
	 * Zahl in deutscher oder englischer Schreibweise, mit oder ohne Währung.
	 * Stehen Punkt und Komma, ist das letzte von beiden das Dezimalzeichen;
	 * steht nur eines, ist es das Dezimalzeichen (XLSX speichert `120.5`).
	 * Ausnahme: mehrere Punkte (`1.234.567`) sind Tausenderpunkte.
	 */
	public static function number(string $text): ?float {
		$t = str_replace(["\u{00A0}", "\u{202F}", ' ', '€', 'EUR', 'eur'], '', trim($text));
		if ($t === '') {
			return null;
		}
		$lastComma = strrpos($t, ',');
		$lastDot = strrpos($t, '.');
		if ($lastComma !== false && $lastDot !== false) {
			$t = $lastComma > $lastDot ? str_replace(['.', ','], ['', '.'], $t) : str_replace(',', '', $t);
		} elseif ($lastComma !== false) {
			$t = substr_count($t, ',') > 1 ? str_replace(',', '', $t) : str_replace(',', '.', $t);
		} elseif ($lastDot !== false && substr_count($t, '.') > 1) {
			$t = str_replace('.', '', $t);
		}
		return preg_match('/^-?\d+(\.\d+)?([eE]-?\d+)?$/', $t) === 1 ? (float)$t : null;
	}

	/**
	 * Prozentangabe: `19`, `19 %`, `7,5`. Ohne Prozentzeichen gilt ein Wert
	 * zwischen 0 und 1 als Anteil, wie ihn eine als Prozent formatierte
	 * Excel-Zelle speichert (`0.19`).
	 */
	private static function percent(string $text): ?float {
		$hasSign = str_contains($text, '%');
		$value = self::number(str_replace('%', '', $text));
		if ($value === null) {
			return null;
		}
		return (!$hasSign && $value > 0 && $value < 1) ? $value * 100 : $value;
	}

	/** `TT.MM.JJJJ`, `TT.MM.JJ`, `JJJJ-MM-TT` (auch mit Uhrzeit) oder Excel-Seriennummer → `JJJJ-MM-TT`. */
	public static function date(string $text): ?string {
		$t = trim($text);
		if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})$/', $t, $m) === 1) {
			[$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
			if ($y < 100) {
				$y += 2000;
			}
		} elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T].*)?$/', $t, $m) === 1) {
			[$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
		} elseif (preg_match('/^\d{5}(\.0+)?$/', $t) === 1) {
			// Excel zählt Tage ab dem 30.12.1899 (mit dem historischen Schalttag-Fehler, der vor März 1900 liegt).
			return (new \DateTimeImmutable('1899-12-30'))->modify('+' . (int)$t . ' days')->format('Y-m-d');
		} else {
			return null;
		}
		return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
	}
}
