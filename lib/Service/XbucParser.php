<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCP\IL10N;

/**
 * Parser für das XML-Exportformat von „zero Buchhaltung" (.xbuc).
 *
 * Liefert den Kontenbaum (flach, Eltern zuerst) und die Buchungen (Soll/Haben).
 * Ohne Pflicht-Abhängigkeit von Nextcloud – dadurch mit `new XbucParser()`
 * eigenständig testbar; $l10n ist optional und übersetzt die Fehlermeldungen,
 * sobald Nextclouds DI-Container eine echte IL10N bereitstellt.
 */
class XbucParser {

	public function __construct(
		private ?IL10N $l10n = null,
	) {
	}

	private function msg(string $text): string {
		return $this->l10n !== null ? $this->l10n->t($text) : $text;
	}

	/**
	 * @return array{accounts: array<int, array<string,mixed>>, bookings: array<int, array<string,mixed>>, costCenters: array<int, array{code:string, name:string}>, year: ?int}
	 * @throws \RuntimeException
	 */
	public function parse(string $content): array {
		$prev = libxml_use_internal_errors(true);
		$xml = simplexml_load_string($content);
		libxml_use_internal_errors($prev);
		if ($xml === false) {
			throw new \RuntimeException($this->msg('Die Datei konnte nicht als XML gelesen werden.'));
		}

		$projekt = $xml->Projekt;
		if (!$projekt) {
			throw new \RuntimeException($this->msg('Kein <Projekt>-Element gefunden – ist das eine zero-Buchhaltung-Datei?'));
		}

		$accounts = [];
		if ($projekt->Ordner_Konten) {
			foreach ($projekt->Ordner_Konten->Konto as $konto) {
				$this->walkAccount($konto, null, null, $accounts);
			}
		}

		// Set aller Kontonummern für die Auflösung von Soll/Haben
		$numbers = array_map(static fn ($a) => $a['number'], $accounts);
		// längste zuerst, damit "546 01 01" vor "546" greift
		usort($numbers, static fn ($a, $b) => strlen($b) <=> strlen($a));

		// Eigenkapital-/Eröffnungsbilanzkonten: Buchungen dagegen sind
		// Anfangsbestände. Das erste dient als Gegenkonto für einseitige
		// Buchungen (zero Buchhaltung lässt bei "Kontostand 01.01." das
		// Gegenkonto leer).
		$equityNumbers = [];
		$bestandNumbers = [];
		foreach ($accounts as $a) {
			if ($a['type'] === 'equity') {
				$equityNumbers[$a['number']] = true;
			}
			if (in_array($a['type'], ['asset', 'equity', 'liability'], true)) {
				$bestandNumbers[$a['number']] = true;
			}
		}
		$openingContra = array_key_first($equityNumbers);

		$bookings = [];
		if ($projekt->Ordner_Buchungen) {
			foreach ($projekt->Ordner_Buchungen->Buchungsgruppe as $gruppe) {
				foreach ($gruppe->Buchung as $b) {
					$booking = $this->parseBooking($b, $numbers, $openingContra, $equityNumbers, $bestandNumbers);
					if ($booking !== null) {
						$bookings[] = $booking;
					}
				}
			}
		}

		// nach Quell-ID aufsteigend für eine sinnvolle Buchungsnummern-Reihenfolge
		usort($bookings, static fn ($a, $b) => $a['sourceId'] <=> $b['sourceId']);

		$costCenters = [];
		if ($projekt->Ordner_Klassifizierung) {
			foreach ($projekt->Ordner_Klassifizierung->Klassifizierung as $k) {
				$code = trim($this->decode((string)$k->ID));
				$name = $this->decode((string)$k->Bezeichnung);
				if ($code !== '') {
					$costCenters[] = ['code' => mb_substr($code, 0, 8), 'name' => mb_substr($name !== '' ? $name : ('Auswertungsgruppe ' . $code), 0, 255)];
				}
			}
		}

		return [
			'accounts' => $accounts,
			'bookings' => $bookings,
			'costCenters' => $costCenters,
			'year' => $this->projectYear($projekt),
		];
	}

	/**
	 * Geschäftsjahr der Datei aus den Projekt-Attributen min__Datum/max__Datum.
	 * Nur wenn beide auf dasselbe Kalenderjahr zeigen, sonst null.
	 */
	private function projectYear(\SimpleXMLElement $projekt): ?int {
		$from = $this->parseDate((string)$projekt['min__Datum']);
		$to = $this->parseDate((string)$projekt['max__Datum']);
		if ($from === null || $to === null) {
			return null;
		}
		$yearFrom = (int)substr($from, 0, 4);
		$yearTo = (int)substr($to, 0, 4);
		return $yearFrom === $yearTo ? $yearFrom : null;
	}

