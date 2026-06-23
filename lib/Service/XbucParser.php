<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

/**
 * Parser für das XML-Exportformat von „zero Buchhaltung" (.xbuc).
 *
 * Liefert den Kontenbaum (flach, Eltern zuerst) und die Buchungen (Soll/Haben).
 * Keine Nextcloud-Abhängigkeiten – dadurch eigenständig testbar.
 */
class XbucParser {

	/**
	 * @return array{accounts: array<int, array<string,mixed>>, bookings: array<int, array<string,mixed>>, costCenters: array<int, array{code:string, name:string}>}
	 * @throws \RuntimeException
	 */
	public function parse(string $content): array {
		$prev = libxml_use_internal_errors(true);
		$xml = simplexml_load_string($content);
		libxml_use_internal_errors($prev);
		if ($xml === false) {
			throw new \RuntimeException('Die Datei konnte nicht als XML gelesen werden.');
		}

		$projekt = $xml->Projekt;
		if (!$projekt) {
			throw new \RuntimeException('Kein <Projekt>-Element gefunden – ist das eine zero-Buchhaltung-Datei?');
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

		$bookings = [];
		if ($projekt->Ordner_Buchungen) {
			foreach ($projekt->Ordner_Buchungen->Buchungsgruppe as $gruppe) {
				foreach ($gruppe->Buchung as $b) {
					$booking = $this->parseBooking($b, $numbers);
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
					$costCenters[] = ['code' => mb_substr($code, 0, 8), 'name' => mb_substr($name !== '' ? $name : ('Kostenstelle ' . $code), 0, 255)];
				}
			}
		}

		return ['accounts' => $accounts, 'bookings' => $bookings, 'costCenters' => $costCenters];
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

		$out[] = [
			'number' => mb_substr($number, 0, 20),
			'name' => mb_substr($name, 0, 255),
			'parentNumber' => $parentNumber,
			'type' => $this->determineType($number),
			'category' => $category !== null ? mb_substr($category, 0, 255) : null,
			'isBank' => in_array($number, ['001', '002'], true),
		];

		foreach ($konto->Konto as $child) {
			$this->walkAccount($child, $number, $category, $out);
		}
	}

	/**
	 * @param string[] $numbers längste zuerst
	 * @return array<string,mixed>|null
	 */
	private function parseBooking(\SimpleXMLElement $b, array $numbers): ?array {
		$date = $this->parseDate((string)$b['Datum']);
		$amount = $this->parseAmount((string)$b->Betrag_als_Zahl);
		if ($date === null || $amount === null) {
			return null;
		}
		$sollRaw = $this->decode((string)$b->Soll);
		$habenRaw = $this->decode((string)$b->Haben);
		[$sollNo, $sollName] = $this->resolveAccount($sollRaw, $numbers);
		[$habenNo, $habenName] = $this->resolveAccount($habenRaw, $numbers);
		if ($sollNo === null || $habenNo === null) {
			return null;
		}

		return [
			'sourceId' => (int)$b['ID'],
			'date' => $date,
			'text' => mb_substr($this->decode((string)$b->Buchungstext), 0, 255),
			'docRef' => mb_substr($this->decode((string)$b->Belegnummer), 0, 64),
			'amountCents' => $amount,
			'sollNumber' => $sollNo,
			'sollName' => $sollName,
			'habenNumber' => $habenNo,
			'habenName' => $habenName,
		];
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
		$s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		// Steuerzeichen (außer Tab/Zeilenumbruch) entfernen
		$s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? $s;
		return trim($s);
	}
}
