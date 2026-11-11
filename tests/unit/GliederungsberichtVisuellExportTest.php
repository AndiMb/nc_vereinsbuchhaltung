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
use OCA\Vereinsbuchhaltung\Service\Export\GliederungsberichtVisuellRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\KassenberichtRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\KurzberichtRenderer;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Service\Report\ReportTemplateService;
use OCA\Vereinsbuchhaltung\Service\Report\StructuredReportService;
use OCA\Vereinsbuchhaltung\Service\Report\VisualReportService;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\ClubClockStub;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\ClubCurrencyStub;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Die Druckseite der visuellen Gliederungsansicht (Plan gegen Ist): sie zeigt
 * genau die Daten aus VisualReportService::build(), ohne Skript.
 */
class GliederungsberichtVisuellExportTest extends TestCase {

	/**
	 * @param array{level:string, ratio:?float} $year
	 * @param array{level:string, ratio:?float} $time
	 * @return array{year: array{level:string, ratio:?float}, time: array{level:string, ratio:?float}}
	 */
	private static function bases(array $year, array $time): array {
		return ['year' => $year, 'time' => $time];
	}

	/** @return array<string, mixed> wie VisualReportService::build() */
	public static function visual(): array {
		$period = new Period();
		$period->setId(7);
		$period->setLabel('2026');
		$period->setStartDate('2026-01-01');
		$period->setEndDate('2026-12-31');
		$crit = ['level' => 'crit', 'ratio' => null];
		return [
			'template' => ['id' => '3', 'name' => 'Ergebnis <b>je</b> Bereich'],
			'period' => $period,
			'asOf' => [
				'value' => '2026-10', 'until' => '2026-10-31', 'elapsed' => 10, 'total' => 12,
				'options' => ['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'],
				'default' => '2026-10',
			],
			'thresholds' => ['expWarn' => 80, 'expCrit' => 120, 'incGood' => 100, 'incWarn' => 80, 'resTol' => 20],
			'kpis' => [
				'income' => ['plan' => 1_200_000, 'actual' => 900_000, 'forecast' => 1_080_000,
					'status' => self::bases(['level' => 'crit', 'ratio' => 0.75], ['level' => 'warn', 'ratio' => 0.9])],
				'expense' => ['plan' => 1_000_000, 'actual' => 1_050_000, 'forecast' => 1_260_000,
					'status' => self::bases(['level' => 'warn', 'ratio' => 1.05], ['level' => 'crit', 'ratio' => 1.26])],
				'result' => ['plan' => 200_000, 'actual' => -150_000, 'forecast' => -180_000,
					'status' => self::bases($crit, $crit)],
			],
			'distribution' => [
				'year' => [
					'income' => ['good' => 1, 'warn' => 0, 'crit' => 1, 'unplanned' => 0],
					'expense' => ['good' => 2, 'warn' => 1, 'crit' => 1, 'unplanned' => 1],
				],
				'time' => [
					'income' => ['good' => 1, 'warn' => 1, 'crit' => 0, 'unplanned' => 0],
					'expense' => ['good' => 1, 'warn' => 1, 'crit' => 2, 'unplanned' => 1],
				],
			],
			'deviations' => [
				'year' => [
					'income' => [['accountId' => 3, 'number' => '111', 'label' => 'Mitgliedsbeiträge <b>neu</b>', 'amount' => 300_000, 'level' => 'crit']],
					'expense' => [['accountId' => 5, 'number' => '420', 'label' => 'Hallenmiete', 'amount' => 50_000, 'level' => 'crit']],
				],
				'time' => [
					'income' => [['accountId' => 3, 'number' => '111', 'label' => 'Mitgliedsbeiträge <b>neu</b>', 'amount' => 100_000, 'level' => 'warn']],
					'expense' => [['accountId' => 5, 'number' => '420', 'label' => 'Hallenmiete', 'amount' => 116_667, 'level' => 'crit']],
				],
			],
			'series' => [
				'months' => ['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'],
				'income' => [300_000, 350_000, 400_000, 450_000, 500_000, 600_000, 650_000, 700_000, 800_000, 900_000],
				'expense' => [100_000, 200_000, 300_000, 400_000, 500_000, 600_000, 700_000, 800_000, 900_000, 1_050_000],
			],
			'rows' => [
				['kind' => 'heading', 'depth' => 0, 'label' => 'Ideeller Bereich'],
				['kind' => 'account', 'depth' => 1, 'accountId' => 3, 'number' => '111', 'label' => 'Mitgliedsbeiträge <b>neu</b>', 'nature' => 'income',
					'plan' => 1_200_000, 'actual' => 900_000, 'flags' => [],
					'status' => self::bases(['level' => 'crit', 'ratio' => 0.75], ['level' => 'warn', 'ratio' => 0.9])],
				['kind' => 'account', 'depth' => 1, 'accountId' => 5, 'number' => '420', 'label' => 'Hallenmiete', 'nature' => 'expense',
					'plan' => 400_000, 'actual' => 450_000, 'flags' => [],
					'status' => self::bases(['level' => 'warn', 'ratio' => 1.125], ['level' => 'crit', 'ratio' => 1.35])],
				['kind' => 'account', 'depth' => 2, 'accountId' => 6, 'number' => '420 01', 'label' => 'Nebenkosten', 'nature' => 'expense',
					'plan' => 0, 'actual' => 20_000, 'flags' => ['covered'],
					'status' => self::bases(['level' => 'none', 'ratio' => null], ['level' => 'none', 'ratio' => null])],
				['kind' => 'account', 'depth' => 1, 'accountId' => 8, 'number' => '430', 'label' => 'Sonstiges', 'nature' => 'expense',
					'plan' => 0, 'actual' => 30_000, 'flags' => ['inactive'],
					'status' => self::bases(['level' => 'unplanned', 'ratio' => null], ['level' => 'unplanned', 'ratio' => null])],
				['kind' => 'footer', 'depth' => 0, 'label' => 'Ergebnis ideeller Bereich', 'nature' => 'result',
					'plan' => 800_000, 'actual' => 420_000, 'status' => self::bases($crit, $crit)],
				['kind' => 'total', 'depth' => 0, 'label' => 'Saldo', 'nature' => 'result',
					'plan' => 200_000, 'actual' => -150_000, 'status' => self::bases($crit, $crit)],
			],
			'warnings' => [['code' => 'uncovered', 'accounts' => ['999']]],
			'hasSpheres' => true,
		];
	}