	/**
	 * Rekursiver Durchlauf des Kontenbaums.
	 *
	 * @param array<int, array<string,mixed>> $out
	 */
	private function walkAccount(\SimpleXMLElement $konto, ?string $parentNumber, ?string $rootCategory, array &$out): void {
		$number = trim($this->decode((string)$konto->ID));
		$name = $this->decode((string)$konto->Bezeichnung);
		if ($number === '') {
			return;
		}

		// Kategorie = Name des obersten Gruppenkontos
		$isRoot = $parentNumber === null;
		if ($name === '' && $number === '0') {
			$name = 'Bestandskonten';
		}
		$category = $rootCategory;
		if ($isRoot) {
			$category = $name !== '' ? $name : ('Gruppe ' . $number);
		}
		if ($name === '') {
			$name = $isRoot ? $category : ('Konto ' . $number);
		}

		[$type, $isBank] = $this->classify($number, $name);

		$out[] = [
			'number' => mb_substr($number, 0, 20),
			'name' => mb_substr($name, 0, 255),
			'parentNumber' => $parentNumber,
			'type' => $type,
			'category' => $category !== null ? mb_substr($category, 0, 255) : null,
			'isBank' => $isBank,
		];

		foreach ($konto->Konto as $child) {
			$this->walkAccount($child, $number, $category, $out);
		}
	}

	/**
	 * @param string[] $numbers längste zuerst
	 * @param array<string,true> $equityNumbers Kontonummern der Eigenkapitalkonten
	 * @return array<string,mixed>|null
	 */
	private function parseBooking(\SimpleXMLElement $b, array $numbers, ?string $openingContra = null, array $equityNumbers = [], array $bestandNumbers = []): ?array {
		$date = $this->parseDate((string)$b['Datum']);
		$amount = $this->parseAmount((string)$b->Betrag_als_Zahl);
		if ($date === null || $amount === null) {
			return null;
		}
		$text = mb_substr($this->decode((string)$b->Buchungstext), 0, 255);
		$docRef = mb_substr($this->decode((string)$b->Belegnummer), 0, 64);
		$sollRaw = $this->decode((string)$b->Soll);
		$habenRaw = $this->decode((string)$b->Haben);
		[$sollNo, $sollName] = $this->resolveAccount($sollRaw, $numbers);
		[$habenNo, $habenName] = $this->resolveAccount($habenRaw, $numbers);

		// Genau eine Kontoseite leer? Zwei Fälle unterscheiden:
		//  a) Anfangsbestand ("Kontostand/KB 01.01." auf einem Bestandskonto) →
		//     fehlende Seite = Eröffnungsbilanzkonto (wie bisher).
		//  b) Reguläre Bankbewegung, deren Gegenkonto beim Erstellen der xbuc-Datei
		//     (z.B. aus CSV) nicht gesetzt wurde → als OFFENE Buchung übernehmen
		//     (openContra); der Import legt sie als unzugeordnete Bankbuchung an.
		if (($sollNo === null) xor ($habenNo === null)) {
			$presentNo = $sollNo ?? $habenNo;
			$presentSide = $sollNo !== null ? 'soll' : 'haben';
			$isOpening = $openingContra !== null
				&& $presentNo !== $openingContra
				&& isset($bestandNumbers[$presentNo])
				&& $this->looksLikeOpening($text);
			if ($isOpening) {
				if ($presentSide === 'soll') {
					$habenNo = $openingContra;
					$habenName = '';
				} else {
					$sollNo = $openingContra;
					$sollName = '';
				}
			} else {
				return [
					'sourceId' => (int)$b['ID'],
					'date' => $date,
					'text' => $text,
					'docRef' => $docRef,
					'amountCents' => $amount,
					'sollNumber' => $sollNo,
					'sollName' => $sollName,
					'habenNumber' => $habenNo,
					'habenName' => $habenName,
					'equitySide' => null,
					'openContra' => true,
					'presentSide' => $presentSide,
				];
			}
		}

		if ($sollNo === null || $habenNo === null) {
			return null;
		}

		// Anfangsbestand: eine Seite ist ein Eigenkapital-/EB-Konto.
		// equitySide = Seite, auf der das EB-Konto steht (null = normale Buchung).
		$equitySide = null;
		if (isset($equityNumbers[$habenNo])) {
			$equitySide = 'haben';
		} elseif (isset($equityNumbers[$sollNo])) {
			$equitySide = 'soll';
		}

		return [
			'sourceId' => (int)$b['ID'],
			'date' => $date,
			'text' => $text,
			'docRef' => $docRef,
			'amountCents' => $amount,
			'sollNumber' => $sollNo,
			'sollName' => $sollName,
			'habenNumber' => $habenNo,
			'habenName' => $habenName,
			'equitySide' => $equitySide,
		];
	}

