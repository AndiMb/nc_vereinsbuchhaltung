<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Pdf\ReportPdf;
use OCA\Vereinsbuchhaltung\Service\Pdf\ReportPdfFactory;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\KeepPdf;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Die PDF-Basis der formellen Berichte (Issue #364): Seitenumbruch in Tabellen,
 * zusammenhängende Blöcke, Archivkennzeichnung. Der Text liegt als Glyphennummern
 * im PDF und lässt sich im Rohstrom nicht suchen; geprüft werden Seitenzahl,
 * Lage und Aufbau.
 */
class ReportPdfTest extends TestCase {

	protected function setUp(): void {
		if (!class_exists(\TCPDF::class)) {
			\OCA\Vereinsbuchhaltung\AppInfo\Application::loadComposerAutoloader();
		}
		if (!class_exists(\TCPDF::class)) {
			$this->markTestSkipped('TCPDF ist nicht installiert (composer install).');
		}
	}

	public function factory(array $config = []): ReportPdfFactory {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $config[$key] ?? $default);
		return new ReportPdfFactory($appConfig, $this->l10n());
	}

	public function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []): string => vsprintf($text, $p));
		$l10n->method('n')->willReturnCallback(static fn (string $s, string $pl, int $n): string => str_replace('%n', (string)$n, $n === 1 ? $s : $pl));
		return $l10n;
	}

	private function pdf(): ReportPdf {
		return $this->factory(['club_name' => 'TSV Beispiel e. V.'])->create('TSV Beispiel e. V. – Testbericht', 'Erstellt am 17.11.2026', false);
	}

	public function testErzeugtPdfA3MitTitelUndSeitenzahl(): void {
		$pdf = $this->pdf();
		$pdf->titleBlock('TSV Beispiel e. V.', 'Testbericht', 'Erstellt am 17.11.2026');
		$pdf->paragraph('Ein Absatz.');
		$bytes = $pdf->output();

		$this->assertStringStartsWith('%PDF-', $bytes);
		$this->assertStringContainsString('<pdfaid:part>3</pdfaid:part>', $bytes);
		$this->assertStringContainsString('TSV Beispiel e. V. – Testbericht', $bytes, 'Titel in den Metadaten');
		$this->assertSame(1, $pdf->pageCount());
	}

	public function testLangeTabelleBrichtUmUndWiederholtDenKopf(): void {
		$pdf = $this->pdf();
		$pdf->titleBlock('TSV Beispiel e. V.', 'Testbericht', '');
		$rows = [['style' => ReportPdf::ROW_HEAD, 'span' => [2, 1], 'cells' => ['Einnahmen', 'Betrag']]];
		for ($i = 1; $i <= 120; $i++) {
			$rows[] = ['cells' => [(string)(4000 + $i), 'Konto ' . $i, number_format($i * 12.5, 2, ',', '.') . ' €']];
		}
		$rows[] = ['style' => ReportPdf::ROW_RESULT, 'span' => [2, 1], 'cells' => ['Summe', '90.750,00 €']];
		$pdf->table([['width' => 0.15], ['width' => 0.6], ['width' => 0.25, 'align' => 'R']], $rows);
		KeepPdf::to('report-table.pdf', $pdf->output());

		$this->assertGreaterThanOrEqual(3, $pdf->pageCount());
	}

	/**
	 * Ein Block, der über das Seitenende liefe, beginnt geschlossen auf der
	 * nächsten Seite – die Unterschriften stehen nie allein und nie zerrissen.
	 */
	public function testZusammenhaengenderBlockWandertGeschlossenAufDieNaechsteSeite(): void {
		$pdf = $this->pdf();
		$pdf->titleBlock('TSV Beispiel e. V.', 'Testbericht', '');
		// Die erste Seite bis kurz vor den unteren Rand füllen.
		while ($pdf->document()->GetY() < 245) {
			$pdf->paragraph('Füllzeile');
		}
		$this->assertSame(1, $pdf->pageCount());

		$startPages = [];
		$pdf->keepTogether(function (ReportPdf $pdf) use (&$startPages): void {
			$startPages[] = $pdf->document()->getPage();
			$pdf->heading('Vollständigkeit');
			$pdf->paragraph('42 Buchungen (Nr. 1–42), Buchungsnummern lückenlos.');
			$pdf->signatures(['Ort, Datum · Schatzmeister/in', 'Ort, Datum · Kassenprüfer/in', 'Ort, Datum · Kassenprüfer/in']);
		});
		KeepPdf::to('report-keep-together.pdf', $pdf->output());

		$this->assertSame([1, 2], $startPages, 'erst auf Seite 1 versucht, dann geschlossen auf Seite 2 gesetzt');
		$this->assertSame(2, $pdf->pageCount(), 'keine leere oder halbe Seite dazwischen');
	}

	public function testPasstDerBlockBleibtErAufDerSeite(): void {
		$pdf = $this->pdf();
		$pdf->titleBlock('TSV Beispiel e. V.', 'Testbericht', '');
		$calls = 0;
		$pdf->keepTogether(function (ReportPdf $pdf) use (&$calls): void {
			$calls++;
			$pdf->signatures(['A', 'B']);
		});
		$this->assertSame(1, $calls);
		$this->assertSame(1, $pdf->pageCount());
	}

	public function testUnlesbaresLogoLaesstDenBerichtNichtScheitern(): void {
		$pdf = $this->pdf();
		$pdf->titleBlock('TSV Beispiel e. V.', 'Testbericht', '', 'kein Bild');
		$this->assertStringStartsWith('%PDF-', $pdf->output());
	}
}
