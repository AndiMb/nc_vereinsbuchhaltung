<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Service\BrandingService;
use OCA\Vereinsbuchhaltung\Service\FiscalYear;
use OCA\Vereinsbuchhaltung\Service\LedgerAggregator;
use OCP\IConfig;
use OCP\IURLGenerator;

/**
 * Der Kurzbericht für die Vorstandssitzung.
 *
 * Anders als der Kassenbericht ist er nicht jahres-, sondern stichtagsbezogen:
 * „was ist seit der letzten Sitzung passiert". Deshalb Kontostände statt
 * Vermögensübersicht, Bewegungen statt Jahresrechnung, und vom Finanzplan nur
 * die Summenzeile – mit der vollen Tabelle wäre es kein Kurzbericht mehr.
 *
 * Optional im Corporate Design des Vereins (Logo und Akzentfarbe, siehe
 * {@see BrandingService}); der Kassenbericht bleibt bewusst schlicht.
 */
class KurzberichtRenderer {

	public function __construct(
		private AccountMapper $accountMapper,
		private JournalLineMapper $lineMapper,
		private BudgetMapper $budgetMapper,
		private BrandingService $branding,
		private IConfig $config,
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * Stichtag prüfen: unbrauchbare oder in der Zukunft liegende Angaben fallen
	 * auf den Jahresanfang zurück, damit der Bericht immer einen sinnvollen
	 * Zeitraum zeigt statt einer leeren Tabelle.
	 */
	public function normalizeSince(?string $since, string $today): string {
		if ($since === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) || $since >= $today) {
			return FiscalYear::start((int)date('Y'));
		}
		return $since;
	}

	public function render(string $userId, ?string $since = null): string {
		$today = date('Y-m-d');
		$since = $this->normalizeSince($since, $today);
		$beforeSince = date('Y-m-d', strtotime($since . ' -1 day'));

		$accounts = $this->accountMapper->findAll($userId);
		$moveSums = $this->lineMapper->sumByAccount($userId, $since, $today);
		$cumStart = $this->lineMapper->sumByAccount($userId, null, $beforeSince);
		$cumEnd = $this->lineMapper->sumByAccount($userId, null, $today);

		$vermoegen = LedgerAggregator::wealthRows($accounts, $cumStart, $cumEnd);
		$erfolg = LedgerAggregator::incomeExpense($accounts, $moveSums);

		$currentYear = (int)date('Y');
		$plan = $this->budgetMapper->findByYear($userId, $currentYear);
		$soll = null;
		if ($plan !== []) {
			$yearMoveSums = $this->lineMapper->sumByAccount($userId, FiscalYear::start($currentYear), $today);
			$soll = LedgerAggregator::planActual($accounts, $yearMoveSums, $plan);
		}

		$clubName = $this->config->getAppValue(Application::APP_ID, 'club_name', '');
		$accent = PrintableReportPage::accentColor($this->config->getAppValue(Application::APP_ID, 'brand_color', ''));
		$logoUrl = $this->branding->hasLogo()
			? $this->urlGenerator->linkToRoute('vereinsbuchhaltung.branding.view')
			: null;
		$title = ($clubName !== '' ? $clubName . ' – ' : '') . 'Kurzbericht zur Vorstandssitzung';

		$h = PrintableReportPage::printHint();
		$h .= PrintableReportPage::header(
			$logoUrl,
			$clubName,
			'Kurzbericht zur Vorstandssitzung',
			'Zeitraum seit ' . ReportFormat::date($since) . ' · Erstellt am ' . ReportFormat::date($today),
		);
		$h .= $this->balanceSection($vermoegen, $beforeSince);
		$h .= $this->movementSection($erfolg, $since);
		if ($soll !== null) {
			$h .= $this->planSummarySection($soll, $currentYear);
		}

		return PrintableReportPage::document($title, $h, $accent);
	}