	/** Text-Heuristik für Anfangsbestands-Buchungen (Kontostand/Kassenbestand/Eröffnung …). */
	private function looksLikeOpening(string $text): bool {
		$t = mb_strtolower($text);
		if (preg_match('/kontostand|kassenbestand|anfangsbestand|er[öo]ffnung|saldovortrag|vortrag/u', $t)) {
			return true;
		}
		return (bool)preg_match('/^(kb|eb)\b/u', $t);
	}

	/**
	 * Löst "111 01 Mitgliedsbeiträge" zu Nummer + Name auf (längster Präfix gewinnt).
	 *
	 * @param string[] $numbers längste zuerst
	 * @return array{0: ?string, 1: string}
	 */
	private function resolveAccount(string $raw, array $numbers): array {
		$raw = trim($raw);
		if ($raw === '') {
			return [null, ''];
		}
		foreach ($numbers as $n) {
			if ($raw === $n || str_starts_with($raw, $n . ' ')) {
				$name = trim(mb_substr($raw, strlen($n)));
				return [$n, $name];
			}
		}
		// Fallback: erstes Token als Nummer
		$parts = preg_split('/\s+/', $raw, 2);
		$num = mb_substr($parts[0], 0, 20);
		$name = isset($parts[1]) ? mb_substr($parts[1], 0, 255) : $raw;
		return [$num, $name];
	}

	/**
	 * Kontotyp + Bank-Flag bestimmen. Der Kontoname hat Vorrang vor der
	 * Nummern-Heuristik, damit auch Kontenrahmen ohne SCV-Nummernschema
	 * (z.B. 100=Bank, 200=Kasse, 300=Einnahmen) korrekt eingestuft werden.
	 *
	 * @return array{0: string, 1: bool}
	 */
	private function classify(string $number, string $name): array {
		$isBank = in_array($number, ['001', '002'], true);
		$lower = mb_strtolower($name);
		if (str_contains($lower, 'bankkonto') || str_contains($lower, 'girokonto') || str_contains($lower, 'sparbuch') || $lower === 'bank') {
			return ['asset', true];
		}
		if ($lower === 'kasse' || str_starts_with($lower, 'barkasse') || str_starts_with($lower, 'handkasse')) {
			// Kasse ist ein Geldkonto: nur Geldkonten kumulieren über Jahresgrenzen.
			return ['asset', true];
		}
		if (str_contains($lower, 'eröffnungsbilanz')) {
			return ['equity', $isBank];
		}
		// Keine weiteren Namensregeln: außer Geldkonten (Bank/Kasse) und dem
		// EB-Konto gibt es keine Sonderkonten; der Typ folgt der Nummer.
		return [$this->determineType($number), $isBank];
	}

	private function determineType(string $number): string {
		$first = $number[0] ?? '';
		if ($number === '000') {
			return 'equity';
		}
		// Bestandskonten 0xx und "offene Posten" 999
		if ($first === '0' || $number === '999') {
			return 'asset';
		}
		if (in_array($first, ['1', '2', '3'], true)) {
			return 'income';
		}
		return 'expense';
	}

	private function parseDate(?string $value): ?string {
		if ($value === null || trim($value) === '') {
			return null;
		}
		if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2,4})$/', trim($value), $m)) {
			$y = (int)$m[3];
			if ($y < 100) {
				$y += 2000;
			}
			return sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[1]);
		}
		return null;
	}

	private function parseAmount(?string $value): ?int {
		if ($value === null || trim($value) === '') {
			return null;
		}
		$v = trim($value);
		$negative = str_starts_with($v, '-');
		$v = preg_replace('/[^0-9,.]/', '', $v) ?? '';
		if ($v === '') {
			return null;
		}
		if (str_contains($v, ',')) {
			$v = str_replace('.', '', $v);
			$v = str_replace(',', '.', $v);
		}
		if (!is_numeric($v)) {
			return null;
		}
		$cents = (int)round(((float)$v) * 100);
		return $negative ? -abs($cents) : $cents;
	}

	/**
	 * Dekodiert die teils doppelt kodierten Entities (z.B. "&#246;" oder "#20;")
	 * und entfernt Steuerzeichen.
	 */
	private function decode(string $s): string {
		$s = preg_replace('/&?#(\d+);/', '&#$1;', $s) ?? $s;
		// Referenzen auf Steuerzeichen (&#0; bis &#31;, z.B. "#20;" als
		// Leerzeichen-Ersatz von zero Buchhaltung) zu Leerzeichen machen –
		// html_entity_decode lässt sie sonst als Literal stehen.
		$s = preg_replace('/&#(?:\d|[12]\d|3[01]);/', ' ', $s) ?? $s;
		$s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		// Steuerzeichen (außer Tab/Zeilenumbruch) entfernen
		$s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? $s;
		return trim($s);
	}
}
