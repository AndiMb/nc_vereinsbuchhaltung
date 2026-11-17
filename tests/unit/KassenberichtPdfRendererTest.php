<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\Period;
use OCA\Vereinsbuchhaltung\Service\BudgetTree;
use OCA\Vereinsbuchhaltung\Service\Export\KassenberichtPdfRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\KassenberichtRenderer;
use OCA\Vereinsbuchhaltung\Service\Pdf\ReportPdfFactory;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\ClubCurrencyStub;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\KeepPdf;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Der Kassenbericht als PDF (Issue #364). Die Zahlen kommen aus
 * {@see KassenberichtRenderer::collect()} – hier ein fester Datensatz; geprüft
 * wird, dass ein vollständiges, mehrseitiges PDF/A entsteht.
 */
class KassenberichtPdfRendererTest extends TestCase {

	protected function setUp(): void {
		\OCA\Vereinsbuchhaltung\AppInfo\Application::loadComposerAutoloader();
		if (!class_exists(\TCPDF::class)) {
			$this->markTestSkipped('TCPDF ist nicht installiert (composer install).');
		}
	}

	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []): string => vsprintf($text, $p));
		$l10n->method('n')->willReturnCallback(static fn (string $s, string $pl, int $n): string => str_replace('%n', (string)$n, $n === 1 ? $s : $pl));
		return $l10n;
	}

	private function account(int $id, string $number, string $name): Account {
		$a = new Account();
		$a->setId($id);
		$a->setNumber($number);
		$a->setName($name);
		return $a;
	}

	/** @return array<string, mixed> wie KassenberichtRenderer::collect() */
	private function data(int $incomeAccounts): array {
		$income = [];
		for ($i = 1; $i <= $incomeAccounts; $i++) {
			$income[] = ['account' => $this->account(100 + $i, (string)(4000 + $i), 'Erlöse Sparte ' . $i), 'cents' => 12345 * $i];
		}
		$incomeCents = array_sum(array_column($income, 'cents'));
		$expense = [
			['account' => $this->account(201, '6100', 'Hallenmiete'), 'cents' => 240000],
			['account' => $this->account(202, '6200', 'Übungsleiter'), 'cents' => 180000],
			['account' => $this->account(203, '6300', 'Ohne Bewegung'), 'cents' => 0],
		];
		$accounts = [
			['id' => 1, 'parentId' => null, 'type' => 'expense', 'number' => '6100'],
			['id' => 2, 'parentId' => null, 'type' => 'expense', 'number' => '6200'],
			['id' => 3, 'parentId' => null, 'type' => 'income', 'number' => '4001'],
		];
		$nodes = BudgetTree::annotate(BudgetTree::build($accounts, ['plan' => [1 => 200000, 2 => 200000, 3 => 10000], 'actual' => [1 => 240000, 2 => 180000, 3 => 12345]]), 'plan', 'actual');
		return [
			'clubName' => 'Turn- und Sportverein Beispielstadt e. V.',
			'periodLabel' => '2026',
			'from' => '2026-01-01',
			'to' => '2026-12-31',
			'created' => 'Erstellt am 17.11.2026',
			'closeNote' => 'Das Geschäftsjahr 2026 ist noch nicht abgeschlossen.',
			'vermoegen' => [
				'rows' => [
					['account' => $this->account(1, '1200', 'Girokonto'), 'start' => 1500000, 'end' => 1720000],
					['account' => $this->account(2, '1000', 'Kasse'), 'start' => 25000, 'end' => 18050],
				],
				'startCents' => 1525000,
				'endCents' => 1738050,
			],
			'erfolg' => ['income' => $income, 'expense' => $expense, 'incomeCents' => $incomeCents, 'expenseCents' => 420000, 'resultCents' => $incomeCents - 420000],
			'spheres' => [
				'spheres' => [
					['name' => 'Ideeller Bereich', 'income' => 1200.0, 'expense' => 300.5, 'result' => 899.5],
					['name' => 'Zweckbetrieb', 'income' => 0.0, 'expense' => 0.0, 'result' => 0.0],
				],
				'freigrenze' => ['incomeCents' => 0, 'income' => 0, 'threshold' => 45000, 'ratio' => 0, 'level' => 'ok'],
			],
			'budget' => [
				'nodes' => $nodes,
				'accounts' => [1 => $this->account(1, '6100', 'Hallenmiete'), 2 => $this->account(2, '6200', 'Übungsleiter'), 3 => $this->account(3, '4001', 'Beiträge')],
				'totals' => ['planIncome' => 10000, 'actualIncome' => 12345, 'planExpense' => 400000, 'actualExpense' => 420000],
				'conflictCount' => 0,
			],
			'numberingNote' => '312 Buchungen (Nr. 1–312), Buchungsnummern lückenlos.',
		];
	}

	private function render(array $data): string {
		$report = $this->createMock(KassenberichtRenderer::class);
		$report->method('collect')->willReturn($data);
		$report->method('signatureRoles')->willReturn(['Schatzmeister/in', 'Kassenprüfer/in', 'Kassenprüfer/in']);
		$report->method('freigrenzeNote')->willReturn('');
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$renderer = new KassenberichtPdfRenderer($report, new ReportPdfFactory($config, $this->l10n()), ClubCurrencyStub::of($this), $this->l10n());
		$period = new Period();
		$period->setLabel('2026');
		return $renderer->render('verein', $period, false);
	}

	public function testErzeugtPdfAMitAllenAbschnitten(): void {
		$bytes = $this->render($this->data(5));
		KeepPdf::to('kassenbericht-kurz.pdf', $bytes);

		$this->assertStringStartsWith('%PDF-', $bytes);
		$this->assertStringContainsString('<pdfaid:part>3</pdfaid:part>', $bytes);
		$this->assertStringContainsString('Kassenbericht 2026', $bytes, 'Titel in den Metadaten');
	}

	public function testVieleKontenErgebenMehrereSeiten(): void {
		$bytes = $this->render($this->data(60));
		KeepPdf::to('kassenbericht-lang.pdf', $bytes);

		$this->assertGreaterThanOrEqual(2, preg_match_all('#/Type /Page\b#', $bytes));
	}

	public function testDateinameAusDemZeitraum(): void {
		$config = $this->createMock(IAppConfig::class);
		$renderer = new KassenberichtPdfRenderer($this->createMock(KassenberichtRenderer::class), new ReportPdfFactory($config, $this->l10n()), ClubCurrencyStub::of($this), $this->l10n());
		$period = new Period();
		$period->setLabel('2025/26');
		$this->assertSame('Kassenbericht-2025-26.pdf', $renderer->fileName($period));
	}
}
