<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\ExportController;
use OCA\Vereinsbuchhaltung\Db\Period;
use OCA\Vereinsbuchhaltung\Service\Export\AccountStatementRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\AttachmentArchive;
use OCA\Vereinsbuchhaltung\Service\Export\BeitragsbescheinigungRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\CsvExportService;
use OCA\Vereinsbuchhaltung\Service\Export\DatenuebersichtRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\FinanzplanRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\GliederungsberichtPdfRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\GliederungsberichtRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\GliederungsberichtVisuellRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\KassenberichtRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\KurzberichtRenderer;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Service\Pdf\ReportPdf;
use OCA\Vereinsbuchhaltung\Service\Pdf\ReportPdfDocument;
use OCA\Vereinsbuchhaltung\Service\Pdf\ReportPdfFactory;
use OCA\Vereinsbuchhaltung\Service\Report\ReportTemplateService;
use OCA\Vereinsbuchhaltung\Service\Report\StructuredReportService;
use OCA\Vereinsbuchhaltung\Service\Report\VisualReportService;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\ClubClockStub;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\ClubCurrencyStub;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\KeepPdf;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\RecordingPdfDocument;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Der Gliederungsbericht als PDF. Der Text liegt im fertigen PDF als
 * Glyphennummern vor; für Inhalte schreibt {@see RecordingPdfDocument} den
 * gesetzten Text mit, Seitenformat und Seitenzahl kommen aus dem PDF selbst.
 */
class GliederungsberichtPdfRendererTest extends TestCase {

