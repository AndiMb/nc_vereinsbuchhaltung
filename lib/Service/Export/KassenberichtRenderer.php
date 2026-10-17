<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\Period;
use OCA\Vereinsbuchhaltung\Service\BudgetOverviewService;
use OCA\Vereinsbuchhaltung\Service\LedgerAggregator;
use OCA\Vereinsbuchhaltung\Service\PeriodRule;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Service\ReportService;
use OCP\IConfig;
use OCP\IL10N;

/**
 * Der Kassenbericht für die Mitgliederversammlung als druckfertige HTML-Seite.
 *
 * Kein Server-PDF: der Browser druckt oder speichert als PDF. Das spart eine
 * PDF-Bibliothek samt Schriftarten im Auslieferungspaket und funktioniert auf
 * jeder Instanz gleich.
 *
 * Der Bericht enthält bewusst auch eine Vollständigkeitsangabe (Anzahl und
 * Lückenlosigkeit der Buchungsnummern) und Unterschriftszeilen – das sind die
 * Angaben, nach denen eine Kassenprüfung als Erstes fragt.
 */
class KassenberichtRenderer {

	public function __construct(
		private AccountMapper $accountMapper,
		private JournalMapper $journalMapper,
		private JournalLineMapper $lineMapper,
		private BudgetOverviewService $budgetOverview,
		private PeriodService $periods,
		private ReportService $reportService,
		private IConfig $config,
		private IL10N $l10n,
	) {
	}

	public function render(string $userId, Period $period): string {
		$from = $period->getStartDate();
		$to = $period->getEndDate();
		// Anfangsbestand ist der kumulierte Stand am Vorabend des Zeitraums.
		// Beim allerersten Geschäftsjahr gibt es keinen Vorgänger; dann tut es
		// der Tag davor, an dem definitionsgemäß noch nichts gebucht ist.
		$previous = $this->periods->previous($userId, $period);
		$prevTo = $previous?->getEndDate() ?? PeriodRule::previousDay($from);

		$accounts = $this->accountMapper->findAll($userId);
		$moveSums = $this->lineMapper->sumByAccount($userId, $from, $to);
		$cumEnd = $this->lineMapper->sumByAccount($userId, null, $to);
		$cumStart = $this->lineMapper->sumByAccount($userId, null, $prevTo);

		$erfolg = LedgerAggregator::incomeExpense($accounts, $moveSums);
		$vermoegen = LedgerAggregator::wealthRows($accounts, $cumStart, $cumEnd);
		$budget = $this->budgetOverview->build($userId, (int)$period->getId());
		$hasPlan = array_filter($budget['nodes'], static fn (array $n): bool => $n['isPlanItem']) !== [];

		$clubName = $this->config->getAppValue(Application::APP_ID, 'club_name', '');
		$title = ($clubName !== '' ? $clubName . ' – ' : '') . $this->l10n->t('Kassenbericht %s', [$period->getLabel()]);

		$h = PrintableReportPage::printHint($this->l10n->t('Zum Drucken oder Als-PDF-Speichern: <strong>Strg+P</strong> (Mac: ⌘P) im Browser.'));
		$h .= PrintableReportPage::header(
			null,
			$clubName,
			$this->l10n->t('Kassenbericht für das Geschäftsjahr %s', [$period->getLabel()]),
			$this->l10n->t('Erstellt am %s', [ReportFormat::date(date('Y-m-d'))]) . ' · ' . PrintableReportPage::escape($this->closeNote($period)),
		);
		$h .= $this->wealthSection($vermoegen, $from, $to);
		$h .= $this->resultSection($erfolg);
		$h .= $this->sphereSection($userId, (int)$period->getId());
		if ($hasPlan) {
			$h .= $this->planSection($budget);
		}
		$h .= '<section><h2>' . $this->l10n->t('Vollständigkeit') . '</h2><p>'
			. PrintableReportPage::escape($this->numberingNote($userId, $period, $from, $to))
			. '</p></section>';
		$h .= $this->signatureSection();

		return PrintableReportPage::document($title, $h);
	}

