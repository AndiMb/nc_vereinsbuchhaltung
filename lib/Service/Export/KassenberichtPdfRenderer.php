<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

use OCA\Vereinsbuchhaltung\Db\Period;
use OCA\Vereinsbuchhaltung\Service\AsciiFileName;
use OCA\Vereinsbuchhaltung\Service\ClubCurrency;
use OCA\Vereinsbuchhaltung\Service\Pdf\ReportPdf;
use OCA\Vereinsbuchhaltung\Service\Pdf\ReportPdfFactory;
use OCP\IL10N;

/**
 * Der Kassenbericht als PDF (Issue #364) – derselbe Inhalt wie die HTML-Seite
 * ({@see KassenberichtRenderer::collect()}), aber als festes Dokument für
 * Unterschrift, Versand und Ablage: Seitenzahlen in jedem Browser, keine URL
 * oder Druckdatum des Browsers am Rand, Tabellen mit wiederholtem Kopf und ein
 * Schlussblock (Vollständigkeit und Unterschriften), den kein Umbruch trennt.
 */
class KassenberichtPdfRenderer {

	public function __construct(
		private KassenberichtRenderer $report,
		private ReportPdfFactory $factory,
		private ClubCurrency $currency,
		private IL10N $l10n,
	) {
	}

	/** Dateiname des PDFs, z. B. „Kassenbericht-2026.pdf“. */
	public function fileName(Period $period): string {
		return 'Kassenbericht-' . AsciiFileName::of((string)$period->getLabel()) . '.pdf';
	}

	/**
	 * @param bool $compress false erzeugt lesbare Streams (nur für Tests)
	 * @return string PDF-Bytes
	 */
	public function render(string $userId, Period $period, bool $compress = true): string {
		$d = $this->report->collect($userId, $period);
		$heading = $this->l10n->t('Kassenbericht für das Geschäftsjahr %s', [$d['periodLabel']]);
		$title = ($d['clubName'] !== '' ? $d['clubName'] . ' – ' : '') . $this->l10n->t('Kassenbericht %s', [$d['periodLabel']]);

		$pdf = $this->factory->create($title, $d['created'], $compress);
		$pdf->titleBlock($d['clubName'], $heading, $d['created'] . ' · ' . $d['closeNote'], $this->factory->logo());

		$this->wealth($pdf, $d['vermoegen'], $d['from'], $d['to']);
		$this->result($pdf, $d['erfolg']);
		$this->spheres($pdf, $d['spheres']);
		if ($d['budget'] !== null) {
			$this->plan($pdf, $d['budget']);
		}
		// Vollständigkeit und Unterschriften gehören zusammen und bleiben auf einer Seite (#361).
		$pdf->keepTogether(function (ReportPdf $pdf) use ($d): void {
			$pdf->heading($this->l10n->t('Vollständigkeit'));
			$pdf->paragraph($d['numberingNote']);
			$pdf->signatures(array_map(fn (string $role): string => $this->l10n->t('Ort, Datum') . ' · ' . $role, $this->report->signatureRoles()));
		});
		return $pdf->output();
	}

	private function money(int $cents): string {
		return ReportFormat::cents($cents, $this->currency->symbol());
	}

	/** @param array{rows: list<array{account:mixed, start:int, end:int}>, startCents:int, endCents:int} $vermoegen */
	private function wealth(ReportPdf $pdf, array $vermoegen, string $from, string $to): void {
		$pdf->heading($this->l10n->t('Vermögensübersicht (Geldkonten)'));
		$rows = [];
		foreach ($vermoegen['rows'] as $row) {
			$rows[] = ['cells' => [
				trim($row['account']->getNumber() . ' ' . $row['account']->getName()),
				$this->money($row['start']),
				$this->money($row['end']),
				$this->money($row['end'] - $row['start']),
			]];
		}
		$rows[] = ['style' => ReportPdf::ROW_RESULT, 'cells' => [
			$this->l10n->t('Gesamtvermögen'),
			$this->money($vermoegen['startCents']),
			$this->money($vermoegen['endCents']),
			$this->money($vermoegen['endCents'] - $vermoegen['startCents']),
		]];
		$pdf->table([
			['label' => $this->l10n->t('Konto'), 'width' => 0.43],
			['label' => $this->l10n->t('Bestand %s', [ReportFormat::date($from)]), 'width' => 0.19, 'align' => 'R'],
			['label' => $this->l10n->t('Bestand %s', [ReportFormat::date($to)]), 'width' => 0.19, 'align' => 'R'],
			['label' => $this->l10n->t('Veränderung'), 'width' => 0.19, 'align' => 'R'],
		], $rows);
	}

