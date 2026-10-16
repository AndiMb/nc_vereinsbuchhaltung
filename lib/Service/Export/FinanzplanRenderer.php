<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\BudgetOverviewService;
use OCA\Vereinsbuchhaltung\Service\BudgetTree;
use OCP\IConfig;
use OCP\IL10N;

/**
 * Der Soll-Ist-Vergleich als druckfertige HTML-Seite – für den Vorstand.
 *
 * Dieselben Zahlen wie der Finanzplan am Bildschirm und der CSV-Export
 * ({@see BudgetOverviewService}). Standardmäßig bis zur Planungsebene
 * aufgeklappt, wahlweise bis zu einer festen Tiefe oder vollständig.
 * Auffälligkeiten stehen als Zeichen in der Zeile, damit sie auch auf einem
 * Schwarzweiß-Ausdruck erkennbar bleiben.
 */
class FinanzplanRenderer {

	/** Zeichen der Auffälligkeiten, in der Reihenfolge der Legende. */
	private const MARKS = [
		BudgetTree::FLAG_OVERRUN => '▲',
		BudgetTree::FLAG_UNPLANNED => '○',
		BudgetTree::FLAG_UNPLANNED_BELOW => '◌',
		BudgetTree::FLAG_CONFLICT => '⚠',
	];

	public function __construct(
		private BudgetOverviewService $overview,
		private IConfig $config,
		private IL10N $l10n,
	) {
	}

	/**
	 * @param string $depth `plan` (Planungsebene), Zahl (Tiefe ab 0) oder `all`
	 */
	public function render(string $userId, ?int $periodId, string $depth = 'plan'): string {
		$data = $this->overview->build($userId, $periodId);
		$period = $data['period'];
		$nodes = BudgetOverviewService::visibleAtDepth($data['nodes'], $depth);

		$clubName = $this->config->getAppValue(Application::APP_ID, 'club_name', '');
		$heading = $this->l10n->t('Soll-Ist-Vergleich %s', [$period->getLabel()]);
		$meta = [
			PrintableReportPage::escape($this->depthLabel($depth)),
			PrintableReportPage::escape($this->l10n->t('Erstellt am %s', [ReportFormat::date(date('Y-m-d'))])),
		];

		$h = PrintableReportPage::printHint($this->l10n->t('Zum Drucken oder Als-PDF-Speichern: <strong>Strg+P</strong> (Mac: ⌘P) im Browser.'));
		$h .= PrintableReportPage::header(null, $clubName, $heading, implode(' · ', $meta));
		if ($data['conflictCount'] > 0) {
			$h .= '<p><strong>⚠ ' . PrintableReportPage::escape($this->l10n->n(
				'%n Plankonflikt: In einem Zweig ist auf mehreren Ebenen geplant, die Summen zählen diese Pläne doppelt.',
				'%n Plankonflikte: In einem Zweig ist auf mehreren Ebenen geplant, die Summen zählen diese Pläne doppelt.',
				$data['conflictCount'],
			)) . '</strong></p>';
		}

		$t = $data['totals'];
		$h .= $this->section($this->l10n->t('Einnahmen'), $nodes, $data['accounts'], 'income', $t['planIncome'], $t['actualIncome']);
		$h .= $this->section($this->l10n->t('Ausgaben'), $nodes, $data['accounts'], 'expense', $t['planExpense'], $t['actualExpense']);

		$h .= '<section><table>';
		$h .= '<tr class="result"><td>' . $this->l10n->t('Ergebnis') . '</td>'
			. '<td class="num">' . ReportFormat::cents($t['planIncome'] - $t['planExpense']) . '</td>'
			. '<td class="num">' . ReportFormat::cents($t['actualIncome'] - $t['actualExpense']) . '</td>'
			. '<td class="num">' . ReportFormat::cents(($t['actualIncome'] - $t['actualExpense']) - ($t['planIncome'] - $t['planExpense'])) . '</td>'
			. '<td class="num"></td></tr>';
		$h .= '</table></section>';
		$h .= $this->legend();

		return PrintableReportPage::document(($clubName !== '' ? $clubName . ' – ' : '') . $heading, $h);
	}