	/**
	 * Die Stichtage stehen in den Spaltenköpfen, statt „01.01." und „31.12." zu
	 * unterstellen: ein Geschäftsjahr muss seit Issue #8 nicht mehr am
	 * Jahreswechsel beginnen.
	 *
	 * @param array{rows: list<array{account:mixed, start:int, end:int}>, startCents:int, endCents:int} $vermoegen
	 */
	private function wealthSection(array $vermoegen, string $from, string $to): string {
		$h = '<section><h2>' . $this->l10n->t('Vermögensübersicht (Geldkonten)') . '</h2><table>';
		$h .= '<tr><th>' . $this->l10n->t('Konto') . '</th><th class="num">' . $this->l10n->t('Bestand %s', [ReportFormat::date($from)]) . '</th><th class="num">' . $this->l10n->t('Bestand %s', [ReportFormat::date($to)]) . '</th><th class="num">' . $this->l10n->t('Veränderung') . '</th></tr>';
		foreach ($vermoegen['rows'] as $row) {
			$label = trim($row['account']->getNumber() . ' ' . $row['account']->getName());
			$h .= '<tr><td>' . PrintableReportPage::escape($label) . '</td>'
				. '<td class="num">' . ReportFormat::cents($row['start']) . '</td>'
				. '<td class="num">' . ReportFormat::cents($row['end']) . '</td>'
				. '<td class="num">' . ReportFormat::cents($row['end'] - $row['start']) . '</td></tr>';
		}
		$h .= '<tr class="sum"><td>' . $this->l10n->t('Gesamtvermögen') . '</td>'
			. '<td class="num">' . ReportFormat::cents($vermoegen['startCents']) . '</td>'
			. '<td class="num">' . ReportFormat::cents($vermoegen['endCents']) . '</td>'
			. '<td class="num">' . ReportFormat::cents($vermoegen['endCents'] - $vermoegen['startCents']) . '</td></tr>';
		return $h . '</table></section>';
	}

	/** @param array<string, mixed> $erfolg aus LedgerAggregator::incomeExpense() */
	private function resultSection(array $erfolg): string {
		$h = '<section><h2>' . $this->l10n->t('Einnahmen-/Ausgaben-Rechnung') . '</h2><table>';
		$h .= '<tr><th colspan="2">' . $this->l10n->t('Einnahmen') . '</th><th class="num">' . $this->l10n->t('Betrag') . '</th></tr>';
		$h .= self::accountRows($erfolg['income']);
		$h .= '<tr class="sum"><td colspan="2">' . $this->l10n->t('Summe Einnahmen') . '</td><td class="num">' . ReportFormat::cents($erfolg['incomeCents']) . '</td></tr>';
		$h .= '<tr><th colspan="2">' . $this->l10n->t('Ausgaben') . '</th><th class="num">' . $this->l10n->t('Betrag') . '</th></tr>';
		$h .= self::accountRows($erfolg['expense']);
		$h .= '<tr class="sum"><td colspan="2">' . $this->l10n->t('Summe Ausgaben') . '</td><td class="num">' . ReportFormat::cents($erfolg['expenseCents']) . '</td></tr>';
		$h .= '<tr class="result"><td colspan="2">' . $this->l10n->t('Jahresergebnis') . '</td><td class="num">' . ReportFormat::cents($erfolg['resultCents']) . '</td></tr>';
		return $h . '</table></section>';
	}