	/** IL10N-Ersatz, der wie Nextcloud vsprintf anwendet: ein nacktes „%“ fiele auf. */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$l10n->method('getLanguageCode')->willReturn('de');
		return $l10n;
	}

	private function renderer(string $currency = 'EUR'): GliederungsberichtVisuellRenderer {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('Musterverein');
		$l10n = $this->l10n();
		$clock = ClubClockStub::at('2026-11-05 10:00:00');
		$money = ClubCurrencyStub::of($this, $currency);
		return new GliederungsberichtVisuellRenderer($config, $l10n, $clock, $money, new GliederungsberichtRenderer($config, $l10n, $clock, $money));
	}

	/** Zeile der Aufriss-Tabelle, die $text enthält. */
	private static function tableRow(string $html, string $text): string {
		foreach (explode('</tr>', $html) as $row) {
			if (str_contains($row, $text)) {
				return $row;
			}
		}
		self::fail('Keine Zeile mit ' . $text);
	}

	public function testKopfMitVorlagePeriodeStandBasisUndErstellungsdatum(): void {
		$html = $this->renderer()->render(self::visual(), 'year');

		$this->assertStringStartsWith('<!DOCTYPE html>', $html);
		$this->assertStringContainsString('<div class="club">Musterverein</div>', $html);
		$this->assertStringContainsString('<h1>Ergebnis &lt;b&gt;je&lt;/b&gt; Bereich</h1>', $html);
		$this->assertStringContainsString('2026 · Stand: Oktober 2026 · Vergleich gegen: Jahresplan · Erstellt am 05.11.2026', $html);
		$this->assertStringNotContainsString('<b>je</b>', $html);
	}

	public function testStandAktuell(): void {
		$visual = self::visual();
		$visual['asOf']['value'] = 'current';

		$this->assertStringContainsString('Stand: Aktuell (alle Buchungen)', $this->renderer()->render($visual, 'year'));
	}

	public function testZeilenTragenBezeichnungStufeBalkenUndAusschoepfung(): void {
		$html = $this->renderer()->render(self::visual(), 'year');

		$miete = self::tableRow($html, 'Hallenmiete</td>');
		$this->assertStringContainsString('420 Hallenmiete', $miete);
		$this->assertStringContainsString('beobachten', $miete);
		$this->assertStringContainsString('class="bar tone-', $miete);
		$this->assertStringContainsString('4.500,00 €', $miete);
		$this->assertStringContainsString('4.000,00 €', $miete);
		$this->assertStringContainsString('113 %', $miete);

		$income = self::tableRow($html, '111 Mitgliedsbeiträge &lt;b&gt;neu&lt;/b&gt;</td>');
		$this->assertStringContainsString('unter Plan', $income);
		$this->assertStringContainsString('75 %', $income);

		$this->assertStringContainsString('im Oberkonto geplant', self::tableRow($html, 'Nebenkosten'));
		$sonstiges = self::tableRow($html, 'Sonstiges');
		$this->assertStringContainsString('ungeplant', $sonstiges);
		$this->assertStringContainsString('Sonstiges †', $sonstiges);

		// Ergebniszeilen: kein Balken, keine Ausschöpfung
		$saldo = self::tableRow($html, 'Saldo');
		$this->assertStringContainsString('class="result"', $saldo);
		$this->assertStringNotContainsString('class="bar tone-', $saldo);
		$this->assertStringNotContainsString('%', $saldo);
		$this->assertStringContainsString('class="sum"', self::tableRow($html, 'Ergebnis ideeller Bereich'));
		$this->assertStringContainsString('colspan="6"', self::tableRow($html, 'Ideeller Bereich</td>'));
	}

	public function testBasisZeitanteiligZeigtDenZeitanteilStatus(): void {
		$html = $this->renderer()->render(self::visual(), 'time');

		$this->assertStringContainsString('Vergleich gegen: zeitanteiligen Plan (10 von 12 Monaten)', $html);
		$this->assertStringContainsString('überschritten', self::tableRow($html, 'Hallenmiete</td>'));
		$this->assertStringContainsString('135 %', self::tableRow($html, 'Hallenmiete</td>'));
		$this->assertStringContainsString('Ausgabenkonten: 2 überschritten · 1 ungeplant · 1 beobachten · 1 im Rahmen', $html);
		$this->assertStringContainsString('+1.166,67 €', $html);
	}

	public function testUnbekannteBasisGiltAlsJahresplan(): void {
		$this->assertStringContainsString('Vergleich gegen: Jahresplan', $this->renderer()->render(self::visual(), 'quatsch'));
	}

	public function testOhneVergangeneMonateFaelltZeitanteiligAufJahrZurueckOhneHochrechnung(): void {
		$visual = self::visual();
		$visual['asOf'] = ['value' => 'current', 'until' => '2026-12-31', 'elapsed' => 0, 'total' => 12, 'options' => [], 'default' => 'current'];
		$visual['series'] = ['months' => [], 'income' => [], 'expense' => []];
		foreach (['income', 'expense', 'result'] as $nature) {
			$visual['kpis'][$nature]['forecast'] = null;
		}

		$html = $this->renderer()->render($visual, 'time');

		$this->assertStringContainsString('Vergleich gegen: Jahresplan', $html);
		$this->assertStringContainsString('der zeitanteilige Vergleich und die Hochrechnung entfallen', $html);
		$this->assertStringNotContainsString('Hochrechnung</', $html);
		$this->assertStringNotContainsString('Zeitanteiliger Plan', $html);
		$this->assertStringNotContainsString('<svg', $html);
		$this->assertStringContainsString('der Verlauf erscheint ab ihrem ersten Monat', $html);
	}

	public function testKennzahlenVerteilungUndAbweichungen(): void {
		$html = $this->renderer()->render(self::visual(), 'year');

		$this->assertStringContainsString('Hochrechnung', $html);
		$this->assertStringContainsString('10.800,00 €', $html);
		$this->assertStringContainsString('Lineare Fortschreibung; saisonale Posten verzerren.', $html);
		$this->assertStringContainsString('Zeitanteiliger Plan', $html);
		$this->assertStringContainsString('Differenz', $html);
		$this->assertStringContainsString('-3.500,00 €', $html, 'Differenz des Ergebnisses');
		$this->assertStringContainsString('105 %', $html);
		$this->assertStringContainsString('deutlich unter Plan', $html);

		$this->assertStringContainsString('Ausgabenkonten: 1 überschritten · 1 ungeplant · 1 beobachten · 2 im Rahmen', $html);
		$this->assertStringContainsString('Einnahmenkonten: 1 unter Plan · 0 ungeplant · 0 beobachten · 1 im Plan', $html);

		$this->assertStringContainsString('+500,00 €', $html, 'Überschreitung mit Vorzeichen');
		$this->assertStringContainsString('3.000,00 €', $html);
		$this->assertStringNotContainsString('+3.000,00 €', $html, 'Lücke ohne Vorzeichen');
	}

	public function testVerlaufAlsSvgOhneSkript(): void {
		$html = $this->renderer()->render(self::visual(), 'year');

		$this->assertStringContainsString('<svg', $html);
		$this->assertStringContainsString('<title>Verlauf</title>', $html);
		$this->assertStringContainsString('>Okt</text>', $html);
		$this->assertStringNotContainsString('<script', $html);
		$this->assertStringContainsString('print-color-adjust: exact', $html);
	}

	public function testBezeichnungenWerdenMaskiert(): void {
		$html = $this->renderer()->render(self::visual(), 'year');

		$this->assertStringContainsString('Mitgliedsbeiträge &lt;b&gt;neu&lt;/b&gt;', $html);
		$this->assertStringNotContainsString('<b>neu</b>', $html);
	}

	public function testWarnungUndHinweisOhnePlan(): void {
		$html = $this->renderer()->render(self::visual(), 'year');
		$this->assertStringContainsString('Diese Konten erfasst kein Abschnitt der Vorlage', $html);
		$this->assertStringNotContainsString('noch kein Plan erfasst', $html);

		$visual = self::visual();
		$visual['kpis']['income']['plan'] = 0;
		$visual['kpis']['expense']['plan'] = 0;
		$this->assertStringContainsString('noch kein Plan erfasst', $this->renderer()->render($visual, 'year'));
	}

	public function testBetraegeInDerVereinswaehrung(): void {
		$html = $this->renderer('CHF')->render(self::visual(), 'year');

		$this->assertStringContainsString('4.500,00 CHF', $html);
		$this->assertStringNotContainsString('€', $html);
	}

	/**
	 * @param ?array<string, mixed> $template gefundene Vorlage oder null
	 * @param \Closure(?string): array<string, mixed> $build Ersatz für VisualReportService::build() (bekommt den Stand)
	 * @param ?string &$renderedBasis die an den Renderer übergebene Basis
	 */
	private function controller(?array $template, \Closure $build, ?string &$renderedBasis = null): ExportController {
		$templates = $this->createMock(ReportTemplateService::class);
		$templates->method('find')->willReturn($template);
		$service = $this->createMock(VisualReportService::class);
		$service->method('build')->willReturnCallback(static fn (string $u, array $t, ?int $p, ?string $asOf): array => $build($asOf));
		$renderer = $this->createMock(GliederungsberichtVisuellRenderer::class);
		$renderer->method('render')->willReturnCallback(static function (array $visual, string $basis) use (&$renderedBasis): string {
			$renderedBasis = $basis;
			return '<p>Seite</p>';
		});
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
			$renderer,
			$templates,
			$this->createMock(StructuredReportService::class),
			$service,
			$this->createMock(PeriodService::class),
			$this->l10n(),
		);
	}

	public function testRouteUnbekannteVorlageGibt404(): void {
		$response = $this->controller(null, static fn (): array => self::visual())->gliederungsberichtVisuell('x');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testRouteUngueltigerStandGibt422(): void {
		$response = $this->controller(['id' => '3', 'name' => 'V', 'definition' => []], static function (): array {
			throw new \InvalidArgumentException('Ungültiger Stand: 2030-01');
		})->gliederungsberichtVisuell('3', null, '2030-01');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['message' => 'Ungültiger Stand'], $response->getData());
	}

	public function testRouteLiefertDruckseiteLeererStandIstVorgabeUnbekannteBasisIstJahr(): void {
		$seenAsOf = 'unberührt';
		$basis = null;
		$controller = $this->controller(['id' => '3', 'name' => 'V', 'definition' => []], static function (?string $asOf) use (&$seenAsOf): array {
			$seenAsOf = $asOf;
			return self::visual();
		}, $basis);

		$response = $controller->gliederungsberichtVisuell('3', 7, '', 'quatsch');
		$this->assertInstanceOf(DataDisplayResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertNull($seenAsOf);
		$this->assertSame('year', $basis);

		$controller->gliederungsberichtVisuell('3', 7, 'current', 'time');
		$this->assertSame('current', $seenAsOf);
		$this->assertSame('time', $basis);
	}
}
