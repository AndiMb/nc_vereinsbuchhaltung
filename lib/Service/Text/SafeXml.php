<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Text;

/**
 * Liest XML aus fremder Hand: Kontoauszüge, xbuc-Exporte, E-Rechnungen in
 * Belegen und Teile von XLSX-Dateien. Das einzige Tor für solches XML – jede
 * Grenze für präparierte Dateien steht hier, nicht beim einzelnen Parser.
 *
 * Abgelehnt wird:
 *
 * - jede Dokumenttyp-Deklaration. Keines der Formate braucht eine, und sie
 *   kann interne Entitäten definieren, die libxml beim Lesen eines Knotens
 *   (String-Cast) zu Hunderten Megabyte aufbläht – außerhalb von PHPs
 *   memory_limit, bis das Betriebssystem den Prozess beendet. LIBXML_NONET
 *   verhindert nur Netzzugriffe, nicht diese Expansion. Geprüft wird der
 *   geparste DOM-Baum, nicht der Text: eine Suche nach „<!DOCTYPE“ übersieht
 *   dieselbe Deklaration in UTF-16.
 * - ein Tag mit {@see MAX_ATTRIBUTES} oder mehr Attributwerten. libxml 2.9
 *   braucht dafür überlinear viel Zeit; 20 MiB reichen für Minuten. Die
 *   Prüfung läuft als linearer Byte-Scan vor libxml und setzt eine
 *   ASCII-kompatible Kodierung voraus; UTF-16 wird dafür gewandelt, alles
 *   andere ({@see ENCODINGS}) abgelehnt, denn libxml folgt auch einer
 *   Kodierungsangabe in einer fehlerhaften Deklaration.
 */
final class SafeXml {
	public const DOCTYPE_ERROR = 'DOCTYPE is not allowed';
	public const ATTRIBUTES_ERROR = 'Too many attributes in one element';
	public const ENCODING_ERROR = 'Unsupported encoding';

	/** Ab so vielen Attributwerten in einem Tag gilt ein Dokument als präpariert. */
	public const MAX_ATTRIBUTES = 256;

	/** Zulässige Kodierungen einer XML-Deklaration (alle ASCII-kompatibel oder UTF-16). */
	private const ENCODINGS = '/^(?:utf-?8|utf-?16(?:le|be)?|us-ascii|iso-8859-\d{1,2}|windows-125\d)$/i';

	/**
	 * @param int $options zusätzliche LIBXML_*-Optionen; LIBXML_NONET ist immer gesetzt,
	 *                     LIBXML_NOENT wird entfernt (es ließe externe Entitäten Dateien lesen)
	 * @param ?string $error bei null die erste Meldung von libxml oder eine der *_ERROR-Konstanten
	 */
	public static function load(string $content, int $options = 0, ?string &$error = null): ?\SimpleXMLElement {
		$error = self::scan($content);
		if ($error !== null) {
			return null;
		}
		$options = ($options | LIBXML_NONET) & ~LIBXML_NOENT;
		$previous = libxml_use_internal_errors(true);
		try {
			$dom = new \DOMDocument();
			if (!$dom->loadXML($content, $options)) {
				$first = libxml_get_errors()[0] ?? null;
				$error = $first !== null ? trim($first->message) : 'Document is not well-formed';
				return null;
			}
			if ($dom->doctype !== null) {
				$error = self::DOCTYPE_ERROR;
				return null;
			}
			$xml = simplexml_import_dom($dom);
			if ($xml === null) {
				$error = 'Document has no root element';
			}
			return $xml;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
	}

	/**
	 * Lineare Prüfungen vor libxml; null, wenn nichts dagegen spricht.
	 *
	 * Ein Fehlschlag von preg_match (Grenze erreicht) zählt als Ablehnung.
	 */
	private static function scan(string $content): ?string {
		if (trim($content) === '') {
			return 'Document is empty';
		}
		// UTF-16 (mit oder ohne BOM) enthält Nullbytes; für die Byte-Prüfung nach UTF-8 wandeln.
		if (str_contains($content, "\0")) {
			$content = mb_convert_encoding($content, 'UTF-8', str_starts_with($content, "\xFE\xFF") ? 'UTF-16BE' : 'UTF-16LE');
		}
		// Was nicht mit „<“ beginnt, ist kein XML in einer ASCII-kompatiblen Kodierung
		// (EBCDIC, UCS-4) – libxml würde es per Autoerkennung trotzdem lesen.
		if (preg_match('/\A(?:\xEF\xBB\xBF)?\s*</', $content) !== 1) {
			return 'Document does not start with <';
		}
		if (preg_match('/\A(?:\xEF\xBB\xBF)?\s*<\?xml[^>]*?encoding\s*=\s*["\']([^"\']*)["\']/i', $content, $m) === 1
			&& preg_match(self::ENCODINGS, $m[1]) !== 1) {
			return self::ENCODING_ERROR;
		}
		if (preg_match('/<[^<>"\']*+(?:"[^"]*+"[^<>"\']*+|\'[^\']*+\'[^<>"\']*+){' . self::MAX_ATTRIBUTES . '}/', $content) !== 0) {
			return self::ATTRIBUTES_ERROR;
		}
		return null;
	}
}
