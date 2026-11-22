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
use OCA\Vereinsbuchhaltung\Service\Export\GliederungsberichtRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\GliederungsberichtVisuellPdfRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\GliederungsberichtVisuellRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\KassenberichtRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\KurzberichtRenderer;
use OCA\Vereinsbuchhaltung\Service\Pdf\ReportPdfDocument;
use OCA\Vereinsbuchhaltung\Service\Pdf\ReportPdfFactory;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
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
 * Die visuelle Gliederungsansicht als PDF. Daten wie in
 * {@see GliederungsberichtVisuellExportTest}; den gesetzten Text schreibt
 * {@see RecordingPdfDocument} mit, im PDF liegt er nur als Glyphennummern.
 */
class GliederungsberichtVisuellPdfRendererTest extends TestCase {

	protected function setUp(): void {
		\OCA\Vereinsbuchhaltung\AppInfo\Application::loadComposerAutoloader();
		if (!class_exists(\TCPDF::class)) {
			$this->markTestSkipped('TCPDF ist nicht installiert (composer install).');
		}
		require_once __DIR__ . '/Support/RecordingPdfDocument.php';
		require_once __DIR__ . '/GliederungsberichtVisuellExportTest.php';
	}

	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []): string => vsprintf($text, $p));
		$l10n->method('getLanguageCode')->willReturn('de');
		return $l10n;
	}

	/**
	 * @param-out \ArrayObject<int, string> $texts bekommt jeden im PDF gesetzten Text
	 */
	private function renderer(mixed &$texts = null, ?\ArrayObject $pages = null, ?\ArrayObject $colors = null): GliederungsberichtVisuellPdfRenderer {
		$texts = new \ArrayObject();
		$log = $texts;
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $key === 'club_name' ? 'Musterverein' : $default);
		$l10n = $this->l10n();
		$currency = ClubCurrencyStub::of($this);
		$factory = new class($config, $l10n, $log, $pages, $colors) extends ReportPdfFactory {
			public function __construct(
				IAppConfig $config,
				IL10N $l10n,
				private \ArrayObject $log,
				private ?\ArrayObject $pages,
				private ?\ArrayObject $colors,
			) {
				parent::__construct($config, $l10n);
			}

			protected function newDocument(string $orientation): ReportPdfDocument {
				return new RecordingPdfDocument($this->log, $orientation, $this->pages, $this->colors);
			}
		};
		$clock = ClubClockStub::at('2026-11-05 10:00:00');
		$table = new GliederungsberichtRenderer($config, $l10n, $clock, $currency);
		return new GliederungsberichtVisuellPdfRenderer(
			$factory,
			new GliederungsberichtVisuellRenderer($config, $l10n, $clock, $currency, $table),
			$table,
			$currency,
			$l10n,
			$clock,
		);
	}

	public function testErzeugtPdfMitKennzahlenAmpelAbweichungenVerlaufUndAufriss(): void {
		$bytes = $this->renderer($texts)->render(GliederungsberichtVisuellExportTest::visual(), 'year', false);
		KeepPdf::to('gliederungsbericht-visuell.pdf', $bytes);

		$this->assertStringStartsWith('%PDF-', $bytes);
		$this->assertStringContainsString('<pdfaid:part>3</pdfaid:part>', $bytes);
		$texts = $texts->getArrayCopy();
		$this->assertContains('Musterverein', $texts);
		$this->assertContains('Ergebnis <b>je</b> Bereich', $texts, 'Vorlagenname als Text, nicht als HTML');
		$this->assertContains('2026 · Stand: Oktober 2026 · Vergleich gegen: Jahresplan', $texts, 'Meta');
		$this->assertContains('⚠ Diese Konten erfasst kein Abschnitt der Vorlage, sie stehen unter „Nicht erfasste Konten“: 999', $texts);

		// Kennzahlen
		$this->assertContains('Einnahmen', $texts);
		$this->assertContains('9.000,00 €', $texts, 'Ist Einnahmen');
		$this->assertContains('✕ unter Plan', $texts, 'Stufe Einnahmen (Jahresplan)');
		$this->assertContains('75 %', $texts, 'Ausschöpfung');
		$this->assertContains('-3.500,00 €', $texts, 'Differenz Ergebnis');
		$this->assertContains('Lineare Fortschreibung; saisonale Posten verzerren.', $texts);

		// Ampel und Abweichungen
		$this->assertContains('Ausgabenkonten: 1 überschritten · 1 ungeplant · 1 beobachten · 2 im Rahmen', $texts);
		$this->assertContains('Größte Überschreitungen (Ausgaben)', $texts);
		$this->assertContains('420 Hallenmiete', $texts);
		$this->assertContains('+500,00 €', $texts, 'Überschreitung mit Vorzeichen');
		$this->assertContains('3.000,00 €', $texts, 'Lücke ohne Vorzeichen');

		// Verlauf und Legende
		$this->assertContains('Verlauf (kumuliert)', $texts);
		$this->assertContains('Hochrechnung', $texts);

		// Aufriss
		$this->assertContains('Aufriss nach Vorlage', $texts);
		$this->assertContains('Ausschöpfung', $texts);
		$this->assertContains('111 Mitgliedsbeiträge <b>neu</b>', $texts);
		$this->assertContains('im Oberkonto geplant', $texts);
		$this->assertContains('430 Sonstiges †', $texts);
		$this->assertContains('? ungeplant', $texts);
		$this->assertContains('† Konto nicht mehr aktiv', $texts, 'Legende');
	}

	public function testVerlaufIstVektorgrafikMitEingebetteterSchrift(): void {
		$bytes = $this->renderer()->render(GliederungsberichtVisuellExportTest::visual(), 'year', false);

		// Keine Rastergrafik und keine Standardschrift (PDF/A verlangt eingebettete Schriften).
		$this->assertStringNotContainsString('/Subtype /Image', $bytes);
		$this->assertDoesNotMatchRegularExpression('#/BaseFont /Helvetica#', $bytes);
	}

	public function testZeitanteiligerVergleichNenntBasisUndStufen(): void {
		$this->renderer($texts)->render(GliederungsberichtVisuellExportTest::visual(), 'time', false);
		$texts = $texts->getArrayCopy();

		$this->assertContains('2026 · Stand: Oktober 2026 · Vergleich gegen: zeitanteiligen Plan (10 von 12 Monaten)', $texts);
		$this->assertContains('! beobachten', $texts, 'Einnahmen zeitanteilig');
		$this->assertContains('+1.166,67 €', $texts, 'Abweichung zeitanteilig');
	}

	public function testVorBeginnDerPeriodeGiltDerJahresplan(): void {
		$visual = GliederungsberichtVisuellExportTest::visual();
		$visual['asOf']['elapsed'] = 0;
		$visual['series'] = ['months' => [], 'income' => [], 'expense' => []];
		$this->renderer($texts)->render($visual, 'time', false);
		$texts = $texts->getArrayCopy();

		$this->assertContains('Die Periode hat noch nicht begonnen – der zeitanteilige Vergleich und die Hochrechnung entfallen.', $texts);
		$this->assertContains('Die Periode hat noch nicht begonnen – der Verlauf erscheint ab ihrem ersten Monat.', $texts);
		$this->assertNotContains('Zeitanteiliger Plan', $texts);
	}

	public function testOhnePlanUndBuchungenNurHinweis(): void {
		$visual = GliederungsberichtVisuellExportTest::visual();
		foreach (['income', 'expense'] as $nature) {
			$visual['kpis'][$nature]['plan'] = 0;
			$visual['kpis'][$nature]['actual'] = 0;
		}
		$this->renderer($texts)->render($visual, 'year', false);
		$texts = $texts->getArrayCopy();

		$this->assertContains('Für diese Periode gibt es weder Planwerte noch Buchungen. Die Planwerte tragen Sie im Finanzplan ein.', $texts);
		$this->assertNotContains('Aufriss nach Vorlage', $texts);
	}

	public function testLangerAufrissBrichtUmUndWiederholtDenSpaltenkopf(): void {
		$visual = GliederungsberichtVisuellExportTest::visual();
		$account = $visual['rows'][2];
		$rows = [$visual['rows'][0]];
		for ($i = 0; $i < 90; $i++) {
			$rows[] = ['number' => (string)(5000 + $i), 'label' => 'Konto ' . $i] + $account;
		}
		$visual['rows'] = [...$rows, ...array_slice($visual['rows'], -2)];
		/** @var \ArrayObject<string, int> $pages */
		$pages = new \ArrayObject();
		$bytes = $this->renderer($texts, $pages)->render($visual, 'year', false);
		KeepPdf::to('gliederungsbericht-visuell-lang.pdf', $bytes);

		$this->assertGreaterThanOrEqual(3, preg_match_all('#/Type /Page\b#', $bytes));
		$this->assertGreaterThanOrEqual(2, count(array_filter($texts->getArrayCopy(), static fn (string $t): bool => $t === 'Ist zu Plan')), 'Spaltenkopf auf der Folgeseite');
		$this->assertGreaterThan($pages['Verlauf (kumuliert)'], $pages['Saldo'], 'Aufriss läuft über Seiten');
	}

	public function testAmpeltextTraegtDieFarbeDerStufe(): void {
		/** @var \ArrayObject<string, string> $colors */
		$colors = new \ArrayObject();
		$texts = null;
		$this->renderer($texts, null, $colors)->render(GliederungsberichtVisuellExportTest::visual(), 'year', false);

		$this->assertNotSame($colors['✕ überschritten'], $colors['! beobachten']);
		$this->assertNotSame($colors['420 Hallenmiete'], $colors['! beobachten'], 'Bezeichnung bleibt schwarz');
	}

	public function testDateinameOhneSonderzeichen(): void {
		$visual = GliederungsberichtVisuellExportTest::visual();
		$visual['template']['name'] = 'Ergebnis/Größe';
		$period = new Period();
		$period->setLabel('2025/26');
		$visual['period'] = $period;

		$this->assertSame('Gliederungsbericht-visuell-Ergebnis-Groesse-2025-26.pdf', $this->renderer()->fileName($visual));
	}

	/**
	 * @param ?array<string, mixed> $template gefundene Vorlage oder null
	 */
	private function controller(?array $template, GliederungsberichtVisuellPdfRenderer $pdf, ?\Closure $build = null): ExportController {
		$templates = $this->createMock(ReportTemplateService::class);
		$templates->method('find')->willReturn($template);
		$service = $this->createMock(VisualReportService::class);
		$service->method('build')->willReturnCallback($build ?? static fn (): array => GliederungsberichtVisuellExportTest::visual());
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
			$this->createMock(StructuredReportService::class),
			$service,
			$this->createMock(PeriodService::class),
			$this->l10n(),
			gliederungsberichtVisuellPdf: $pdf,
		);
	}

	public function testRouteUnbekannteVorlageGibt404(): void {
		$response = $this->controller(null, $this->createMock(GliederungsberichtVisuellPdfRenderer::class))->gliederungsberichtVisuellPdf('x');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Vorlage nicht gefunden'], $response->getData());
	}

	public function testRouteUngueltigerStandGibt422(): void {
		$response = $this->controller(['id' => '3', 'name' => 'V', 'definition' => []], $this->createMock(GliederungsberichtVisuellPdfRenderer::class), static function (): array {
			throw new \InvalidArgumentException('Ungültiger Stand');
		})->gliederungsberichtVisuellPdf('3', null, '2030-01');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}

	/** DataDownloadResponse braucht Symfony String, das im Unit-Test-Umfeld fehlt; geprüft wird die Übergabe und der Fehlerweg. */
	public function testRouteGibtDemRendererStandUndBasisUndMeldetFehler(): void {
		$seenAsOf = 'unberührt';
		$renderer = $this->createMock(GliederungsberichtVisuellPdfRenderer::class);
		$renderer->expects($this->once())->method('render')->with(GliederungsberichtVisuellExportTest::visual(), 'year')->willThrowException(new \RuntimeException('kaputt'));

		$response = $this->controller(['id' => '3', 'name' => 'V', 'definition' => []], $renderer, static function (string $u, array $t, ?int $p, ?string $asOf) use (&$seenAsOf): array {
			$seenAsOf = $asOf;
			return GliederungsberichtVisuellExportTest::visual();
		})->gliederungsberichtVisuellPdf('3', 7, '', 'quatsch');

		$this->assertNull($seenAsOf, 'leerer Stand = Vorgabe');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'Das PDF konnte nicht erzeugt werden.'], $response->getData());
	}
}