	/**
	 * @param list<array<string, mixed>> $nodes
	 * @param array<int, \OCA\Vereinsbuchhaltung\Db\Account> $accounts
	 */
	private function section(string $title, array $nodes, array $accounts, string $type, int $plan, int $actual): string {
		$h = '<section><h2>' . PrintableReportPage::escape($title) . '</h2><table>';
		$h .= '<tr><th>' . $this->l10n->t('Konto') . '</th>'
			. '<th class="num">' . $this->l10n->t('Plan') . '</th>'
			. '<th class="num">' . $this->l10n->t('Ist') . '</th>'
			. '<th class="num">' . $this->l10n->t('Differenz') . '</th>'
			. '<th class="num">' . $this->l10n->t('Ist/Plan') . '</th></tr>';
		foreach ($nodes as $n) {
			if ($n['type'] !== $type) {
				continue;
			}
			$account = $accounts[$n['id']];
			$marks = '';
			foreach (self::MARKS as $flag => $mark) {
				if (in_array($flag, $n['flags'], true)) {
					$marks .= ' ' . $mark;
				}
			}
			$label = trim($account->getNumber() . ' ' . $account->getName());
			$style = $n['depth'] > 0 ? ' style="padding-left:' . (4 + min((int)$n['depth'], 6) * 14) . 'px"' : '';
			$strong = $n['depth'] === 0;
			$h .= '<tr><td' . $style . '>'
				. ($strong ? '<strong>' : '') . PrintableReportPage::escape($label) . ($strong ? '</strong>' : '')
				. ($marks !== '' ? '<span style="white-space:nowrap">' . $marks . '</span>' : '') . '</td>'
				. '<td class="num">' . ReportFormat::cents($n['sum']['plan']) . '</td>'
				. '<td class="num">' . ReportFormat::cents($n['sum']['actual']) . '</td>'
				. '<td class="num">' . ($n['comparable'] ? ReportFormat::cents($n['sum']['actual'] - $n['sum']['plan']) : '') . '</td>'
				. '<td class="num">' . ($n['comparable'] ? $this->percent($n['sum']['plan'], $n['sum']['actual']) : '') . '</td></tr>';
		}
		$h .= '<tr class="sum"><td>' . PrintableReportPage::escape($this->l10n->t('Summe %s', [$title])) . '</td>'
			. '<td class="num">' . ReportFormat::cents($plan) . '</td>'
			. '<td class="num">' . ReportFormat::cents($actual) . '</td>'
			. '<td class="num">' . ReportFormat::cents($actual - $plan) . '</td>'
			. '<td class="num">' . $this->percent($plan, $actual) . '</td></tr>';
		return $h . '</table></section>';
	}

	private function percent(int $plan, int $actual): string {
		if ($plan === 0) {
			return '–';
		}
		return number_format($actual / $plan * 100, 0, ',', '.') . ' %';
	}

	private function depthLabel(string $depth): string {
		if ($depth === 'all') {
			return $this->l10n->t('Alle Ebenen');
		}
		if ($depth !== 'plan' && ctype_digit($depth)) {
			return $this->l10n->t('Bis Ebene %s', [(string)((int)$depth + 1)]);
		}
		return $this->l10n->t('Bis zur Planungsebene');
	}

	private function legend(): string {
		$texts = [
			BudgetTree::FLAG_OVERRUN => $this->l10n->t('Ausgaben über Plan'),
			BudgetTree::FLAG_UNPLANNED => $this->l10n->t('Ist ohne Plan'),
			BudgetTree::FLAG_UNPLANNED_BELOW => $this->l10n->t('ungeplante Buchungen in Unterkonten'),
			BudgetTree::FLAG_CONFLICT => $this->l10n->t('Plankonflikt'),
		];
		$parts = [];
		foreach (self::MARKS as $flag => $mark) {
			$parts[] = $mark . ' ' . PrintableReportPage::escape($texts[$flag]);
		}
		return '<p class="meta">' . implode(' · ', $parts) . '<br>'
			. PrintableReportPage::escape($this->l10n->t('Plan und Ist schließen alle Unterkonten ein.')) . '</p>';
	}
}
