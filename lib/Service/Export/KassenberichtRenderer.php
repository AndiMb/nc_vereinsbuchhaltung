<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\YearCloseMapper;
use OCA\Vereinsbuchhaltung\Service\FiscalYear;
use OCA\Vereinsbuchhaltung\Service\LedgerAggregator;
use OCA\Vereinsbuchhaltung\Service\ReportService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;

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
		private BudgetMapper $budgetMapper,
		private YearCloseMapper $yearCloseMapper,
		private ReportService $reportService,
		private IConfig $config,
	) {
	}

	public function render(string $userId, int $year): string {
		$from = FiscalYear::start($year);
		$to = FiscalYear::end($year);
		$prevTo = FiscalYear::end($year - 1);

		$accounts = $this->accountMapper->findAll($userId);
		$moveSums = $this->lineMapper->sumByAccount($userId, $from, $to);
		$cumEnd = $this->lineMapper->sumByAccount($userId, null, $to);
		$cumStart = $this->lineMapper->sumByAccount($userId, null, $prevTo);

		$erfolg = LedgerAggregator::incomeExpense($accounts, $moveSums);
		$vermoegen = LedgerAggregator::wealthRows($accounts, $cumStart, $cumEnd);
		$plan = $this->budgetMapper->findByYear($userId, $year);
		$soll = $plan !== [] ? LedgerAggregator::planActual($accounts, $moveSums, $plan) : null;

		$clubName = $this->config->getAppValue(Application::APP_ID, 'club_name', '');
		$title = ($clubName !== '' ? $clubName . ' – ' : '') . 'Kassenbericht ' . $year;

		$h = PrintableReportPage::printHint();
		$h .= PrintableReportPage::header(
			null,
			$clubName,
			'Kassenbericht für das Geschäftsjahr ' . $year,
			'Erstellt am ' . ReportFormat::date(date('Y-m-d')) . ' · ' . PrintableReportPage::escape($this->closeNote($year)),
		);
		$h .= $this->wealthSection($vermoegen);
		$h .= $this->resultSection($erfolg);
		$h .= $this->sphereSection($userId, $year);
		if ($soll !== null) {
			$h .= $this->planSection($soll);
		}
		$h .= '<section><h2>Vollständigkeit</h2><p>'
			. PrintableReportPage::escape($this->numberingNote($userId, $year, $from, $to))
			. '</p></section>';
		$h .= $this->signatureSection();

		return PrintableReportPage::document($title, $h);
	}

	/** @param array{rows: list<array{account:mixed, start:int, end:int}>, startCents:int, endCents:int} $vermoegen */
	private function wealthSection(array $vermoegen): string {
		$h = '<section><h2>Vermögensübersicht (Geldkonten)</h2><table>';
		$h .= '<tr><th>Konto</th><th class="num">Bestand 01.01.</th><th class="num">Bestand 31.12.</th><th class="num">Veränderung</th></tr>';
		foreach ($vermoegen['rows'] as $row) {
			$label = trim($row['account']->getNumber() . ' ' . $row['account']->getName());
			$h .= '<tr><td>' . PrintableReportPage::escape($label) . '</td>'
				. '<td class="num">' . ReportFormat::cents($row['start']) . '</td>'
				. '<td class="num">' . ReportFormat::cents($row['end']) . '</td>'
				. '<td class="num">' . ReportFormat::cents($row['end'] - $row['start']) . '</td></tr>';
		}
		$h .= '<tr class="sum"><td>Gesamtvermögen</td>'
			. '<td class="num">' . ReportFormat::cents($vermoegen['startCents']) . '</td>'
			. '<td class="num">' . ReportFormat::cents($vermoegen['endCents']) . '</td>'
			. '<td class="num">' . ReportFormat::cents($vermoegen['endCents'] - $vermoegen['startCents']) . '</td></tr>';
		return $h . '</table></section>';
	}

	/** @param array<string, mixed> $erfolg aus LedgerAggregator::incomeExpense() */
	private function resultSection(array $erfolg): string {
		$h = '<section><h2>Einnahmen-/Ausgaben-Rechnung</h2><table>';
		$h .= '<tr><th colspan="2">Einnahmen</th><th class="num">Betrag</th></tr>';
		$h .= self::accountRows($erfolg['income']);
		$h .= '<tr class="sum"><td colspan="2">Summe Einnahmen</td><td class="num">' . ReportFormat::cents($erfolg['incomeCents']) . '</td></tr>';
		$h .= '<tr><th colspan="2">Ausgaben</th><th class="num">Betrag</th></tr>';
		$h .= self::accountRows($erfolg['expense']);
		$h .= '<tr class="sum"><td colspan="2">Summe Ausgaben</td><td class="num">' . ReportFormat::cents($erfolg['expenseCents']) . '</td></tr>';
		$h .= '<tr class="result"><td colspan="2">Jahresergebnis</td><td class="num">' . ReportFormat::cents($erfolg['resultCents']) . '</td></tr>';
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

	private function sphereSection(string $userId, int $year): string {
		$report = $this->reportService->sphereReport($userId, $year);
		$h = '<section><h2>Sphärenübersicht (steuerlich)</h2><table>';
		$h .= '<tr><th>Sphäre</th><th class="num">Einnahmen</th><th class="num">Ausgaben</th><th class="num">Ergebnis</th></tr>';
		foreach ($report['spheres'] as $s) {
			$h .= '<tr><td>' . PrintableReportPage::escape((string)$s['name']) . '</td>'
				. '<td class="num">' . ReportFormat::money((float)$s['income']) . ' €</td>'
				. '<td class="num">' . ReportFormat::money((float)$s['expense']) . ' €</td>'
				. '<td class="num">' . ReportFormat::money((float)$s['result']) . ' €</td></tr>';
		}
		$h .= '</table>';

		$fg = $report['freigrenze'];
		if ($fg['incomeCents'] > 0) {
			$levelText = match ($fg['level']) {
				'over' => 'überschritten',
				'warn' => 'nähert sich der Grenze',
				default => 'im grünen Bereich',
			};
			$h .= '<p>Wirtschaftlicher Geschäftsbetrieb: ' . ReportFormat::money((float)$fg['income'])
				. ' € von ' . ReportFormat::money((float)$fg['threshold'])
				. ' € Freigrenze (' . round(((float)$fg['ratio']) * 100) . ' % – ' . $levelText . ').</p>';
		}
		$h .= '<p class="meta">Ersetzt keine steuerliche Beratung.</p>';
		return $h . '</section>';
	}

	/** @param array<string, mixed> $soll aus LedgerAggregator::planActual() */
	private function planSection(array $soll): string {
		// Konten ohne Plan und ohne Ist verlängern nur die Tabelle.
		$rows = array_values(array_filter(
			$soll['rows'],
			static fn (array $r): bool => $r['planCents'] !== 0 || $r['actualCents'] !== 0,
		));
		if ($rows === []) {
			return '';
		}
		usort($rows, static fn (array $a, array $b): int => strcmp(
			(string)$a['account']->getNumber(),
			(string)$b['account']->getNumber(),
		));

		$h = '<section><h2>Soll-Ist-Vergleich (Finanzplan)</h2><table>';
		$h .= '<tr><th colspan="2">Konto</th><th class="num">Plan</th><th class="num">Ist</th><th class="num">Differenz</th></tr>';
		foreach ($rows as $r) {
			$h .= '<tr><td class="nr">' . PrintableReportPage::escape((string)$r['account']->getNumber()) . '</td>'
				. '<td>' . PrintableReportPage::escape((string)$r['account']->getName()) . '</td>'
				. '<td class="num">' . ReportFormat::cents($r['planCents']) . '</td>'
				. '<td class="num">' . ReportFormat::cents($r['actualCents']) . '</td>'
				. '<td class="num">' . ReportFormat::cents($r['actualCents'] - $r['planCents']) . '</td></tr>';
		}
		$h .= self::planTotalRow('sum', 'Einnahmen', $soll['planIncomeCents'], $soll['actualIncomeCents']);
		$h .= self::planTotalRow('sum', 'Ausgaben', $soll['planExpenseCents'], $soll['actualExpenseCents']);
		$h .= self::planTotalRow(
			'result',
			'Ergebnis',
			$soll['planIncomeCents'] - $soll['planExpenseCents'],
			$soll['actualIncomeCents'] - $soll['actualExpenseCents'],
		);
		return $h . '</table></section>';
	}

	private static function planTotalRow(string $class, string $label, int $plan, int $actual): string {
		return '<tr class="' . $class . '"><td colspan="2">' . $label . '</td>'
			. '<td class="num">' . ReportFormat::cents($plan) . '</td>'
			. '<td class="num">' . ReportFormat::cents($actual) . '</td>'
			. '<td class="num">' . ReportFormat::cents($actual - $plan) . '</td></tr>';
	}

	private function signatureSection(): string {
		$h = '<section class="signatures">';
		foreach (['Schatzmeister/in', 'Kassenprüfer/in', 'Kassenprüfer/in'] as $rolle) {
			$h .= '<div><div class="line"></div>Ort, Datum · ' . $rolle . '</div>';
		}
		return $h . '</section>';
	}

	private function closeNote(int $year): string {
		try {
			$close = $this->yearCloseMapper->findByYear($year);
		} catch (DoesNotExistException) {
			return sprintf('Das Geschäftsjahr %d ist noch nicht abgeschlossen.', $year);
		}
		return sprintf(
			'Das Geschäftsjahr %d wurde am %s von %s abgeschlossen (festgeschrieben).',
			$year,
			ReportFormat::date(substr((string)$close->getClosedAt(), 0, 10)),
			$close->getClosedBy(),
		);
	}

	/**
	 * Anzahl der Buchungen und Prüfung der Nummernfolge auf Lücken und
	 * Doppelungen – die Kernfrage jeder Kassenprüfung: ist der Bericht
	 * vollständig?
	 */
	private function numberingNote(string $userId, int $year, string $from, string $to): string {
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
			return 'Keine Buchungen im Geschäftsjahr.';
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

		$note = sprintf('%d Buchungen (Nr. %d–%d)', $count, $entryNos[0], $entryNos[$count - 1]);
		if (!$missing && !$duplicates) {
			return $note . ', Buchungsnummern lückenlos.';
		}

		$hints = [];
		if ($missing) {
			$hints[] = 'fehlende Nummern: ' . implode(', ', array_slice($missing, 0, 20)) . (count($missing) > 20 ? ' …' : '');
		}
		if ($duplicates) {
			$hints[] = 'doppelte Nummern: ' . implode(', ', array_unique($duplicates));
		}
		$note .= ' – ⚠ ' . implode('; ', $hints);

		// Seit der Nachnummerierung (EntryNumberService) schließt die App Lücken
		// beim Löschen selbst; in einem offenen Jahr kann hier also eigentlich
		// nichts mehr stehen. Bleibt eine Lücke in einem bereits festgeschriebenen
		// Jahr, stammt sie aus einer älteren Version – dann hilft nur
		// Wiedereröffnen und erneut Abschließen.
		if ($missing && $this->isYearClosed($year)) {
			$note .= ' (Lücken aus einer früheren Programmversion; sie verschwinden, '
				. 'wenn das Jahr einmal wiedereröffnet und erneut abgeschlossen wird)';
		}
		return $note;
	}

	private function isYearClosed(int $year): bool {
		try {
			$this->yearCloseMapper->findByYear($year);
			return true;
		} catch (DoesNotExistException) {
			return false;
		}
	}
}
