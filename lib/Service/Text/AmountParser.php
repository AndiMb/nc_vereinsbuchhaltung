<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Text;

/**
 * Liest Geldbeträge aus Importdateien in Cent (Review 2026-11-18,
 * Backend 4 / Modularität 3).
 *
 * Vorher hatte jeder Import seinen eigenen Betragsleser, und dieselbe Angabe
 * „1.234" ergab je nach Weg 1,23 € (CSV-Kontoauszug, Mitglieder-CSV, XBUC,
 * CAMT.053) oder 1.234,00 € (PayPal, MT940). Hier stehen die Regeln einmal:
 *
 * **XML** ({@see xml()}, CAMT.053): der Wert ist ein xs:decimal – nur der
 * Punkt als Dezimaltrenner, keine Tausendertrenner, kein Währungszeichen.
 * „1.234" ist dort 1,234 und wird auf 1,23 € gerundet.
 *
 * **Text und CSV** ({@see text()}, {@see germanText()}):
 * - Kommen Punkt und Komma vor, ist das zuletzt stehende Zeichen der
 *   Dezimaltrenner und das andere der Tausendertrenner („1.234,56" wie
 *   „1,234.56"). Die Tausendergruppen müssen dann dreistellig sein.
 * - Nur Komma: Dezimalkomma („12,50"; „1,234" ist 1,234).
 * - Nur Punkt: Dezimalpunkt („1234.56") – außer die Quelle ist deutsch
 *   formatiert und die Punkte trennen nach der ersten Gruppe nur
 *   Dreierblöcke („1.234", „12.345.678"): dann sind es Tausenderpunkte. Ein
 *   deutscher Export schreibt einen Dezimalbetrag nie mit Punkt, wohl aber
 *   glatte Tausender ohne Nachkommastellen.
 * - Nachkommastellen dürfen fehlen: „60," (SWIFT-MT940, Format 15d: Komma
 *   Pflicht, Nachkommastellen nicht) und „12." sind ganze Euro, ebenso die
 *   Tabellenschreibweise „12,-" / „12,--" / „12,–" – der Strich direkt hinter
 *   dem Komma ist dort kein nachgestelltes Minus.
 * - Vorzeichen: „-" oder „+" vorn, das typografische Minus (U+2212), der
 *   Halbgeviertstrich (U+2013, den Excel und Word gern aus „-" machen) und
 *   das nachgestellte Minus mancher Bankexporte („12,50-"). Höchstens eines.
 * - Leerraum (auch geschützte und schmale Leerzeichen) zählt nicht; so sind
 *   auch „1 234,56" und „- 12,50" lesbar.
 * - Höchstens eine Währungsangabe vorn oder hinten: ein Währungszeichen
 *   („€", „$") oder ein dreibuchstabiger Code („EUR55,00", „12,50 EUR").
 * - Alles andere ist kein Betrag: der Leser liefert dann null, statt wie
 *   früher fremde Zeichen still zu entfernen („ca. 12" ergab 12 €).
 *
 * Mehr als zwei Nachkommastellen werden kaufmännisch auf Cent gerundet
 * (halbe Cent vom Nullpunkt weg). Gerechnet wird auf der Ziffernfolge, nicht
 * mit float – sonst wiche 1,005 je nach Darstellung ab.
 */
final class AmountParser {

	/** Mehr Vorkommastellen passen nicht sicher als Cent in einen int. */
	private const MAX_INTEGER_DIGITS = 15;

	/** Ein Währungszeichen oder ein dreibuchstabiger Währungscode. */
	private const CURRENCY = '(?:\p{Sc}|[A-Za-z]{3})';

	private function __construct() {
	}

	/**
	 * Betrag aus XML (xs:decimal): optionales Vorzeichen, Ziffern, optional
	 * Punkt und Nachkommastellen.
	 *
	 * @return int|null Cent; null, wenn der Wert kein solcher Betrag ist
	 */
	public static function xml(string $value): ?int {
		if (preg_match('/^([-+]?)(\d+)(?:\.(\d+))?$/D', trim($value), $m) !== 1) {
			return null;
		}
		return self::cents($m[1] === '-', $m[2], $m[3] ?? '');
	}

	/** Betrag aus einer deutsch formatierten Text- oder CSV-Quelle (siehe Klassendoc). */
	public static function germanText(string $value): ?int {
		return self::text($value, true);
	}