	protected function setUp(): void {
		\OCA\Vereinsbuchhaltung\AppInfo\Application::loadComposerAutoloader();
		if (!class_exists(\TCPDF::class)) {
			$this->markTestSkipped('TCPDF ist nicht installiert (composer install).');
		}
		require_once __DIR__ . '/Support/RecordingPdfDocument.php';
	}

	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []): string => vsprintf($text, $p));
		return $l10n;
	}

	/** @return array<string, mixed> wie StructuredReportService::build() */
	private function report(int $valueColumns = 2, int $accounts = 3): array {
		$period = new Period();
		$period->setId(7);
		$period->setLabel('2026');
		$columns = [];
		$nulls = [];
		for ($i = 0; $i < $valueColumns; $i++) {
			$columns[] = ['source' => 'plan', 'periodOffset' => $i, 'periodId' => 7, 'label' => 'Spalte ' . $i];
			$nulls[] = $i === 0 ? null : 1_200_050 + $i;
		}
		$rows = [
			['kind' => 'heading', 'depth' => 0, 'label' => 'Ideeller Bereich'],
			['kind' => 'heading', 'depth' => 1, 'label' => 'Beiträge und Spenden'],
			['kind' => 'account', 'depth' => 2, 'accountId' => 3, 'number' => '111 01', 'label' => 'Mitgliedsbeiträge', 'nature' => 'income', 'values' => $nulls, 'flags' => ['inactive', 'duplicate']],
		];
		for ($i = 0; $i < $accounts; $i++) {
			$rows[] = ['kind' => 'account', 'depth' => 2, 'accountId' => 10 + $i, 'number' => (string)(4000 + $i), 'label' => 'Erlöse Sparte ' . $i, 'nature' => 'income', 'values' => array_fill(0, $valueColumns, 12_345 * ($i + 1)), 'flags' => []];
		}
		$rows[] = ['kind' => 'note', 'depth' => 2, 'label' => '7.300 Mitglieder'];
		$rows[] = ['kind' => 'line', 'depth' => 1, 'label' => 'Zwischenzeile', 'values' => array_fill(0, $valueColumns, 100)];
		$rows[] = ['kind' => 'footer', 'depth' => 0, 'label' => 'Ergebnis ideeller Bereich', 'nature' => 'result', 'values' => array_fill(0, $valueColumns, -50_000)];
		$rows[] = ['kind' => 'total', 'depth' => 0, 'label' => 'Saldo', 'nature' => 'result', 'values' => array_fill(0, $valueColumns, -50_000)];
		return [
			'template' => ['id' => '3', 'name' => 'Ergebnis je Bereich'],
			'period' => $period,
			'columns' => $columns,
			'rows' => $rows,
			'warnings' => [['code' => 'uncovered', 'accounts' => ['999']]],
			'hasSpheres' => true,
		];
	}

	/**
	 * @param-out \ArrayObject<int, string> $texts bekommt jeden im PDF gesetzten Text
	 */
	private function renderer(mixed &$texts = null, ?\ArrayObject $colors = null): GliederungsberichtPdfRenderer {
		$texts = new \ArrayObject();
		$log = $texts;
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $key === 'club_name' ? 'Musterverein' : $default);
		$l10n = $this->l10n();
		$currency = ClubCurrencyStub::of($this);
		$factory = new class($config, $l10n, $log, $colors) extends ReportPdfFactory {
			public function __construct(IAppConfig $config, IL10N $l10n, private \ArrayObject $log, private ?\ArrayObject $colors) {
				parent::__construct($config, $l10n);
			}

			protected function newDocument(string $orientation): ReportPdfDocument {
				return new RecordingPdfDocument($this->log, $orientation, null, $this->colors);
			}
		};
		$clock = ClubClockStub::at('2026-11-17 10:00:00');
		return new GliederungsberichtPdfRenderer($factory, new GliederungsberichtRenderer($config, $l10n, $clock, $currency), $currency, $l10n, $clock);
	}

	/** @return array{0: float, 1: float} Breite und Höhe der ersten Seite in pt */
	private function mediaBox(string $bytes): array {
		$this->assertSame(1, preg_match('#/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+([\d.]+)\s+([\d.]+)\s*\]#', $bytes, $m), 'MediaBox gefunden');
		return [(float)$m[1], (float)$m[2]];
	}

	public function testErzeugtPdfMitTitelblockWarnungTabelleUndLegende(): void {
		$bytes = $this->renderer($texts)->render($this->report(), false);
		KeepPdf::to('gliederungsbericht-kurz.pdf', $bytes);

		$this->assertStringStartsWith('%PDF-', $bytes);
		$this->assertStringContainsString('<pdfaid:part>3</pdfaid:part>', $bytes);
		$this->assertStringContainsString('Musterverein – Ergebnis je Bereich', $bytes, 'Titel in den Metadaten');

		$texts = $texts->getArrayCopy();
		$this->assertContains('Musterverein', $texts, 'Vereinsname');
		$this->assertContains('Ergebnis je Bereich', $texts, 'Vorlagenname als Überschrift');
		$this->assertContains('2026 · Stand: 17.11.2026', $texts, 'Meta');
		$this->assertContains('⚠ Diese Konten erfasst kein Abschnitt der Vorlage, sie stehen unter „Nicht erfasste Konten“: 999', $texts, 'Warnung');
		$this->assertContains('Nr.', $texts);
		$this->assertContains('Bezeichnung', $texts);
		$this->assertContains('Spalte 1', $texts, 'Wertspalte');
		$this->assertContains('Ideeller Bereich', $texts, 'Abschnittsüberschrift');
		$this->assertContains('Mitgliedsbeiträge † ⚠', $texts, 'Marken am Konto');
		$this->assertContains('7.300 Mitglieder', $texts, 'Erläuterung');
		$this->assertContains('† Konto nicht mehr aktiv · ⚠ Konto ist in mehreren Abschnitten gezählt', $texts, 'Legende');
	}

	public function testLeereWerteBleibenLeerOhneNullBetrag(): void {
		$this->renderer($texts)->render($this->report(), false);

		$this->assertNotContains('0,00 €', $texts->getArrayCopy(), 'null ist kein Betrag');
		$this->assertContains('–', $texts->getArrayCopy(), 'wie die Druckseite');
		$this->assertContains('12.000,51 €', $texts->getArrayCopy());
	}

	public function testOhneWarnungenUndMarkenKeineLegende(): void {
		$report = $this->report();
		$report['warnings'] = [];
		$report['rows'] = [['kind' => 'account', 'depth' => 0, 'accountId' => 1, 'number' => '1', 'label' => 'Konto', 'values' => [1, 2], 'flags' => []]];
		$this->renderer($texts)->render($report, false);

		foreach ($texts->getArrayCopy() as $text) {
			$this->assertStringNotContainsString('†', $text);
			$this->assertStringNotContainsString('⚠', $text);
		}
	}

	public function testBisVierWertspaltenHochformat(): void {
		[$width, $height] = $this->mediaBox($this->renderer()->render($this->report(4), false));

		$this->assertLessThan($height, $width);
	}

	public function testAbFuenfWertspaltenQuerformat(): void {
		$bytes = $this->renderer()->render($this->report(5), false);
		KeepPdf::to('gliederungsbericht-quer.pdf', $bytes);
		[$width, $height] = $this->mediaBox($bytes);

		$this->assertGreaterThan($height, $width);
	}

	public function testLangeVorlageBrichtUmUndSchreibtKopfwiederholungMit(): void {
		$bytes = $this->renderer($texts)->render($this->report(5, 120), false);
		KeepPdf::to('gliederungsbericht-lang.pdf', $bytes);

		$this->assertGreaterThanOrEqual(2, preg_match_all('#/Type /Page\b#', $bytes));
		$this->assertGreaterThanOrEqual(2, count(array_filter($texts->getArrayCopy(), static fn (string $t): bool => $t === 'Spalte 4')), 'Spaltenkopf wiederholt sich auf der Folgeseite');
	}

	public function testDateinameMitSchraegstrichUndUmlaut(): void {
		$report = $this->report();
		$report['template']['name'] = 'Ergebnis/Größe Übersicht';
		$period = new Period();
		$period->setLabel('2025/26');
		$report['period'] = $period;

		$name = $this->renderer()->fileName($report);

		$this->assertSame('Gliederungsbericht-Ergebnis-Groesse-Uebersicht-2025-26.pdf', $name);
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]+$/', $name);
	}

	/** Sehr viele Wertspalten: die Bezeichnung behält ihre Mindestbreite, alle Breiten bleiben positiv und füllen die Seite. */
	public function testSpaltenbreitenBleibenBeiVielenWertspaltenPositivUndFuellenDieBreite(): void {
		$method = new \ReflectionMethod(GliederungsberichtPdfRenderer::class, 'columns');
		foreach ([2, 4, 5, 12, 15, 30] as $count) {
			$columns = array_fill(0, $count, ['label' => 'Spalte']);
			$widths = $method->invoke($this->renderer(), 257.0, $columns);

			$this->assertCount($count + 2, $widths);
			foreach ($widths as $w) {
				$this->assertGreaterThan(0.0, $w['width'], "$count Wertspalten");
			}
			$this->assertEqualsWithDelta(1.0, array_sum(array_column($widths, 'width')), 0.0001, "$count Wertspalten");
			$this->assertGreaterThanOrEqual(50.0 / 257.0 - 0.0001, $widths[1]['width'], "Bezeichnung bei $count Wertspalten");
		}
	}

	/** Strukturelle Konten sind wie auf der Druckseite abgesetzt (grau). */
	public function testStrukturelleKontenSindGrau(): void {
		$report = $this->report();
		$report['rows'][] = ['kind' => 'account', 'depth' => 2, 'accountId' => 77, 'number' => '9999', 'label' => 'Strukturkonto', 'values' => [1, 2], 'flags' => ['structural']];
		/** @var \ArrayObject<string, string> $colors */
		$colors = new \ArrayObject();
		$texts = null;
		$this->renderer($texts, $colors)->render($report, false);

		$this->assertNotSame($colors['Erlöse Sparte 0'], $colors['Strukturkonto']);
		$this->assertSame($colors['Erlöse Sparte 0'], $colors['Mitgliedsbeiträge † ⚠']);
	}

	/**
	 * @param ?array<string, mixed> $template gefundene Vorlage oder null
	 */
	private function controller(?array $template, ?GliederungsberichtPdfRenderer $pdf): ExportController {
		$templates = $this->createMock(ReportTemplateService::class);
		$templates->method('find')->willReturn($template);
		$service = $this->createMock(StructuredReportService::class);
		$service->method('build')->willReturn($this->report());
		return new ExportController(
			$this->createMock(IRequest::class),
			$this->createMock(CsvExportService::class),
			$this->createMock(AttachmentArchive::class),
			$this->createMock(KassenberichtRenderer::class),
			$this->createMock(KurzberichtRenderer::class),
			$this->createMock(BeitragsbescheinigungRenderer::class),
			$this->createMock(DatenuebersichtRenderer::class),
			$this->createMock(AccountStatementRenderer::class),
			$this->createMock(FinanzplanRenderer::class),
			$this->createMock(GliederungsberichtRenderer::class),
			$this->createMock(GliederungsberichtVisuellRenderer::class),
			$templates,
			$service,
			$this->createMock(VisualReportService::class),
			$this->createMock(PeriodService::class),
			$this->l10n(),
			null,
			$pdf,
		);
	}

	public function testRouteUnbekannteVorlageGibtDieselbeAntwortWieDieDruckseite(): void {
		$controller = $this->controller(null, $this->createMock(GliederungsberichtPdfRenderer::class));

		$pdf = $controller->gliederungsberichtPdf('x', 7);
		$html = $controller->gliederungsbericht('x', 7);

		$this->assertSame(Http::STATUS_NOT_FOUND, $pdf->getStatus());
		$this->assertSame($html->getStatus(), $pdf->getStatus());
		$this->assertSame(['message' => 'Vorlage nicht gefunden'], $pdf->getData());
		$this->assertEquals($html->getData(), $pdf->getData());
	}

	/** DataDownloadResponse braucht Symfony String, das im Unit-Test-Umfeld fehlt; geprüft wird daher der Fehlerweg und die Übergabe des Berichts. */
	public function testRouteGibtDemRendererDenBerichtUndMeldetFehlerOhne500Absturz(): void {
		$renderer = $this->createMock(GliederungsberichtPdfRenderer::class);
		$renderer->expects($this->once())->method('render')->with($this->report())->willThrowException(new \RuntimeException('kaputt'));

		$response = $this->controller(['id' => '3', 'name' => 'V', 'definition' => []], $renderer)->gliederungsberichtPdf('3', 7);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'Das PDF konnte nicht erzeugt werden.'], $response->getData());
	}
}
