<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\BudgetComparison;
use OCA\Vereinsbuchhaltung\Service\BudgetOverviewService;
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
 *
 * Mit Vergleichsperiode (Issue #114) folgen drei Spalten – Vergleichswert,
 * Δ und Δ % – und die Seite wird quer gedruckt, damit acht Zahlenspalten
 * nebeneinander passen. Δ trägt sein Vorzeichen, die Aussage hängt also
 * nicht an einer Farbe.
 */
class FinanzplanRenderer {

	public function __construct(
		private BudgetOverviewService $overview,
		private IConfig $config,
		private IL10N $l10n,
	) {
	}

	/**
	 * @param string $depth `plan` (Planungsebene), Zahl (Tiefe ab 0) oder `all`
	 * @param int $compareId Vergleichsperiode, 0 = keine
	 */
	public function render(string $userId, ?int $periodId, string $depth = 'plan', int $compareId = 0, string $basis = 'actual'): string {
		$data = $this->overview->build($userId, $periodId, $compareId, $basis);
		$period = $data['period'];
		$nodes = BudgetOverviewService::visibleAtDepth($data['nodes'], $depth);
		$cmp = $data['comparison'];
		$cmpHead = $cmp === null ? null : $this->compareHead($cmp['period']->getLabel(), $cmp['basis']);
		$cmpDeltaHead = $cmp === null ? null : $this->l10n->t('Δ zu %s', [$cmp['period']->getLabel()]);

		$clubName = $this->config->getAppValue(Application::APP_ID, 'club_name', '');
		$heading = $this->l10n->t('Soll-Ist-Vergleich %s', [$period->getLabel()]);
		$meta = [
			PrintableReportPage::escape($this->depthLabel($depth)),
			...($cmpHead === null ? [] : [PrintableReportPage::escape($this->l10n->t('Vergleich: %s', [$cmpHead]))]),
			PrintableReportPage::escape($this->l10n->t('Erstellt am %s', [ReportFormat::date(date('Y-m-d'))])),
		];

		$h = $cmp === null ? '' : '<style>@page { size: A4 landscape; } body { max-width: 297mm; }</style>';
		$h .= PrintableReportPage::printHint($this->l10n->t('Zum Drucken oder Als-PDF-Speichern: <strong>Strg+P</strong> (Mac: ⌘P) im Browser.'));
		$h .= PrintableReportPage::header(null, $clubName, $heading, implode(' · ', $meta));
		$h .= BudgetMarks::conflictNote($this->l10n, $data['conflictCount']);

		$t = $data['totals'];
		$h .= $this->section($this->l10n->t('Einnahmen'), $nodes, $data['accounts'], 'income', $t['planIncome'], $t['actualIncome'], $cmpHead, $cmpDeltaHead, $t['compareIncome']);
		$h .= $this->section($this->l10n->t('Ausgaben'), $nodes, $data['accounts'], 'expense', $t['planExpense'], $t['actualExpense'], $cmpHead, $cmpDeltaHead, $t['compareExpense']);

		$planResult = $t['planIncome'] - $t['planExpense'];
		$h .= '<section><table>';
		$h .= '<tr class="result"><td>' . $this->l10n->t('Ergebnis') . '</td>'
			. '<td class="num">' . ReportFormat::cents($planResult) . '</td>'
			. '<td class="num">' . ReportFormat::cents($t['actualIncome'] - $t['actualExpense']) . '</td>'
			. '<td class="num">' . ReportFormat::cents(($t['actualIncome'] - $t['actualExpense']) - $planResult) . '</td>'
			. '<td class="num"></td>'
			. ($cmpHead === null ? '' : $this->compareCells($planResult, $t['compareIncome'] - $t['compareExpense']))
			. '</tr>';
		$h .= '</table></section>';
		$h .= BudgetMarks::legend($this->l10n);

		return PrintableReportPage::document(($clubName !== '' ? $clubName . ' – ' : '') . $heading, $h);
	}

	/**
	 * @param list<array<string, mixed>> $nodes
	 * @param array<int, \OCA\Vereinsbuchhaltung\Db\Account> $accounts
	 */
	private function section(string $title, array $nodes, array $accounts, string $type, int $plan, int $actual, ?string $cmpHead = null, ?string $cmpDeltaHead = null, int $compare = 0): string {
		$h = '<section><h2>' . PrintableReportPage::escape($title) . '</h2><table>';
		$h .= '<tr><th>' . $this->l10n->t('Konto') . '</th>'
			. '<th class="num">' . $this->l10n->t('Plan') . '</th>'
			. '<th class="num">' . $this->l10n->t('Ist') . '</th>'
			. '<th class="num">' . $this->l10n->t('Differenz') . '</th>'
			. '<th class="num">' . $this->l10n->t('Ist/Plan') . '</th>';
		if ($cmpHead !== null) {
			$h .= '<th class="num">' . PrintableReportPage::escape($cmpHead) . '</th>'
				. '<th class="num">' . PrintableReportPage::escape((string)$cmpDeltaHead) . '</th>'
				. '<th class="num">Δ %</th>';
		}
		$h .= '</tr>';
		foreach ($nodes as $n) {
			if ($n['type'] !== $type) {
				continue;
			}
			$account = $accounts[$n['id']];
			$marks = BudgetMarks::marks($n['flags']);
			$label = trim($account->getNumber() . ' ' . $account->getName());
			$style = $n['depth'] > 0 ? ' style="padding-left:' . (4 + min((int)$n['depth'], 6) * 14) . 'px"' : '';
			$strong = $n['depth'] === 0;
			$h .= '<tr><td' . $style . '>'
				. ($strong ? '<strong>' : '') . PrintableReportPage::escape($label) . ($strong ? '</strong>' : '')
				. ($marks !== '' ? '<span style="white-space:nowrap">' . $marks . '</span>' : '') . '</td>'
				. '<td class="num">' . ReportFormat::cents($n['sum']['plan']) . '</td>'
				. '<td class="num">' . ReportFormat::cents($n['sum']['actual']) . '</td>'
				. '<td class="num">' . ($n['comparable'] ? ReportFormat::cents($n['sum']['actual'] - $n['sum']['plan']) : '') . '</td>'
				. '<td class="num">' . ($n['comparable'] ? $this->percent($n['sum']['plan'], $n['sum']['actual']) : '') . '</td>'
				. ($cmpHead === null ? '' : $this->compareCells($n['sum']['plan'], $n['sum']['compare'], $n['comparable']))
				. '</tr>';
		}
		$h .= '<tr class="sum"><td>' . PrintableReportPage::escape($this->l10n->t('Summe %s', [$title])) . '</td>'
			. '<td class="num">' . ReportFormat::cents($plan) . '</td>'
			. '<td class="num">' . ReportFormat::cents($actual) . '</td>'
			. '<td class="num">' . ReportFormat::cents($actual - $plan) . '</td>'
			. '<td class="num">' . $this->percent($plan, $actual) . '</td>'
			. ($cmpHead === null ? '' : $this->compareCells($plan, $compare))
			. '</tr>';
		return $h . '</table></section>';
	}

	/** Kopf der Vergleichsspalte: „Ist 2025“ bzw. „Plan 2025“. */
	private function compareHead(string $label, string $basis): string {
		return $basis === BudgetComparison::BASIS_PLAN
			? $this->l10n->t('Plan %s', [$label])
			: $this->l10n->t('Ist %s', [$label]);
	}

	/**
	 * Vergleichswert, Δ (Plan − Vergleich) und Δ % als Tabellenzellen. Unter
	 * einem Planposten bleiben Δ und Δ % leer, wie die Differenz.
	 */
	private function compareCells(int $plan, int $compare, bool $comparable = true): string {
		if (!$comparable) {
			return '<td class="num">' . ReportFormat::cents($compare) . '</td><td class="num"></td><td class="num"></td>';
		}
		$percent = BudgetComparison::percent($plan, $compare);
		return '<td class="num">' . ReportFormat::cents($compare) . '</td>'
			. '<td class="num">' . ReportFormat::signedMoney(BudgetComparison::delta($plan, $compare)) . ' €</td>'
			. '<td class="num">' . ($percent === null ? '–' : ReportFormat::signedPercent($percent) . ' %') . '</td>';
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
}