	/**
	 * Kontozeilen einer Erfolgsseite. Konten ohne Bewegung bleiben weg – auf die
	 * Summen wirkt sich das nicht aus, sie steuern null bei.
	 *
	 * @param list<array{account:mixed, cents:int}> $rows
	 */
	public static function accountRows(array $rows): string {
		$h = '';
		foreach ($rows as $row) {
			if ($row['cents'] === 0) {
				continue;
			}
			$h .= '<tr><td class="nr">' . PrintableReportPage::escape((string)$row['account']->getNumber()) . '</td>'
				. '<td>' . PrintableReportPage::escape((string)$row['account']->getName()) . '</td>'
				. '<td class="num">' . ReportFormat::cents($row['cents']) . '</td></tr>';
		}
		return $h;
	}

	private function sphereSection(string $userId, int $periodId): string {
		$report = $this->reportService->sphereReport($userId, $periodId);
		$h = '<section><h2>' . $this->l10n->t('Sphärenübersicht (steuerlich)') . '</h2><table>';
		$h .= '<tr><th>' . $this->l10n->t('Sphäre') . '</th><th class="num">' . $this->l10n->t('Einnahmen') . '</th><th class="num">' . $this->l10n->t('Ausgaben') . '</th><th class="num">' . $this->l10n->t('Ergebnis') . '</th></tr>';
		$h .= self::sphereRows($report['spheres']);
		$h .= '</table>';

		$fg = $report['freigrenze'];
		if ($fg['incomeCents'] > 0) {
			$levelText = match ($fg['level']) {
				'over' => $this->l10n->t('überschritten'),
				'warn' => $this->l10n->t('nähert sich der Grenze'),
				default => $this->l10n->t('im grünen Bereich'),
			};
			$h .= '<p>' . $this->l10n->t(
				'Wirtschaftlicher Geschäftsbetrieb: %s € von %s € Freigrenze (%s %% – %s).',
				[
					ReportFormat::money((float)$fg['income']),
					ReportFormat::money((float)$fg['threshold']),
					(string)round(((float)$fg['ratio']) * 100),
					$levelText,
				],
			) . '</p>';
		}
		$h .= '<p class="meta">' . $this->l10n->t('Ersetzt keine steuerliche Beratung.') . '</p>';
		return $h . '</section>';
	}

	/**
	 * Sphärenzeilen. Sphären ohne Einnahmen und ohne Ausgaben bleiben weg –
	 * wie Konten ohne Bewegung in der Erfolgsrechnung. Der Sphärenbericht
	 * selbst liefert weiterhin alle Sphären, damit die Oberfläche offene
	 * Zuordnungen zeigen kann; im gedruckten Bericht wären Nullzeilen nur Länge.
	 *
	 * @param array<int, array<string, mixed>> $spheres aus ReportService::sphereReport()
	 */
	public static function sphereRows(array $spheres): string {
		$h = '';
		foreach ($spheres as $s) {
			$income = (float)$s['income'];
			$expense = (float)$s['expense'];
			if ($income === 0.0 && $expense === 0.0) {
				continue;
			}
			$h .= '<tr><td>' . PrintableReportPage::escape((string)$s['name']) . '</td>'
				. '<td class="num">' . ReportFormat::money($income) . ' €</td>'
				. '<td class="num">' . ReportFormat::money($expense) . ' €</td>'
				. '<td class="num">' . ReportFormat::money((float)$s['result']) . ' €</td></tr>';
		}
		return $h;
	}

