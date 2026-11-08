<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\InvoiceBatch\SpreadsheetReader;
use OCA\Vereinsbuchhaltung\Service\Text\SafeXml;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tabellen für Sammelrechnungen (#243): XLSX ohne Bibliothek und CSV in den
 * Formen, in denen Excel und LibreOffice sie speichern.
 */
class SpreadsheetReaderTest extends TestCase {

	/** @var list<string> */
	private array $tempFiles = [];

	private const SHEET_OPEN = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
	private const SHEET_CLOSE = '</sheetData></worksheet>';

	protected function tearDown(): void {
		foreach ($this->tempFiles as $file) {
			@unlink($file);
		}
	}

	/** Temporäre Dateien wie Nextclouds ITempManager: eine echte Datei, die der Test am Ende löscht. */
	private function temp(): ITempManager {
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(function (string $postFix = ''): string {
			$file = tempnam(sys_get_temp_dir(), 'vbh-test') . $postFix;
			touch($file);
			$this->tempFiles[] = $file;
			return $file;
		});
		return $temp;
	}

	/** @return array<int, list<string>> */
	private function read(string $content): array {
		return SpreadsheetReader::read($content, $this->temp());
	}

	/** XLSX wird über die temporären Dateien von Nextcloud entpackt, nicht über tempnam() (#265). */
	public function testXlsxNutztDieTemporaereDateiVonNextcloud(): void {
		$temp = $this->createMock(ITempManager::class);
		$file = tempnam(sys_get_temp_dir(), 'vbh-test');
		$this->tempFiles[] = $file;
		$temp->expects($this->once())->method('getTemporaryFile')->with('.xlsx')->willReturn($file);

		$rows = SpreadsheetReader::read($this->xlsx('<row r="1"><c r="A1" t="inlineStr"><is><t>ok</t></is></c></row>'), $temp);

		$this->assertSame([1 => ['ok']], $rows);
	}

	public function testCsvBrauchtKeineTemporaereDatei(): void {
		$temp = $this->createMock(ITempManager::class);
		$temp->expects($this->never())->method('getTemporaryFile');

		$this->assertSame([1 => ['Name'], 2 => ['Anna']], SpreadsheetReader::read("Name\nAnna\n", $temp));
	}

	/**
	 * Baut eine minimale XLSX-Datei so, wie Excel sie ablegt: Arbeitsmappe mit
	 * Relationen, geteilte Zeichenketten und ein Blatt.
	 *
	 * @param string $sheetXml Inhalt von <sheetData>
	 * @param list<string> $shared geteilte Zeichenketten
	 */
	/** @param ?string $rawSheet ersetzt das erste Blatt wörtlich, ohne <worksheet>-Hülle */
	private function xlsx(string $sheetXml, array $shared = [], ?string $target = null, ?string $rawSheet = null): string {
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
		$zip->addFromString('xl/' . $sheetPath, $rawSheet ?? '<?xml version="1.0"?>' . self::SHEET_OPEN . $sheetXml . self::SHEET_CLOSE);
		$zip->addFromString('xl/worksheets/other.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>falsches Blatt</t></is></c></row></sheetData></worksheet>');
		$zip->close();
		$content = (string)file_get_contents($file);
		unlink($file);
		return $content;
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function unsafeSheets(): array {
		$attrs = static fn (string $value): string => implode(' ', array_map(static fn (int $i): string => 'a' . $i . '="' . $value . '"', range(1, 300)));
		$cell = '<row r="1"><c r="A1" t="inlineStr"><is><t>&e;</t></is></c></row>';
		return [
			'interne Entität' => ['<?xml version="1.0"?><!DOCTYPE worksheet [<!ENTITY e "' . str_repeat('A', 1000) . '">]>' . self::SHEET_OPEN . $cell . self::SHEET_CLOSE],
			'DOCTYPE in UTF-16' => ["\xFF\xFE" . mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE worksheet [<!ENTITY e "A">]>' . self::SHEET_OPEN . $cell . self::SHEET_CLOSE, 'UTF-16LE', 'UTF-8')],
			'viele Attribute' => [self::SHEET_OPEN . '<row r="1" ' . $attrs('1') . '/>' . self::SHEET_CLOSE],
			'Attribute mit >' => [self::SHEET_OPEN . '<row r="1" ' . $attrs('>') . '/>' . self::SHEET_CLOSE],
			'Kodierung ohne Leerzeichen' => ['<?xml version="1.0"encoding="UTF-7"?>' . self::SHEET_OPEN . self::SHEET_CLOSE],
		];
	}

	/** Präparierte Teile lehnt {@see SafeXml} ab, der Leser meldet sie als unlesbar. */
	#[DataProvider('unsafeSheets')]
	public function testPraepariertesBlattWirdAbgelehnt(string $sheetXml): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Die Excel-Datei ließ sich nicht lesen.');
		$this->read($this->xlsx('', rawSheet: $sheetXml));
	}

	/** Die Deklaration, die Excel schreibt, samt BOM, geht durch. */
	public function testExcelDeklarationMitBomWirdGelesen(): void {
		$sheet = "\xEF\xBB\xBF" . '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\r\n"
			. self::SHEET_OPEN . '<row r="1"><c r="A1" t="inlineStr"><is><t>Anna</t></is></c></row>' . self::SHEET_CLOSE;

		$this->assertSame([1 => ['Anna']], $this->read($this->xlsx('', rawSheet: $sheet)));
	}

	public function testXlsxLiestGeteilteUndInlineZeichenkettenZahlenUndLuecken(): void {
		$sheet = '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="D1" t="inlineStr"><is><t>Preis</t></is></c></row>'
			. '<row r="3"><c r="A3" t="s"><v>2</v></c><c r="B3" t="str"><v>Teilnahme</v></c><c r="D3"><f>A1*2</f><v>120.5</v></c><c r="E3" t="b"><v>1</v></c><c r="F3" t="e"><v>#DIV/0!</v></c></row>';
		$rows = $this->read($this->xlsx($sheet, ['Name', 'Position', 'Anna Müller']));

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
		$rows = $this->read((string)file_get_contents($tmp));
		unlink($tmp);

		$this->assertSame([1 => ['Hallenmiete']], $rows);
	}

	public function testXlsxMitAbsolutemPfadInDenRelationen(): void {
		$rows = $this->read($this->xlsx('<row r="1"><c r="A1" t="inlineStr"><is><t>ok</t></is></c></row>', [], '/xl/worksheets/sheet1.xml'));
		$this->assertSame([1 => ['ok']], $rows);
	}

	public function testKaputtesZipWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->read("PK\x03\x04kaputt");
	}

	public function testCsvMitSemikolonUndBom(): void {
		$rows = $this->read("\xEF\xBB\xBFName;Position;Einzelpreis\r\nAnna;\"Teilnahme; inkl. Essen\";120,00\r\n\r\n");
		$this->assertSame([
			1 => ['Name', 'Position', 'Einzelpreis'],
			2 => ['Anna', 'Teilnahme; inkl. Essen', '120,00'],
		], $rows);
	}

	public function testCsvMitKommaUndTab(): void {
		$this->assertSame([1 => ['Name', 'Position'], 2 => ['Anna', 'X']], $this->read("Name,Position\nAnna,X\n"));
		$this->assertSame([1 => ['Name', 'Position'], 2 => ['Anna', 'X']], $this->read("Name\tPosition\nAnna\tX\n"));
	}

	public function testCsvInWindows1252WirdUmgewandelt(): void {
		$rows = $this->read("Name;Ort\nJ\xFCrgen;M\xFCnchen\n");
		$this->assertSame([1 => ['Name', 'Ort'], 2 => ['Jürgen', 'München']], $rows);
	}

	public function testLeereDateiWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->read('   ');
	}
}
