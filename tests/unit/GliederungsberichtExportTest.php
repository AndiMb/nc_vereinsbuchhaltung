<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\Period;
use OCA\Vereinsbuchhaltung\Service\AccountStatementService;
use OCA\Vereinsbuchhaltung\Service\BudgetOverviewService;
use OCA\Vereinsbuchhaltung\Service\Export\CsvExportService;
use OCA\Vereinsbuchhaltung\Service\Export\GliederungsberichtRenderer;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Service\ReportService;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Druck und CSV des Gliederungsberichts lesen dieselbe Zeilenliste.
 */
class GliederungsberichtExportTest extends TestCase {

	/** @return array<string, mixed> */
	private function report(): array {
		$period = new Period();
		$period->setId(7);
		$period->setLabel('2026');
		return [
			'template' => ['id' => '3', 'name' => 'Ergebnis je Bereich'],
			'period' => $period,
			'columns' => [
				['source' => 'plan', 'periodOffset' => -1, 'periodId' => null, 'label' => 'Plan (keine Periode)'],
				['source' => 'plan', 'periodOffset' => 0, 'periodId' => 7, 'label' => 'Plan 2026'],
			],
			'rows' => [
				['kind' => 'heading', 'depth' => 0, 'label' => 'Ideeller Bereich'],
				['kind' => 'account', 'depth' => 1, 'accountId' => 3, 'number' => '111 01', 'label' => 'Mitgliedsbeiträge <alt>', 'nature' => 'income', 'values' => [null, 1_200_050], 'flags' => ['inactive']],
				['kind' => 'note', 'depth' => 2, 'label' => '7.300 Mitglieder'],
				['kind' => 'footer', 'depth' => 0, 'label' => 'Ergebnis ideeller Bereich', 'nature' => 'result', 'values' => [null, -50_000]],
				['kind' => 'total', 'depth' => 0, 'label' => 'Saldo', 'nature' => 'result', 'values' => [null, -50_000]],
			],
			'warnings' => [['code' => 'uncovered', 'accounts' => ['999']]],
			'hasSpheres' => true,
		];
	}

	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		return $l10n;
	}

	public function testCsvMitEbeneArtUndLeererSpalteOhnePeriode(): void {
		$csv = new CsvExportService(
			$this->createMock(AccountMapper::class),
			$this->createMock(JournalMapper::class),
			$this->createMock(JournalLineMapper::class),
			$this->createMock(ReportService::class),
			$this->createMock(PeriodService::class),
			$this->createMock(AccountStatementService::class),
			$this->createMock(BudgetOverviewService::class),
		);

		$file = $csv->gliederungsbericht($this->report());
		$lines = array_map(
			static fn (string $l): array => str_getcsv($l, ';', '"', ''),
			preg_split('/\r?\n/', trim(substr($file->content, 3))) ?: [],
		);

		$this->assertSame('Ergebnis-je-Bereich_2026.csv', $file->fileName);
		$this->assertSame(['Ebene', 'Art', 'Nr.', 'Bezeichnung', 'Plan (keine Periode) (EUR)', 'Plan 2026 (EUR)'], $lines[0]);
		$this->assertSame(['2', 'Konto', '111 01', 'Mitgliedsbeiträge <alt>', '', '12.000,50'], $lines[2]);
		$this->assertSame(['3', 'Erläuterung', '', '7.300 Mitglieder', '', ''], $lines[3]);
		$this->assertSame(['1', 'Gesamt', '', 'Saldo', '', '-500,00'], $lines[5]);
	}

	public function testDruckseiteZeigtWarnungMaskiertTexteUndMarkiertInaktive(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('Musterverein');
		$html = (new GliederungsberichtRenderer($config, $this->l10n()))->render($this->report());

		$this->assertStringContainsString('<h1>Ergebnis je Bereich</h1>', $html);
		$this->assertStringContainsString('Diese Konten erfasst kein Abschnitt der Vorlage', $html);
		$this->assertStringContainsString('Mitgliedsbeiträge &lt;alt&gt; †', $html);
		$this->assertStringContainsString('7.300 Mitglieder', $html);
		$this->assertStringContainsString('<td class="num">–</td>', $html, 'Spalte ohne Periode');
		$this->assertStringContainsString('-500,00 €', $html);
		$this->assertStringNotContainsString('<script', $html);
	}
}