	/**
	 * Betrag aus einer Text- oder CSV-Quelle (siehe Klassendoc).
	 *
	 * @param bool $germanSource die Quelle ist deutsch formatiert: reine
	 *                           Dreierblöcke hinter Punkten sind Tausender
	 * @return int|null Cent; null, wenn der Wert kein Betrag ist
	 */
	public static function text(string $value, bool $germanSource): ?int {
		// Leerraum aller Art entfernen (u. a. U+00A0, U+2007, U+2009, U+202F).
		// Scheitert die Regex an ungültigem UTF-8, ist es kein Betrag.
		$v = preg_replace('/[\s\x{00A0}\x{2007}\x{2009}\x{202F}]+/u', '', $value);
		if ($v === null || $v === '') {
			return null;
		}
		$c = self::CURRENCY;
		// „12,-" / „12,--" / „12,–" sind ganze Euro, kein nachgestelltes Minus.
		$v = (string)preg_replace('/,(?:-{1,2}|\x{2013}|\x{2014})(?=(?:' . $c . ')?$)/Du', ',', $v);
		if (preg_match('/^(' . $c . ')?([-+\x{2212}\x{2013}])?(' . $c . ')?(\d[\d.,]*)([-\x{2212}\x{2013}])?(' . $c . ')?$/Du', $v, $m, PREG_UNMATCHED_AS_NULL) !== 1) {
			return null;
		}
		[, $cur1, $sign1, $cur2, $number, $sign2, $cur3] = $m + [6 => null];
		if (count(array_filter([$cur1, $cur2, $cur3], static fn (?string $x): bool => $x !== null)) > 1) {
			return null;
		}
		if ($sign1 !== null && $sign2 !== null) {
			return null;
		}
		$negative = ($sign1 !== null && $sign1 !== '+') || $sign2 !== null;

		$split = self::splitNumber((string)$number, $germanSource);
		if ($split === null) {
			return null;
		}
		return self::cents($negative, $split[0], $split[1]);
	}

	/**
	 * Zerlegt die Ziffernfolge mit Punkten und Kommas in Vor- und
	 * Nachkommastellen nach den Regeln im Klassendoc.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private static function splitNumber(string $number, bool $germanSource): ?array {
		$hasDot = str_contains($number, '.');
		$hasComma = str_contains($number, ',');

		if ($hasDot && $hasComma) {
			// Der zuletzt stehende Trenner ist der Dezimaltrenner.
			$decimal = strrpos($number, '.') > strrpos($number, ',') ? '.' : ',';
			$thousands = $decimal === '.' ? ',' : '.';
			if (substr_count($number, $decimal) !== 1) {
				return null;
			}
			[$integer, $fraction] = explode($decimal, $number);
			$t = preg_quote($thousands, '/');
			if (preg_match('/^\d{1,3}(?:' . $t . '\d{3})+$/D', $integer) !== 1 || preg_match('/^\d*$/D', $fraction) !== 1) {
				return null;
			}
			return [str_replace($thousands, '', $integer), $fraction];
		}

		if ($hasComma) {
			// Nur Komma: Dezimalkomma, genau eines; Nachkommastellen dürfen fehlen.
			return preg_match('/^(\d+),(\d*)$/D', $number, $m) === 1 ? [$m[1], $m[2]] : null;
		}

		if ($hasDot) {
			// Nur Punkt: in deutscher Schreibweise sind reine Dreierblöcke
			// Tausender („1.234" = 1.234,00 €). Eine führende Null („0.500")
			// schreibt niemand vor einen Tausenderblock – das bleibt dezimal.
			if ($germanSource && preg_match('/^[1-9]\d{0,2}(?:\.\d{3})+$/D', $number) === 1) {
				return [str_replace('.', '', $number), ''];
			}
			return preg_match('/^(\d+)\.(\d*)$/D', $number, $m) === 1 ? [$m[1], $m[2]] : null;
		}

		return [$number, ''];
	}

	/**
	 * Cent aus Vor- und Nachkommastellen, kaufmännisch gerundet.
	 *
	 * @param string $integer nur Ziffern
	 * @param string $fraction nur Ziffern, auch leer
	 */
	private static function cents(bool $negative, string $integer, string $fraction): ?int {
		$integer = ltrim($integer, '0');
		if (strlen($integer) > self::MAX_INTEGER_DIGITS) {
			return null;
		}
		$cents = (int)$integer * 100 + (int)str_pad(substr($fraction, 0, 2), 2, '0');
		if (strlen($fraction) > 2 && $fraction[2] >= '5') {
			$cents++;
		}
		return $negative && $cents !== 0 ? -$cents : $cents;
	}
}
