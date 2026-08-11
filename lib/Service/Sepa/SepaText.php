<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Sepa;

/**
 * Bereitet Freitexte für eine SEPA-Einreichungsdatei auf.
 *
 * SEPA lässt in Namen und Verwendungszwecken nur einen kleinen lateinischen
 * Zeichensatz zu (EPC-Regelwerk, „Latin character set"): Buchstaben, Ziffern
 * und `/ - ? : ( ) . , ' +` sowie das Leerzeichen. Ein „ä" oder „ß" ist darin
 * nicht enthalten – ausgerechnet für eine App, die deutsche Vereine adressiert,
 * ist das nicht der Sonderfall, sondern der Normalfall („Grün-Weiß", „Übungs-
 * leiter", „Fördermitglied"). Je nach Bank wird die Datei sonst komplett
 * abgewiesen oder der Name landet verstümmelt auf dem Kontoauszug des Zahlers.
 *
 * Deshalb wird umgeschrieben statt weggeworfen: Umlaute werden nach deutscher
 * Konvention aufgelöst (ä->ae, ß->ss), gängige Akzentbuchstaben verlieren ihren
 * Akzent, und alles danach noch Unerlaubte wird zu einem Leerzeichen. Ein Name
 * bleibt so lesbar, statt zu Fragezeichen zu zerfallen.
 *
 * Die Längen sind die Grenzen aus dem pain.008-Schema; zu langer Text wird
 * gekürzt, weil eine zu lange Angabe die ganze Datei ungültig macht.
 */
class SepaText {

	/** Max70Text – Name von Gläubiger und Zahler. */
	public const MAX_NAME = 70;
	/** Max140Text – unstrukturierter Verwendungszweck (Ustrd). */
	public const MAX_REMITTANCE = 140;
	/** Max35Text – Referenzen (MsgId, PmtInfId, EndToEndId, MndtId). */
	public const MAX_ID = 35;

	/**
	 * Der erlaubte Zeichensatz. Bewusst als Positivliste: eine Sperrliste
	 * müsste alle 150.000 Unicode-Zeichen kennen, die es nicht sein dürfen.
	 */
	private const ALLOWED = "/[^A-Za-z0-9\\/\\-?:().,'+ ]/u";

	/**
	 * Zuerst die deutschen Umlaute, danach der allgemeine Akzent-Abbau. Die
	 * Reihenfolge ist wichtig: „ü" muss zu „ue" werden, nicht zu „u".
	 */
	private const GERMAN = [
		'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
		'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
		'ß' => 'ss', 'ẞ' => 'SS',
	];

	private const ACCENTS = [
		'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a',
		'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e',
		'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i',
		'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'ō' => 'o',
		'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ū' => 'u',
		'ý' => 'y', 'ÿ' => 'y', 'ñ' => 'n', 'ç' => 'c',
		'æ' => 'ae', 'œ' => 'oe',
		'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Å' => 'A', 'Ā' => 'A',
		'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ē' => 'E',
		'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ī' => 'I',
		'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ø' => 'O', 'Ō' => 'O',
		'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ū' => 'U',
		'Ý' => 'Y', 'Ñ' => 'N', 'Ç' => 'C',
		'Æ' => 'AE', 'Œ' => 'OE',
		// Typografische Zeichen, die aus Textverarbeitungen mitkommen und sonst
		// als Leerzeichen endeten, obwohl es eine sinnvolle Entsprechung gibt.
		// Als Codepunkte notiert: die Anführungszeichen sehen im Editor fast
		// gleich aus, und zwei versehentlich gleiche Schlüssel fielen hier nicht
		// auf (der zweite überschriebe still den ersten).
		"\u{201E}" => '', "\u{201C}" => '', "\u{201D}" => '', "\u{00AB}" => '', "\u{00BB}" => '',
		"\u{201A}" => "'", "\u{2018}" => "'", "\u{2019}" => "'",
		"\u{2013}" => '-', "\u{2014}" => '-', "\u{2026}" => '.',
		'€' => 'EUR', '&' => '+',
	];

	/**
	 * Wandelt einen beliebigen Text in einen SEPA-tauglichen um und kürzt ihn
	 * auf $maxLength.
	 *
	 * @param int $maxLength eine der MAX_*-Konstanten dieser Klasse
	 */
	public static function convert(?string $value, int $maxLength): string {
		if ($value === null) {
			return '';
		}
		$converted = strtr($value, self::GERMAN);
		$converted = strtr($converted, self::ACCENTS);
		$converted = (string)preg_replace(self::ALLOWED, ' ', $converted);
		// Der Ersatz durch Leerzeichen erzeugt leicht Doppel- und Randleerzeichen.
		$converted = trim((string)preg_replace('/ {2,}/', ' ', $converted));
		return mb_substr($converted, 0, $maxLength);
	}

	/**
	 * Ein Name darf nicht leer in die Datei – das Schema verlangt ihn. Bleibt
	 * nach der Umschrift nichts übrig (etwa bei einem rein kyrillischen oder
	 * chinesischen Namen), tritt diese Ersatzangabe an seine Stelle: eine
	 * unvollständige Datei einreichen zu können ist besser als eine, die die
	 * Bank ohne Begründung zurückweist.
	 */
	public static function name(?string $value): string {
		$converted = self::convert($value, self::MAX_NAME);
		return $converted !== '' ? $converted : 'UNBEKANNT';
	}
}
