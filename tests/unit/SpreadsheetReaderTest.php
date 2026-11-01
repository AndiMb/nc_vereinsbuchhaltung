<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\InvoiceBatch\SpreadsheetReader;
use PHPUnit\Framework\TestCase;

/**
 * Tabellen für Sammelrechnungen (#243): XLSX ohne Bibliothek und CSV in den
 * Formen, in denen Excel und LibreOffice sie speichern.
 */
class SpreadsheetReaderTest extends TestCase {

	/**
	 * Baut eine minimale XLSX-Datei so, wie Excel sie ablegt: Arbeitsmappe mit
	 * Relationen, geteilte Zeichenketten und ein Blatt.
	 *
	 * @param string $sheetXml Inhalt von <sheetData>
	 * @param list<string> $shared geteilte Zeichenketten
	 */
	private function xlsx(string $sheetXml, array $shared = [], ?string $target = null): string {
		$sheetPath = 'worksheets/sheet1.xml';
		$file = tempnam(sys_get_temp_dir(), 'xlsx');
		$zip = new \ZipArchive();
		$zip->open($file, \ZipArchive::OVERWRITE);
		$zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
		$zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Liste" sheetId="1" r:id="rId1"/><sheet name="Zweites" sheetId="2" r:id="rId2"/></sheets></workbook>');
		$zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/other.xml"/><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="' . ($target ?? $sheetPath) . '"/></Relationships>');
		$sst = '';
		foreach ($shared as $s) {
			$sst .= '<si><t xml:space="preserve">' . htmlspecialchars($s, ENT_XML1) . '</t></si>';
		}
		$zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . $sst . '</sst>');
		$zip->addFromString('xl/' . $sheetPath, '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheetXml . '</sheetData></worksheet>');
		$zip->addFromString('xl/worksheets/other.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>falsches Blatt</t></is></c></row></sheetData></worksheet>');
		$zip->close();
		$content = (string)file_get_contents($file);
		unlink($file);
		return $content;
	}

	public function testXlsxLiestGeteilteUndInlineZeichenkettenZahlenUndLuecken(): void {
		$sheet = '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="D1" t="inlineStr"><is><t>Preis</t></is></c></row>'
			. '<row r="3"><c r="A3" t="s"><v>2</v></c><c r="B3" t="str"><v>Teilnahme</v></c><c r="D3"><f>A1*2</f><v>120.5</v></c><c r="E3" t="b"><v>1</v></c><c r="F3" t="e"><v>#DIV/0!</v></c></row>';
		$rows = SpreadsheetReader::read($this->xlsx($sheet, ['Name', 'Position', 'Anna Müller']));

		// Schlüssel ist die Zeilennummer im Blatt, leere Zeilen fehlen.
		$this->assertSame([
			1 => ['Name', 'Position', '', 'Preis'],
			3 => ['Anna Müller', 'Teilnahme', '', '120.5', '1', ''],
		], $rows);
	}

	public function testXlsxRichTextWirdZusammengesetzt(): void {
		$file = $this->xlsx('<row r="1"><c r="A1" t="s"><v>0</v></c></row>');
		// Rich Text: mehrere Läufe in einem <si>
		$zip = new \ZipArchive();
		$tmp = tempnam(sys_get_temp_dir(), 'xlsx');
		file_put_contents($tmp, $file);
		$zip->open($tmp);
		$zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><r><t>Hallen</t></r><r><rPr><b/></rPr><t>miete</t></r></si></sst>');
		$zip->close();
		$rows = SpreadsheetReader::read((string)file_get_contents($tmp));
		unlink($tmp);

		$this->assertSame([1 => ['Hallenmiete']], $rows);
	}

	public function testXlsxMitAbsolutemPfadInDenRelationen(): void {
		$rows = SpreadsheetReader::read($this->xlsx('<row r="1"><c r="A1" t="inlineStr"><is><t>ok</t></is></c></row>', [], '/xl/worksheets/sheet1.xml'));
		$this->assertSame([1 => ['ok']], $rows);
	}

	public function testKaputtesZipWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		SpreadsheetReader::read("PK\x03\x04kaputt");
	}

	public function testCsvMitSemikolonUndBom(): void {
		$rows = SpreadsheetReader::read("\xEF\xBB\xBFName;Position;Einzelpreis\r\nAnna;\"Teilnahme; inkl. Essen\";120,00\r\n\r\n");
		$this->assertSame([
			1 => ['Name', 'Position', 'Einzelpreis'],
			2 => ['Anna', 'Teilnahme; inkl. Essen', '120,00'],
		], $rows);
	}

	public function testCsvMitKommaUndTab(): void {
		$this->assertSame([1 => ['Name', 'Position'], 2 => ['Anna', 'X']], SpreadsheetReader::read("Name,Position\nAnna,X\n"));
		$this->assertSame([1 => ['Name', 'Position'], 2 => ['Anna', 'X']], SpreadsheetReader::read("Name\tPosition\nAnna\tX\n"));
	}

	public function testCsvInWindows1252WirdUmgewandelt(): void {
		$rows = SpreadsheetReader::read("Name;Ort\nJ\xFCrgen;M\xFCnchen\n");
		$this->assertSame([1 => ['Name', 'Ort'], 2 => ['Jürgen', 'München']], $rows);
	}

	public function testLeereDateiWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		SpreadsheetReader::read('   ');
	}
}
