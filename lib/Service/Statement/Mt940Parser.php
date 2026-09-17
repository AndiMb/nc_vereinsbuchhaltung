<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Statement;

use OCA\Vereinsbuchhaltung\Service\Sepa\DkReturnReasonCodes;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaPurposeFields;
use OCP\IL10N;

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

	/**
	 * $l10n ist bewusst optional: der Parser bleibt dadurch ohne laufende
	 * Nextcloud-Instanz mit `new Mt940Parser()` instanziierbar (siehe
	 * tests/unit/Mt940ParserTest.php), übersetzt seine Fehlermeldungen aber,
	 * sobald ihn Nextclouds DI-Container mit einer echten IL10N versorgt.
	 */
	public function __construct(
		private RowNormalizer $normalizer = new RowNormalizer(),
		private ?IL10N $l10n = null,
	) {
	}

	private function msg(string $text): string {
		return $this->l10n !== null ? $this->l10n->t($text) : $text;
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
			throw new \RuntimeException($this->msg('Die MT940-Datei enthält keine lesbaren Buchungen (:61:).'));
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

		// Manche Institute hängen bei einer Rücklastschrift Ursprungsbetrag/
		// Bankgebühr direkt an die Buchungszeile an, als "/OCMT/EUR55,00/CHGS/
		// EUR5,00/" im freien Rest hinter Betrag/Geschäftsvorfall (Spec §5:
		// "original_amount_cents ... :61:/OCMT/", "charges_cents ... :61:/CHGS/").
		// Andere Institute tragen dieselben Werte stattdessen als OAMT+/COAM+
		// im :86:-Verwendungszweck (siehe applyDetails()) - beide Quellen werden
		// unterstützt, da unklar ist, welche Form die tatsächlich verwendeten
		// Vereinskonten liefern (siehe Spec §5 Implementierungs-Hinweis).
		// Über das ganze Feld gesucht (nicht nur $line): Zusatzangaben können auf
		// einer Fortsetzungszeile des :61:-Felds stehen.
		$originalAmountCents = $this->extractSlashField($value, 'OCMT');
		$chargesCents = $this->extractSlashField($value, 'CHGS');

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
			// Scratch-Felder, ausschließlich für applyDetails()/sepaDetails()
			// gedacht - RowNormalizer::build() kennt nur die festen Schlüssel
			// oben plus 'sepaDetails' und ignoriert alles andere.
			'_returnOriginalAmountCents' => $originalAmountCents,
			'_returnChargesCents' => $chargesCents,
		];
	}

	/** "/OCMT/EUR55,00/" -> 5500 (Cent), oder null, wenn das Feld fehlt. */
	private function extractSlashField(string $text, string $field): ?int {
		if (!preg_match('/\/' . $field . '\/([A-Z]{3})?([\d.,]+)/', $text, $m)) {
			return null;
		}
		return SepaPurposeFields::amountCents($m[2]);
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
			// Instituten vor) – dann ist er der Verwendungszweck. Ohne
			// strukturierte Felder bleibt auch die SEPA-Detail-Erkennung leer
			// (referenzlose Formate laufen über den Text-Heuristik-Fallback in
			// der Import-Verarbeitung, siehe Spec §5).
			$row['purpose'] = trim($text) !== '' ? trim($text) : null;
			$row['sepaDetails'] = [];
			return $row;
		}

		$row['bookingText'] = $sub['00'] ?? null;
		// Geschäftsvorfallcode am :86:-Anfang (Spec §5 "MT940 GVC am
		// :86:-Anfang") - NICHT aus subfields() (siehe dortiger Kommentar: der
		// GVC landet dort als fehlgedeuteter Schlüssel '?NN', nicht gebraucht).
		$gvc = $this->leadingGvc($text);

		$purpose = '';
		foreach (array_keys($sub) as $key) {
			$n = (int)$key;
			// ?20–?29 und ?60–?63 sind die Verwendungszweckzeilen.
			if (($n >= 20 && $n <= 29) || ($n >= 60 && $n <= 63)) {
				$purpose .= $sub[$key];
			}
		}
		$purpose = trim($purpose);
		$row['purpose'] = $purpose !== '' ? $purpose : null;

		$name = trim(($sub['32'] ?? '') . ($sub['33'] ?? ''));
		$row['counterparty'] = $name !== '' ? $name : null;
		$row['counterpartyBic'] = $sub['30'] ?? null;
		$row['counterpartyIban'] = $sub['31'] ?? null;

		// SEPA-strukturierte Felder aus dem konkatenierten Verwendungszweck
		// (Spec §5 Implementierungs-Hinweis: "?20–29/?60–63 konkatenieren, dann
		// an SEPA-Präfixen splitten"). MT940 liefert höchstens eine
		// Detail-Zeile je Buchung (anders als camt, siehe Camt053Parser).
		$fields = SepaPurposeFields::parse($purpose);
		$endToEndId = $fields['EREF'] ?? null;
		$mandateReference = $fields['MREF'] ?? null;
		$batchReference = $fields['KREF'] ?? null;
		$originalAmountCents = $row['_returnOriginalAmountCents'] ?? SepaPurposeFields::amountCents($fields['OAMT'] ?? null);
		$chargesCents = $row['_returnChargesCents'] ?? SepaPurposeFields::amountCents($fields['COAM'] ?? null);
		unset($row['_returnOriginalAmountCents'], $row['_returnChargesCents']);

		// "ist Rückgabe" (Spec §5): GVC am :86:-Anfang, dieselben Kernwerte wie
		// bei camt (108/109 - der GVC-Katalog ist formatunabhängig derselbe,
		// Anlage 3 DFÜ-Abkommen).
		$isReturn = $gvc !== null && in_array($gvc, ['108', '109'], true);

		$hasAnyField = $endToEndId !== null || $mandateReference !== null || $batchReference !== null
			|| $originalAmountCents !== null || $chargesCents !== null || $isReturn;
		$row['sepaDetails'] = $hasAnyField ? [[
			'endToEndId' => $endToEndId,
			'mandateReference' => $mandateReference,
			// MT940 hat keinen eigenen Rückgabegrund-Text/-Code-Pfad außer dem
			// DK-Nummerncode - siehe applyDetails()-Aufrufer/Mt940Parser-weite
			// Reason-Code-Erkennung, die den Verwendungszweck nach ?34 absucht.
			'returnReasonCode' => $this->returnReasonCode($sub),
			'returnReasonText' => null,
			'originalAmountCents' => $originalAmountCents,
			'chargesCents' => $chargesCents,
			'gvc' => $gvc,
			'batchReference' => $batchReference,
			'amountCents' => $row['amountCents'],
			'isReturn' => $isReturn,
		]] : [];

		return $row;
	}

	/** GVC am Anfang von :86: (Spec §5), oder null ohne erkennbaren 3-stelligen Code. */
	private function leadingGvc(string $text): ?string {
		return preg_match('/^(\d{3})\?/', $text, $m) === 1 ? $m[1] : null;
	}

	/**
	 * DK-Retourencode `?34` (Textschlüsselergänzung, Anlage 3 DFÜ-Abkommen) auf
	 * den ISO-20022-Rückgabegrund übersetzt - "nur DK-Kernwerte 901–918, nie
	 * raten" (Spec §5): unbekannte/mehrdeutige Codes liefern bewusst `null`
	 * statt eines geratenen Werts, siehe {@see DkReturnReasonCodes}.
	 *
	 * @param array<string, string> $sub
	 */
	private function returnReasonCode(array $sub): ?string {
		$code = $sub['34'] ?? null;
		if ($code === null) {
			return null;
		}
		// Nur die ersten drei Ziffern zählen - ?34 kann laut Konvention weitere
		// Zusatzinformation anhängen.
		if (!preg_match('/^(\d{3})/', trim($code), $m)) {
			return null;
		}
		return DkReturnReasonCodes::translate($m[1]);
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