	/**
	 * Soll-Ist-Vergleich bis zur Planungsebene, wie der Finanzplan-Druck in
	 * der Startansicht: Ein Konto mit Plan zeigt Plan und Ist samt aller
	 * Unterkonten, die Unterkonten selbst erscheinen nicht. Flach verglichen
	 * stünde der Plan sonst gegen 0 € Ist und das Ist der Unterkonten gegen
	 * 0 € Plan – eine Überschreitung wäre nicht zu erkennen.
	 *
	 * @param array<string, mixed> $budget aus BudgetOverviewService::build()
	 */
	private function planSection(array $budget): string {
		$nodes = self::planNodes($budget['nodes']);
		if ($nodes === []) {
			return '';
		}
		$names = [];
		foreach ($budget['accounts'] as $id => $account) {
			$names[$id] = (string)$account->getName();
		}
		$t = $budget['totals'];

		$h = '<section><h2>' . $this->l10n->t('Soll-Ist-Vergleich (Finanzplan)') . '</h2>';
		$h .= BudgetMarks::conflictNote($this->l10n, $budget['conflictCount']);
		$h .= '<table>';
		foreach (['income' => $this->l10n->t('Einnahmen'), 'expense' => $this->l10n->t('Ausgaben')] as $type => $title) {
			$rows = self::planRows($nodes, $names, $type);
			if ($rows === '') {
				continue;
			}
			$h .= '<tr><th colspan="2">' . $title . '</th><th class="num">' . $this->l10n->t('Plan') . '</th><th class="num">' . $this->l10n->t('Ist') . '</th><th class="num">' . $this->l10n->t('Differenz') . '</th></tr>';
			$h .= $rows;
		}
		$h .= $this->planTotalRow('sum', $this->l10n->t('Einnahmen'), $t['planIncome'], $t['actualIncome']);
		$h .= $this->planTotalRow('sum', $this->l10n->t('Ausgaben'), $t['planExpense'], $t['actualExpense']);
		$h .= $this->planTotalRow(
			'result',
			$this->l10n->t('Ergebnis'),
			$t['planIncome'] - $t['planExpense'],
			$t['actualIncome'] - $t['actualExpense'],
		);
		return $h . '</table>' . BudgetMarks::legend($this->l10n) . '</section>';
	}

	/**
	 * Die Knoten, die der Kassenbericht zeigt: bis zur Planungsebene, ohne
	 * Zeilen, die weder Plan noch Ist haben. Gefiltert wird über Σ, damit ein
	 * leerer Zweig ganz wegfällt.
	 *
	 * @param list<array<string, mixed>> $nodes aus BudgetOverviewService::build()
	 * @return list<array<string, mixed>>
	 */
	public static function planNodes(array $nodes): array {
		return array_values(array_filter(
			BudgetOverviewService::visibleAtDepth($nodes, 'plan'),
			static fn (array $n): bool => $n['sum']['plan'] !== 0 || $n['sum']['actual'] !== 0,
		));
	}

	/**
	 * Zeilen einer Seite (Einnahmen oder Ausgaben) des Soll-Ist-Vergleichs.
	 *
	 * Eingerückt nach Tiefe wie im Finanzplan-Druck. Ein Unterkonto, dessen
	 * Plan auf einem Vorfahren liegt, ist nur bei einem Plankonflikt sichtbar;
	 * eine eigene Differenz hat es nicht – dort stünde sonst das volle Ist als
	 * scheinbare Überschreitung.
	 *
	 * @param list<array<string, mixed>> $nodes aus planNodes()
	 * @param array<int, string> $names Kontoname je Konto-ID
	 */
	public static function planRows(array $nodes, array $names, string $type): string {
		$h = '';
		foreach ($nodes as $n) {
			if ($n['type'] !== $type) {
				continue;
			}
			$marks = BudgetMarks::marks($n['flags']);
			$style = $n['depth'] > 0 ? ' style="padding-left:' . (4 + min((int)$n['depth'], 6) * 14) . 'px"' : '';
			$h .= '<tr><td class="nr"' . $style . '>' . PrintableReportPage::escape((string)$n['number']) . '</td>'
				. '<td>' . PrintableReportPage::escape($names[$n['id']] ?? '')
				. ($marks !== '' ? '<span style="white-space:nowrap">' . $marks . '</span>' : '') . '</td>'
				. '<td class="num">' . ReportFormat::cents($n['sum']['plan']) . '</td>'
				. '<td class="num">' . ReportFormat::cents($n['sum']['actual']) . '</td>'
				. '<td class="num">' . ($n['comparable'] ? ReportFormat::cents($n['sum']['actual'] - $n['sum']['plan']) : '') . '</td></tr>';
		}
		return $h;
	}