	/** @param array<string, mixed> $erfolg aus LedgerAggregator::incomeExpense() */
	private function result(ReportPdf $pdf, array $erfolg): void {
		$pdf->heading($this->l10n->t('Einnahmen-/Ausgaben-Rechnung'));
		$rows = [];
		foreach (['income' => [$this->l10n->t('Einnahmen'), $this->l10n->t('Summe Einnahmen'), 'incomeCents'], 'expense' => [$this->l10n->t('Ausgaben'), $this->l10n->t('Summe Ausgaben'), 'expenseCents']] as $side => [$title, $sumLabel, $sumKey]) {
			$rows[] = ['style' => ReportPdf::ROW_HEAD, 'span' => [2, 1], 'cells' => [$title, $this->l10n->t('Betrag')]];
			foreach ($erfolg[$side] as $row) {
				// Konten ohne Bewegung bleiben weg wie in der HTML-Fassung; zu den Summen tragen sie nichts bei.
				if ($row['cents'] === 0) {
					continue;
				}
				$rows[] = ['cells' => [(string)$row['account']->getNumber(), (string)$row['account']->getName(), $this->money($row['cents'])]];
			}
			$rows[] = ['style' => ReportPdf::ROW_SUM, 'span' => [2, 1], 'cells' => [$sumLabel, $this->money($erfolg[$sumKey])]];
		}
		$rows[] = ['style' => ReportPdf::ROW_RESULT, 'span' => [2, 1], 'cells' => [$this->l10n->t('Jahresergebnis'), $this->money($erfolg['resultCents'])]];
		$pdf->table([
			['width' => 0.14],
			['width' => 0.64],
			['width' => 0.22, 'align' => 'R'],
		], $rows);
	}

	/** @param array<string, mixed> $report aus ReportService::sphereReport() */
	private function spheres(ReportPdf $pdf, array $report): void {
		$pdf->heading($this->l10n->t('Sphärenübersicht (steuerlich)'));
		$symbol = $this->currency->symbol();
		$rows = [];
		foreach ($report['spheres'] as $s) {
			$income = (float)$s['income'];
			$expense = (float)$s['expense'];
			if ($income === 0.0 && $expense === 0.0) {
				continue;
			}
			$rows[] = ['cells' => [
				(string)$s['name'],
				ReportFormat::money($income) . ' ' . $symbol,
				ReportFormat::money($expense) . ' ' . $symbol,
				ReportFormat::money((float)$s['result']) . ' ' . $symbol,
			]];
		}
		$pdf->table([
			['label' => $this->l10n->t('Sphäre'), 'width' => 0.43],
			['label' => $this->l10n->t('Einnahmen'), 'width' => 0.19, 'align' => 'R'],
			['label' => $this->l10n->t('Ausgaben'), 'width' => 0.19, 'align' => 'R'],
			['label' => $this->l10n->t('Ergebnis'), 'width' => 0.19, 'align' => 'R'],
		], $rows);
		$note = $this->report->freigrenzeNote($report);
		if ($note !== '') {
			$pdf->paragraph($note);
		}
		$pdf->paragraph($this->l10n->t('Ersetzt keine steuerliche Beratung.'), true);
	}

	/**
	 * Soll-Ist-Vergleich bis zur Planungsebene, wie in der HTML-Fassung
	 * ({@see KassenberichtRenderer::planNodes()}).
	 *
	 * @param array<string, mixed> $budget aus BudgetOverviewService::build()
	 */
	private function plan(ReportPdf $pdf, array $budget): void {
		$nodes = KassenberichtRenderer::planNodes($budget['nodes']);
		if ($nodes === []) {
			return;
		}
		$names = [];
		foreach ($budget['accounts'] as $id => $account) {
			$names[$id] = (string)$account->getName();
		}
		$pdf->heading($this->l10n->t('Soll-Ist-Vergleich (Finanzplan)'));
		$conflict = BudgetMarks::conflictText($this->l10n, (int)$budget['conflictCount']);
		if ($conflict !== '') {
			$pdf->paragraph($conflict, false, true);
		}
		$rows = [];
		foreach (['income' => $this->l10n->t('Einnahmen'), 'expense' => $this->l10n->t('Ausgaben')] as $type => $title) {
			$typeRows = [];
			foreach ($nodes as $n) {
				if ($n['type'] !== $type) {
					continue;
				}
				$typeRows[] = [
					'indent' => min((int)$n['depth'], 6) * 3.5,
					'cells' => [
						(string)$n['number'],
						($names[$n['id']] ?? '') . BudgetMarks::marks($n['flags']),
						$this->money($n['sum']['plan']),
						$this->money($n['sum']['actual']),
						$n['comparable'] ? $this->money($n['sum']['actual'] - $n['sum']['plan']) : '',
					],
				];
			}
			if ($typeRows === []) {
				continue;
			}
			$rows[] = ['style' => ReportPdf::ROW_HEAD, 'span' => [2, 1, 1, 1], 'cells' => [$title, $this->l10n->t('Plan'), $this->l10n->t('Ist'), $this->l10n->t('Differenz')]];
			array_push($rows, ...$typeRows);
		}
		$t = $budget['totals'];
		foreach ([
			[ReportPdf::ROW_SUM, $this->l10n->t('Einnahmen'), $t['planIncome'], $t['actualIncome']],
			[ReportPdf::ROW_SUM, $this->l10n->t('Ausgaben'), $t['planExpense'], $t['actualExpense']],
			[ReportPdf::ROW_RESULT, $this->l10n->t('Ergebnis'), $t['planIncome'] - $t['planExpense'], $t['actualIncome'] - $t['actualExpense']],
		] as [$style, $label, $planCents, $actualCents]) {
			$rows[] = ['style' => $style, 'span' => [2, 1, 1, 1], 'cells' => [$label, $this->money($planCents), $this->money($actualCents), $this->money($actualCents - $planCents)]];
		}
		$pdf->table([
			['width' => 0.13],
			['width' => 0.36],
			['width' => 0.17, 'align' => 'R'],
			['width' => 0.17, 'align' => 'R'],
			['width' => 0.17, 'align' => 'R'],
		], $rows);
		foreach (BudgetMarks::legendText($this->l10n) as $line) {
			$pdf->paragraph($line, true);
		}
	}
}
