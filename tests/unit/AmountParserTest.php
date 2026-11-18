<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Text\AmountParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Der eine Betragsleser aller Importe (Review 2026-11-18, Backend 4 /
 * Modularität 3). Vorher hatte jedes Format seine eigene Regel, und „1.234"
 * ergab je nach Importweg 1,23 € oder 1.234,00 €.
 */
class AmountParserTest extends TestCase {

	/** @return array<string, array{0: string, 1: ?int}> */
	public static function xmlFaelle(): array {
		return [
			'ganze Zahl' => ['1234', 123400],
			'Dezimalpunkt' => ['1234.56', 123456],
			'eine Nachkommastelle' => ['12.5', 1250],
			'kleiner Betrag' => ['0.01', 1],
			'null' => ['0.00', 0],
			'Leerraum außen' => ["  42.10\n", 4210],
			'Minus' => ['-12.50', -1250],
			'Plus' => ['+12.50', 1250],
			'„1.234" ist im XML ein Dezimalpunkt' => ['1.234', 123],
			'mehr Nachkommastellen, kaufmännisch gerundet' => ['1.005', 101],
			'abgerundet' => ['1.004', 100],
			'Minus gerundet' => ['-1.005', -101],
			'Komma gibt es im XML nicht' => ['12,50', null],
			'Tausendertrenner gibt es im XML nicht' => ['1,234.56', null],
			'kein Währungszeichen' => ['EUR12.50', null],
			'keine Exponentenschreibweise' => ['1e3', null],
			'kein Hex' => ['0x1A', null],
			'leer' => ['', null],
			'nur Punkt' => ['.', null],
			'Punkt ohne Ziffer davor' => ['.5', null],
			'Punkt ohne Ziffer danach' => ['5.', null],
			'zwei Vorzeichen' => ['--5', null],
			'zu groß für Cent in int' => ['12345678901234567', null],
		];
	}

	#[DataProvider('xmlFaelle')]
	public function testXml(string $value, ?int $expected): void {
		$this->assertSame($expected, AmountParser::xml($value));
	}