	private function planTotalRow(string $class, string $label, int $plan, int $actual): string {
		return '<tr class="' . $class . '"><td colspan="2">' . $label . '</td>'
			. '<td class="num">' . ReportFormat::cents($plan) . '</td>'
			. '<td class="num">' . ReportFormat::cents($actual) . '</td>'
			. '<td class="num">' . ReportFormat::cents($actual - $plan) . '</td></tr>';
	}

	private function signatureSection(): string {
		$h = '<section class="signatures">';
		foreach ([$this->l10n->t('Schatzmeister/in'), $this->l10n->t('Kassenprüfer/in'), $this->l10n->t('Kassenprüfer/in')] as $rolle) {
			$h .= '<div><div class="line"></div>' . $this->l10n->t('Ort, Datum') . ' · ' . $rolle . '</div>';
		}
		return $h . '</section>';
	}

	private function closeNote(Period $period): string {
		if (!$period->isClosed()) {
			return $this->l10n->t('Das Geschäftsjahr %s ist noch nicht abgeschlossen.', [$period->getLabel()]);
		}
		return $this->l10n->t(
			'Das Geschäftsjahr %s wurde am %s von %s abgeschlossen (festgeschrieben).',
			[
				$period->getLabel(),
				ReportFormat::date(substr((string)$period->getClosedAt(), 0, 10)),
				$period->getClosedBy(),
			],
		);
	}

	/**
	 * Anzahl der Buchungen und Prüfung der Nummernfolge auf Lücken und
	 * Doppelungen – die Kernfrage jeder Kassenprüfung: ist der Bericht
	 * vollständig?
	 */
	private function numberingNote(string $userId, Period $period, string $from, string $to): string {
		$entryNos = [];
		foreach ($this->journalMapper->findAll($userId, 100000, 0, $from, $to) as $journal) {
			$no = $journal->getEntryNo();
			if ($no !== null) {
				$entryNos[] = (int)$no;
			}
		}
		sort($entryNos);
		$count = count($entryNos);
		if ($count === 0) {
			return $this->l10n->t('Keine Buchungen im Geschäftsjahr.');
		}

		$missing = [];
		$duplicates = [];
		$prev = null;
		foreach ($entryNos as $no) {
			if ($prev !== null) {
				if ($no === $prev) {
					$duplicates[] = $no;
				}
				for ($i = $prev + 1; $i < $no && count($missing) <= 20; $i++) {
					$missing[] = $i;
				}
			}
			$prev = $no;
		}

		$note = $this->l10n->t('%d Buchungen (Nr. %d–%d)', [$count, $entryNos[0], $entryNos[$count - 1]]);
		if (!$missing && !$duplicates) {
			return $note . ', ' . $this->l10n->t('Buchungsnummern lückenlos.');
		}

		$hints = [];
		if ($missing) {
			$hints[] = $this->l10n->t('fehlende Nummern: %s', [implode(', ', array_slice($missing, 0, 20)) . (count($missing) > 20 ? ' …' : '')]);
		}
		if ($duplicates) {
			$hints[] = $this->l10n->t('doppelte Nummern: %s', [implode(', ', array_unique($duplicates))]);
		}
		$note .= ' – ⚠ ' . implode('; ', $hints);

		// Seit der Nachnummerierung (EntryNumberService) schließt die App Lücken
		// beim Löschen selbst; in einem offenen Jahr kann hier also eigentlich
		// nichts mehr stehen. Bleibt eine Lücke in einem bereits festgeschriebenen
		// Jahr, stammt sie aus einer älteren Version – dann hilft nur
		// Wiedereröffnen und erneut Abschließen.
		if ($missing && $period->isClosed()) {
			$note .= ' ' . $this->l10n->t('(Lücken aus einer früheren Programmversion; sie verschwinden, wenn das Jahr einmal wiedereröffnet und erneut abgeschlossen wird)');
		}
		return $note;
	}
}
