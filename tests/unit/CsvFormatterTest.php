<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\CsvFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Der Verwendungszweck einer Buchung stammt aus einer fremden Überweisung.
 * Landet er ungefiltert im CSV, kann er in Excel/LibreOffice als Formel
 * ausgeführt werden – bei einer Kassenprüfung genau die falsche Überraschung.
 */
class CsvFormatterTest extends TestCase {

	/**
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function formelFelder(): array {
		return [
			'Gleichheitszeichen' => ['=1+1', "'=1+1"],
			'HYPERLINK-Formel' => ['=HYPERLINK("http://boese.example","Klick")', '\'=HYPERLINK("http://boese.example","Klick")'],
			'DDE-Aufruf' => ['=cmd|\' /C calc\'!A0', "'=cmd|' /C calc'!A0"],
			'Plus' => ['+1+1', "'+1+1"],
			'At-Zeichen' => ['@SUM(A1)', "'@SUM(A1)"],
			'Tabulator' => ["\t=1+1", "'\t=1+1"],
			'Wagenruecklauf' => ["\r=1+1", "'\r=1+1"],
			'Minus mit Text' => ['-cmd|calc', "'-cmd|calc"],
		];
	}

	/**
	 * @dataProvider formelFelder
	 */
	public function testFormelFelderWerdenEntschaerft(string $eingabe, string $erwartet): void {
		$this->assertSame($erwartet, CsvFormatter::safeField($eingabe));
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function harmloseFelder(): array {
		return [
			'leer' => [''],
			'Text' => ['Mitgliedsbeitrag Januar'],
			'Text mit Gleichheitszeichen mittendrin' => ['Rechnung Nr=4711'],
			'positiver Betrag' => ['1.234,56'],
			'negativer Betrag' => ['-1.234,56'],
			'negative Ganzzahl' => ['-42'],
			'Kontonummer' => ['5000 40'],
			'Datum' => ['02.01.2026'],
		];
	}

	/**
	 * @dataProvider harmloseFelder
	 */
	public function testHarmloseFelderBleibenUnveraendert(string $eingabe): void {
		$this->assertSame($eingabe, CsvFormatter::safeField($eingabe));
	}

	/**
	 * Der wichtigste Nicht-Regressionsfall: Beträge müssen Zahlen bleiben,
	 * sonst rechnet die Tabellenkalkulation die Summenspalten nicht mehr.
	 */
	public function testNegativeBetraegeBleibenRechenbar(): void {
		$zeile = CsvFormatter::line(['Saldo', '-1.234,56']);
		$this->assertSame("\"Saldo\";\"-1.234,56\"\r\n", $zeile);
		$this->assertStringNotContainsString("'", $zeile);
	}

	public function testZeileMitAnfuehrungszeichen(): void {
		$this->assertSame(
			"\"Spende \"\"Ferienlager\"\"\";\"50,00\"\r\n",
			CsvFormatter::line(['Spende "Ferienlager"', '50,00']),
		);
	}

	public function testZeileEndetMitCrlf(): void {
		$this->assertStringEndsWith("\r\n", CsvFormatter::line(['a', 'b']));
	}
}
