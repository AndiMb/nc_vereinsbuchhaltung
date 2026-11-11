<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Export\CostCenterAllocationExport;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Service\Report\CostCenterAllocationReportService;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\ClubClockStub;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\ClubCurrencyStub;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Druckseite und CSV des Berichts "Kostenstellen" (Issue #7).
 *
 * Die Übersetzung läuft hier über vsprintf wie in Nextcloud: ein Text mit
 * nacktem Prozentzeichen ließe den Test mit einem ValueError scheitern,
 * statt erst die Druckseite im Betrieb.
 */
class CostCenterAllocationExportTest extends TestCase {

	/** @return array<string, mixed> */
	private function report(bool $reconciled = true, bool $planned = false): array {
		$row = static fn (int $id, string $name, int $cents): array => ['accountId' => $id, 'number' => (string)$id, 'name' => $name, 'amountCents' => $cents];
		return [
			'centers' => [
				[
					'id' => 5, 'code' => 'AJU', 'name' => 'A-Junioren', 'active' => true,
					'income' => [], 'expense' => [$row(5300, 'Ausrüstung', 40000)],
					'incomeCents' => 0, 'expenseCents' => 40000, 'resultCents' => -40000,
				] + ($planned ? ['plan' => ['incomeCents' => 10000, 'expenseCents' => 55000, 'resultCents' => -45000]] : []),
				[
					'id' => 3, 'code' => 'SEN', 'name' => 'Senioren <1.>', 'active' => false,
					'income' => [$row(4000, 'Beiträge', 30000)], 'expense' => [$row(5300, 'Ausrüstung', 60000)],
					'incomeCents' => 30000, 'expenseCents' => 60000, 'resultCents' => -30000,
				],
			],
			'unassigned' => [
				'income' => [], 'expense' => [$row(5400, 'Miete', 123456)],
				'incomeCents' => 0, 'expenseCents' => 123456, 'resultCents' => -123456,
			],
			'total' => ['incomeCents' => 30000, 'expenseCents' => 223456, 'resultCents' => -193456],
			'ledgerResultCents' => $reconciled ? -193456 : -200000,
			'reconciled' => $reconciled,
		] + ($planned ? ['planned' => true] : []);
	}

	private function export(bool $reconciled = true, bool $planned = false): CostCenterAllocationExport {
		$service = $this->createMock(CostCenterAllocationReportService::class);
		$service->method('report')->willReturn($this->report($reconciled, $planned));
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $key === 'club_name' ? 'TSV Beispiel' : $default);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		return new CostCenterAllocationExport(
			$service,
			$this->createMock(PeriodService::class),
			$config,
			$l10n,
			ClubClockStub::at('2026-11-11 10:00:00'),
			ClubCurrencyStub::of($this),
		);
	}

	public function testDruckseiteNenntKostenstellenKontenUndSummen(): void {
		$html = $this->export()->html('u', null);
		foreach (['TSV Beispiel', 'AJU', 'A-Junioren', 'Ausrüstung', '400,00', '600,00', 'Ohne Kostenstelle', '1.234,56', '-1.934,56', '11.11.2026'] as $needle) {
			$this->assertStringContainsString($needle, $html);
		}
	}

	public function testDruckseiteMaskiertNamen(): void {
		$html = $this->export()->html('u', null);
		$this->assertStringContainsString('Senioren &lt;1.&gt;', $html);
		$this->assertStringNotContainsString('Senioren <1.>', $html);
	}

	public function testDruckseiteKennzeichnetInaktive(): void {
		$this->assertStringContainsString('inaktiv', $this->export()->html('u', null));
	}

	public function testAbgestimmterBerichtWarntNicht(): void {
		$this->assertStringNotContainsString('weicht', $this->export()->html('u', null));
	}

	public function testAbweichungWirdGenannt(): void {
		$html = $this->export(false)->html('u', null);
		$this->assertStringContainsString('weicht', $html);
		$this->assertStringContainsString('-2.000,00', $html);
	}

	public function testCsvHatEineZeileJeKontoUndDieSchlusszeile(): void {
		$file = $this->export()->csv('u', null);
		$this->assertSame('kostenstellen_alle_zeitraeume.csv', $file->fileName);
		$this->assertStringStartsWith("\xEF\xBB\xBF", $file->content);
		$rows = array_map(
			static fn (string $line): array => str_getcsv($line, ';'),
			array_values(array_filter(preg_split('/\r?\n/', substr($file->content, 3)) ?: [], static fn (string $l): bool => $l !== '')),
		);
		$this->assertSame(['Kostenstelle', 'Art', 'Konto-Nr.', 'Konto', 'Betrag (EUR)'], $rows[0]);
		$this->assertSame(['AJU A-Junioren', 'Ausgabe', '5300', 'Ausrüstung', '400,00'], $rows[1]);
		$this->assertSame(['SEN Senioren <1.>', 'Einnahme', '4000', 'Beiträge', '300,00'], $rows[2]);
		$this->assertSame(['SEN Senioren <1.>', 'Ausgabe', '5300', 'Ausrüstung', '600,00'], $rows[3]);
		$this->assertSame(['Ohne Kostenstelle', 'Ausgabe', '5400', 'Miete', '1.234,56'], $rows[4]);
		$this->assertSame(['Gesamt', 'Ergebnis', '', '', '-1.934,56'], $rows[5]);
		$this->assertCount(6, $rows);
	}

	// ---- Budget je Kostenstelle (Issue #335) ----

	/** @return list<list<string|null>> */
	private function csvRows(CostCenterAllocationExport $export): array {
		return array_map(
			static fn (string $line): array => str_getcsv($line, ';'),
			array_values(array_filter(preg_split('/\r?\n/', substr($export->csv('u', null)->content, 3)) ?: [], static fn (string $l): bool => $l !== '')),
		);
	}

	public function testDruckseiteZeigtPlanIstAbweichung(): void {
		$html = $this->export(true, true)->html('u', null);
		// AJU: Plan Ausgaben 550,00, Ist 400,00, Abweichung -150,00; Plan-Ergebnis -450,00, Ist -400,00, Abweichung 50,00.
		foreach (['Plan', 'Abweichung', '550,00', '-150,00', '-450,00', '50,00', '100,00'] as $needle) {
			$this->assertStringContainsString($needle, $html);
		}
	}

	public function testOhnePlanBleibenDruckseiteUndCsvWieBisher(): void {
		$html = $this->export()->html('u', null);
		$this->assertStringNotContainsString('Abweichung', $html);
		$this->assertStringNotContainsString('Plan', $html);
		$this->assertCount(6, $this->csvRows($this->export()));
	}

	public function testCsvNenntPlanwerte(): void {
		$rows = $this->csvRows($this->export(true, true));
		$this->assertContains(['AJU A-Junioren', 'Plan Einnahmen', '', '', '100,00'], $rows);
		$this->assertContains(['AJU A-Junioren', 'Plan Ausgaben', '', '', '550,00'], $rows);
		// Die Kostenstelle ohne Plan bekommt keine Planzeilen.
		$this->assertCount(8, $rows);
	}
}
