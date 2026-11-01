<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\InvoiceBatch;

/**
 * Liest eine Tabelle für Sammelrechnungen (#243): XLSX oder CSV, erkannt am
 * Inhalt, nicht am Dateinamen.
 *
 * XLSX ohne Bibliothek: eine XLSX-Datei ist ein ZIP mit XML. Gebraucht wird nur
 * das erste Tabellenblatt mit Text und Zahlen; für Formeln steht der zuletzt
 * berechnete Wert ohnehin in der Datei. Datumszellen kommen als Excel-
 * Seriennummer an, die Umrechnung macht der {@see BatchSheetParser} je Spalte.
 *
 * CSV in den Formen, die Excel und LibreOffice speichern: mit oder ohne BOM,
 * UTF-8 oder Windows-1252, Trennzeichen Semikolon, Komma oder Tab.
 *
 * Ergebnis: Zeilennummer im Blatt (ab 1) => Zellen als Text. Leere Zeilen
 * fehlen, damit Meldungen trotzdem die Zeile nennen, die der Nutzer sieht.
 *
 * Ohne Nextcloud-Abhängigkeiten; die Meldungen sind deutsche Quelltexte, die
 * der aufrufende Dienst übersetzt.
 */
class SpreadsheetReader {

	public const MAX_ROWS = 5000;
	public const MAX_COLUMNS = 100;
	/** Obergrenze je entpacktem XML-Teil (Schutz vor ZIP-Bomben). */
	private const MAX_PART_BYTES = 20 * 1024 * 1024;

	private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

	/**
	 * @return array<int, list<string>>
	 * @throws \InvalidArgumentException wenn die Datei nicht lesbar ist
	 */
	public static function read(string $content): array {
		if (trim($content) === '') {
			throw new \InvalidArgumentException('Die Datei ist leer.');
		}
		return str_starts_with($content, "PK\x03\x04") ? self::readXlsx($content) : self::readCsv($content);
	}

	// ------------------------------------------------------------------
	// XLSX
	// ------------------------------------------------------------------

	/** @return array<int, list<string>> */
	private static function readXlsx(string $content): array {
		if (!class_exists(\ZipArchive::class)) {
			throw new \InvalidArgumentException('Excel-Dateien lassen sich auf diesem Server nicht lesen (PHP-Erweiterung „zip“ fehlt). Bitte als CSV speichern.');
		}
		$tmp = tempnam(sys_get_temp_dir(), 'vbh-xlsx');
		if ($tmp === false) {
			throw new \InvalidArgumentException('Die Excel-Datei ließ sich nicht lesen.');
		}
		try {
			file_put_contents($tmp, $content);
			$zip = new \ZipArchive();
			if ($zip->open($tmp) !== true) {
				throw new \InvalidArgumentException('Die Excel-Datei ließ sich nicht lesen.');
			}
			try {
				$shared = self::sharedStrings($zip);
				$sheet = self::part($zip, self::firstSheetPath($zip));
				if ($sheet === null) {
					throw new \InvalidArgumentException('Die Excel-Datei enthält kein Tabellenblatt.');
				}
				return self::sheetRows($sheet, $shared);
			} finally {
				$zip->close();
			}
		} finally {
			@unlink($tmp);
		}
	}