	/** @param array{rows: list<array{account:mixed, start:int, end:int}>, startCents:int, endCents:int} $vermoegen */
	private function balanceSection(array $vermoegen, string $beforeSince): string {
		$h = '<section><h2>Kontostände (Geldkonten)</h2><table>';
		$h .= '<tr><th>Konto</th><th class="num">Bestand ' . ReportFormat::date($beforeSince)
			. '</th><th class="num">Bestand heute</th><th class="num">Veränderung</th></tr>';
		foreach ($vermoegen['rows'] as $row) {
			$label = trim($row['account']->getNumber() . ' ' . $row['account']->getName());
			$h .= '<tr><td>' . PrintableReportPage::escape($label) . '</td>'
				. '<td class="num">' . ReportFormat::cents($row['start']) . '</td>'
				. '<td class="num">' . ReportFormat::cents($row['end']) . '</td>'
				. '<td class="num">' . ReportFormat::cents($row['end'] - $row['start']) . '</td></tr>';
		}
		$h .= '<tr class="sum"><td>Gesamt</td>'
			. '<td class="num">' . ReportFormat::cents($vermoegen['startCents']) . '</td>'
			. '<td class="num">' . ReportFormat::cents($vermoegen['endCents']) . '</td>'
			. '<td class="num">' . ReportFormat::cents($vermoegen['endCents'] - $vermoegen['startCents']) . '</td></tr>';
		return $h . '</table></section>';
	}

	/** @param array<string, mixed> $erfolg aus LedgerAggregator::incomeExpense() */
	private function movementSection(array $erfolg, string $since): string {
		$h = '<section><h2>Bewegungen seit ' . ReportFormat::date($since) . '</h2><table>';
		$h .= '<tr><th colspan="2">Einnahmen</th><th class="num">Betrag</th></tr>';
		$h .= KassenberichtRenderer::accountRows($erfolg['income']);
		$h .= '<tr class="sum"><td colspan="2">Summe Einnahmen</td><td class="num">' . ReportFormat::cents($erfolg['incomeCents']) . '</td></tr>';
		$h .= '<tr><th colspan="2">Ausgaben</th><th class="num">Betrag</th></tr>';
		$h .= KassenberichtRenderer::accountRows($erfolg['expense']);
		$h .= '<tr class="sum"><td colspan="2">Summe Ausgaben</td><td class="num">' . ReportFormat::cents($erfolg['expenseCents']) . '</td></tr>';
		$h .= '<tr class="result"><td colspan="2">Ergebnis seit Stichtag</td><td class="num">' . ReportFormat::cents($erfolg['resultCents']) . '</td></tr>';
		return $h . '</table></section>';
	}

	/** @param array<string, mixed> $soll aus LedgerAggregator::planActual() */
	private function planSummarySection(array $soll, int $year): string {
		$h = '<section><h2>Finanzplan ' . $year . ' (Kurzfassung)</h2><table>';
		$h .= '<tr><th></th><th class="num">Plan</th><th class="num">Ist (bisher)</th></tr>';
		$h .= '<tr><td>Einnahmen</td><td class="num">' . ReportFormat::cents($soll['planIncomeCents'])
			. '</td><td class="num">' . ReportFormat::cents($soll['actualIncomeCents']) . '</td></tr>';
		$h .= '<tr><td>Ausgaben</td><td class="num">' . ReportFormat::cents($soll['planExpenseCents'])
			. '</td><td class="num">' . ReportFormat::cents($soll['actualExpenseCents']) . '</td></tr>';
		$h .= '<tr class="result"><td>Ergebnis</td>'
			. '<td class="num">' . ReportFormat::cents($soll['planIncomeCents'] - $soll['planExpenseCents']) . '</td>'
			. '<td class="num">' . ReportFormat::cents($soll['actualIncomeCents'] - $soll['actualExpenseCents']) . '</td></tr>';
		return $h . '</table></section>';
	}
}