	/**
	 * Gemeinsame Regeln aller Text- und CSV-Formate, deutsch formatiert oder nicht.
	 *
	 * @return array<string, array{0: string, 1: ?int}>
	 */
	public static function textFaelle(): array {
		return [
			// beide Trenner: der letzte ist der Dezimaltrenner
			'deutsch mit Tausenderpunkt' => ['1.234,56', 123456],
			'englisch mit Tausenderkomma' => ['1,234.56', 123456],
			'mehrere Tausendergruppen deutsch' => ['12.345.678,90', 1234567890],
			'mehrere Tausendergruppen englisch' => ['12,345,678.90', 1234567890],
			'Tausendergruppe nicht dreistellig' => ['1.23,45', null],
			'Tausendergruppe nicht dreistellig englisch' => ['12,34.56', null],
			'Dezimaltrenner zweimal' => ['1.234,56,7', null],
			'Trenner vertauscht gemischt' => ['1,234.567,89', null],
			// nur Komma: Dezimalkomma
			'Dezimalkomma' => ['12,50', 1250],
			'Dezimalkomma ohne Tausender' => ['1234,56', 123456],
			'Komma mit drei Nachkommastellen bleibt Dezimalkomma' => ['1,234', 123],
			'zwei Kommas ohne Punkt' => ['1,234,567', null],
			// Ganze Euro ohne Nachkommastellen (Review-Nachtrag C-1): SWIFT-MT940
			// schreibt „60," (Format 15d: Komma Pflicht, Nachkommastellen nicht),
			// deutsche Tabellen „12,-" oder „12,--".
			'Komma ohne Nachkommastellen (MT940)' => ['60,', 6000],
			'großer Betrag ohne Nachkommastellen' => ['1000,', 100000],
			'Währungscode und Komma ohne Nachkommastellen' => ['EUR1000,', 100000],
			'Tausenderpunkt und Komma ohne Nachkommastellen' => ['1.000,', 100000],
			'Strich für ganze Euro' => ['12,-', 1200],
			'Doppelstrich für ganze Euro' => ['50,--', 5000],
			'Halbgeviertstrich für ganze Euro' => ["12,\u{2013}", 1200],
			'Strich für ganze Euro mit Währung' => ['12,- €', 1200],
			'Strich für ganze Euro, negativ' => ['-12,-', -1200],
			'Punkt ohne Nachkommastellen' => ['12.', 1200],
			// nur Punkt, keine Dreiergruppen: Dezimalpunkt
			'Dezimalpunkt' => ['1234.56', 123456],
			'Dezimalpunkt eine Stelle' => ['12.5', 1250],
			'Dezimalpunkt mit führender Null' => ['0.123', 12],
			'führende Null ist keine Tausendergruppe' => ['0.500', 50],
			// ganze Zahlen
			'ganze Zahl' => ['42', 4200],
			'null' => ['0', 0],
			'minus null' => ['-0,00', 0],
			// Vorzeichen
			'Minus' => ['-1.234,56', -123456],
			'Plus' => ['+12,50', 1250],
			'Minus hinten (Bankexport)' => ['12,50-', -1250],
			'typografisches Minus' => ["\u{2212}12,50", -1250],
			'Minus mit Leerraum' => ['- 12,50', -1250],
			// Excel und Word machen aus „-" gern einen Halbgeviertstrich.
			'Halbgeviertstrich als Minus' => ["\u{2013}12,50", -1250],
			'Halbgeviertstrich hinten als Minus' => ["12,50\u{2013}", -1250],
			'zwei Vorzeichen' => ['-12,50-', null],
			'Plus hinten gibt es nicht' => ['12,50+', null],
			// Leerraum
			'Leerraum außen' => ["\t 12,50 \r\n", 1250],
			'geschütztes Leerzeichen als Tausendertrenner' => ["1\u{00A0}234,56", 123456],
			'schmales geschütztes Leerzeichen' => ["1\u{202F}234,56", 123456],
			'Leerzeichen als Tausendertrenner' => ['1 234,56', 123456],
			// Währung
			'Euro-Zeichen hinten' => ['12,50 €', 1250],
			'Euro-Zeichen vorn' => ['€ 12,50', 1250],
			'Währungscode hinten' => ['1.234,56 EUR', 123456],
			'Währungscode vorn ohne Abstand (SEPA-Verwendungszweck)' => ['EUR55,00', 5500],
			'Währungscode vor dem Minus' => ['EUR -12,50', -1250],
			'Minus vor dem Währungszeichen' => ['-€12,50', -1250],
			'Dollar' => ['$12.50', 1250],
			'zwei Währungsangaben' => ['EUR 12,50 EUR', null],
			// Rundung
			'drei Nachkommastellen aufgerundet' => ['0,125', 13],
			'drei Nachkommastellen abgerundet' => ['0,124', 12],
			'negativ aufgerundet' => ['-0,125', -13],
			// ungültig
			'leer' => ['', null],
			'nur Leerraum' => ['   ', null],
			'Text' => ['abc', null],
			'Zahl mit Text' => ['ca. 12,50', null],
			'Komma ohne Ziffer davor' => [',50', null],
			'nur Komma' => [',', null],
			'Soll-Kennzeichen' => ['12,50 S', null],
			'Haben-Kennzeichen' => ['H 12,50', null],
			'Exponent' => ['1e3', null],
			'zu groß für Cent in int' => ['12345678901234567', null],
			'kaputtes UTF-8' => ["12,50 \xC3\x28", null],
		];
	}

	#[DataProvider('textFaelle')]
	public function testTextDeutsch(string $value, ?int $expected): void {
		$this->assertSame($expected, AmountParser::germanText($value));
	}

	#[DataProvider('textFaelle')]
	public function testTextOhneDeutscheHerkunft(string $value, ?int $expected): void {
		$this->assertSame($expected, AmountParser::text($value, false));
	}

	/**
	 * Der einzige Unterschied: nur Punkte, die Ziffern hinter der ersten
	 * Gruppe in Dreierblöcken trennen. Aus einer deutsch formatierten Quelle
	 * sind das Tausenderpunkte, sonst ein Dezimalpunkt (bzw. ungültig).
	 *
	 * @return array<string, array{0: string, 1: ?int, 2: ?int}>
	 */
	public static function dreiergruppen(): array {
		return [
			'„1.234"' => ['1.234', 123400, 123],
			'„12.345.678"' => ['12.345.678', 1234567800, null],
			'„-1.000"' => ['-1.000', -100000, -100],
			'„1.000 EUR"' => ['1.000 EUR', 100000, 100],
			'Viererblock ist kein Tausender' => ['1.2345', 123, 123],
			'Zweierblock' => ['1.23', 123, 123],
			'mehrere Punkte ohne Dreierblöcke' => ['1.23.45', null, null],
		];
	}

	#[DataProvider('dreiergruppen')]
	public function testDreiergruppenNurInDeutscherQuelleTausender(string $value, ?int $german, ?int $other): void {
		$this->assertSame($german, AmountParser::germanText($value), 'deutsch formatierte Quelle');
		$this->assertSame($other, AmountParser::text($value, false), 'andere Quelle');
	}
}