	/** Inhalt eines ZIP-Teils als XML, null wenn es ihn nicht gibt. */
	private static function part(\ZipArchive $zip, string $name): ?\SimpleXMLElement {
		$stat = $zip->statName($name);
		if ($stat === false) {
			return null;
		}
		if ($stat['size'] > self::MAX_PART_BYTES) {
			throw new \InvalidArgumentException('Die Excel-Datei ist zu groß.');
		}
		$xml = $zip->getFromName($name);
		if ($xml === false) {
			return null;
		}
		$previous = libxml_use_internal_errors(true);
		try {
			// Ohne LIBXML_NOENT: Entitäten werden nicht ersetzt; LIBXML_NONET verbietet Netzzugriffe.
			$doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
		if ($doc === false) {
			throw new \InvalidArgumentException('Die Excel-Datei ließ sich nicht lesen.');
		}
		return $doc;
	}

	private static function mainNs(\SimpleXMLElement $doc): string {
		return $doc->getNamespaces()[''] ?? self::NS_MAIN;
	}

	/** Pfad des ersten Blatts laut Arbeitsmappe und deren Relationen. */
	private static function firstSheetPath(\ZipArchive $zip): string {
		$fallback = 'xl/worksheets/sheet1.xml';
		$workbook = self::part($zip, 'xl/workbook.xml');
		$rels = self::part($zip, 'xl/_rels/workbook.xml.rels');
		if ($workbook === null || $rels === null) {
			return $fallback;
		}
		$sheets = $workbook->children(self::mainNs($workbook))->sheets;
		if (!isset($sheets) || $sheets->count() === 0) {
			return $fallback;
		}
		$first = $sheets->children(self::mainNs($workbook))->sheet[0] ?? null;
		if ($first === null) {
			return $fallback;
		}
		$relNs = $workbook->getDocNamespaces(true)['r'] ?? self::NS_REL;
		$rid = (string)($first->attributes($relNs)['id'] ?? '');
		foreach ($rels->children(self::mainNs($rels)) as $rel) {
			$attrs = $rel->attributes();
			if ($attrs !== null && (string)$attrs['Id'] === $rid) {
				$target = (string)$attrs['Target'];
				return str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
			}
		}
		return $fallback;
	}

	/** @return list<string> */
	private static function sharedStrings(\ZipArchive $zip): array {
		$doc = self::part($zip, 'xl/sharedStrings.xml');
		if ($doc === null) {
			return [];
		}
		$ns = self::mainNs($doc);
		$out = [];
		foreach ($doc->children($ns)->si as $si) {
			$out[] = self::stringItem($si, $ns);
		}
		return $out;
	}

	/** Text eines <si>/<is>: direkter <t> oder Rich-Text-Läufe <r><t>; Lautschrift <rPh> fällt weg. */
	private static function stringItem(\SimpleXMLElement $item, string $ns): string {
		$text = '';
		foreach ($item->children($ns) as $name => $child) {
			if ($name === 't') {
				$text .= (string)$child;
			} elseif ($name === 'r') {
				$text .= (string)$child->children($ns)->t;
			}
		}
		return $text;
	}

	/**
	 * @param list<string> $shared
	 * @return array<int, list<string>>
	 */
	private static function sheetRows(\SimpleXMLElement $sheet, array $shared): array {
		$ns = self::mainNs($sheet);
		$data = $sheet->children($ns)->sheetData;
		$rows = [];
		$rowNo = 0;
		foreach ($data->children($ns)->row ?? [] as $row) {
			$rAttr = (string)($row->attributes()['r'] ?? '');
			$rowNo = $rAttr !== '' ? (int)$rAttr : $rowNo + 1;
			$cells = [];
			$col = -1;
			foreach ($row->children($ns)->c as $c) {
				$attrs = $c->attributes();
				$ref = (string)($attrs['r'] ?? '');
				$col = $ref !== '' ? self::columnIndex($ref) : $col + 1;
				if ($col >= self::MAX_COLUMNS) {
					continue;
				}
				$cells[$col] = self::cellValue($c, (string)($attrs['t'] ?? ''), $shared, $ns);
			}
			if ($cells === [] || implode('', array_map('trim', $cells)) === '') {
				continue;
			}
			if (count($rows) >= self::MAX_ROWS) {
				throw new \InvalidArgumentException('Die Tabelle hat zu viele Zeilen (höchstens 5000).');
			}
			$max = max(array_keys($cells));
			$line = [];
			for ($i = 0; $i <= $max; $i++) {
				$line[] = $cells[$i] ?? '';
			}
			$rows[$rowNo] = $line;
		}
		return $rows;
	}

	/** @param list<string> $shared */
	private static function cellValue(\SimpleXMLElement $c, string $type, array $shared, string $ns): string {
		$children = $c->children($ns);
		return match ($type) {
			's' => $shared[(int)(string)$children->v] ?? '',
			'inlineStr' => isset($children->is) ? self::stringItem($children->is, $ns) : '',
			'e' => '',
			default => trim((string)$children->v),
		};
	}

	/** `C7` → 2 (Spalten ab 0). */
	private static function columnIndex(string $ref): int {
		$letters = strtoupper((string)preg_replace('/[^A-Za-z]/', '', $ref));
		$index = 0;
		for ($i = 0, $n = strlen($letters); $i < $n; $i++) {
			$index = $index * 26 + (ord($letters[$i]) - 64);
		}
		return $index - 1;
	}

	// ------------------------------------------------------------------
	// CSV
	// ------------------------------------------------------------------

	/** @return array<int, list<string>> */
	private static function readCsv(string $content): array {
		if (str_starts_with($content, "\xEF\xBB\xBF")) {
			$content = substr($content, 3);
		}
		if (!mb_check_encoding($content, 'UTF-8')) {
			$content = (string)mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
		}
		$delimiter = self::detectDelimiter($content);
		$stream = fopen('php://temp', 'r+');
		if ($stream === false) {
			throw new \InvalidArgumentException('Die Datei ließ sich nicht lesen.');
		}
		fwrite($stream, $content);
		rewind($stream);
		$rows = [];
		$lineNo = 0;
		while (($fields = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
			$lineNo++;
			$cells = array_map(static fn ($v): string => trim((string)$v), array_slice($fields, 0, self::MAX_COLUMNS));
			if (implode('', $cells) === '') {
				continue;
			}
			if (count($rows) >= self::MAX_ROWS) {
				fclose($stream);
				throw new \InvalidArgumentException('Die Tabelle hat zu viele Zeilen (höchstens 5000).');
			}
			$rows[$lineNo] = $cells;
		}
		fclose($stream);
		return $rows;
	}

	/** Das häufigste Trennzeichen der ersten nicht leeren Zeile. */
	private static function detectDelimiter(string $content): string {
		foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
			if (trim($line) === '') {
				continue;
			}
			$counts = [';' => substr_count($line, ';'), "\t" => substr_count($line, "\t"), ',' => substr_count($line, ',')];
			arsort($counts);
			$best = array_key_first($counts);
			return $counts[$best] > 0 ? (string)$best : ';';
		}
		return ';';
	}
}
