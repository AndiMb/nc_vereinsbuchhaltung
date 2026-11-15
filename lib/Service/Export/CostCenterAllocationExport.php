<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\ClubClock;
use OCA\Vereinsbuchhaltung\Service\ClubCurrency;
use OCA\Vereinsbuchhaltung\Service\CsvFormatter;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Service\Report\CostCenterAllocationReportService;
use OCP\IAppConfig;
use OCP\IL10N;

/**
 * Der Bericht "Kostenstellen" (Issue #7) als druckfertige HTML-Seite und als
 * CSV-Datei – dieselben Zahlen wie in der Oberfläche, aus
 * {@see CostCenterAllocationReportService}.
 */
class CostCenterAllocationExport {

	/** UTF-8-BOM, damit Excel die Kodierung erkennt (wie CsvExportService). */
	private const BOM = "\xEF\xBB\xBF";

	public function __construct(
		private CostCenterAllocationReportService $report,
		private PeriodService $periods,
		private IAppConfig $config,
		private IL10N $l10n,
		private ClubClock $clock,
		private ClubCurrency $currency,
	) {
	}

	public function html(string $userId, ?int $periodId): string {
		$report = $this->report->report($userId, $periodId);
		$clubName = $this->config->getValueString(Application::APP_ID, 'club_name', '');
		$heading = $this->l10n->t('Kostenstellen');
		$meta = implode(' · ', [
			PrintableReportPage::escape($this->l10n->t('Zeitraum: %s', [$this->periodLabel($userId, $periodId)])),
			PrintableReportPage::escape($this->l10n->t('Erstellt am %s', [ReportFormat::date($this->clock->today())])),
		]);

		$h = PrintableReportPage::printHint($this->l10n->t('Zum Drucken oder Als-PDF-Speichern: <strong>Strg+P</strong> (Mac: ⌘P) im Browser.'));
		$h .= PrintableReportPage::header(null, $clubName, $heading, $meta);
		$h .= $this->requiredOpenSection($report['requiredOpen'] ?? []);

		foreach ($report['centers'] as $center) {
			$title = trim($center['code'] . ' – ' . $center['name'], ' –');
			if (!$center['active']) {
				$title .= ' (' . $this->l10n->t('inaktiv') . ')';
			}
			$h .= $this->block($title, $center, $center['plan'] ?? null);
		}
		$h .= $this->block($this->l10n->t('Ohne Kostenstelle'), $report['unassigned']);

		$h .= '<section><table>'
			. $this->sumRow($this->l10n->t('Einnahmen gesamt'), $report['total']['incomeCents'])
			. $this->sumRow($this->l10n->t('Ausgaben gesamt'), $report['total']['expenseCents'])
			. $this->sumRow($this->l10n->t('Ergebnis gesamt'), $report['total']['resultCents'])
			. '</table>';
		$h .= '<p>' . PrintableReportPage::escape(
			$report['reconciled']
				? $this->l10n->t('Das Ergebnis entspricht dem Ergebnis laut Kontensalden.')
				: $this->l10n->t(
					'Das Ergebnis weicht vom Ergebnis laut Kontensalden (%s) ab.',
					[$this->currency->format((int)$report['ledgerResultCents'])],
				)
		) . '</p></section>';

		return PrintableReportPage::document(($clubName !== '' ? $clubName . ' – ' : '') . $heading, $h);
	}

	/**
	 * Format: Kostenstelle;Art;Konto-Nr.;Konto;Betrag (EUR), dazu die Schlusszeile "Gesamt".
	 */
	public function csv(string $userId, ?int $periodId): CsvFile {
		$report = $this->report->report($userId, $periodId);
		$csv = self::BOM;
		$csv .= CsvFormatter::line(['Kostenstelle', 'Art', 'Konto-Nr.', 'Konto', 'Betrag (' . $this->currency->code() . ')']);

		// Pflichtkonten mit offenem Betrag (Issue #334) vorweg.
		foreach ($report['requiredOpen'] ?? [] as $row) {
			$csv .= CsvFormatter::line(['Pflichtkonto offen', $row['kind'] === 'income' ? 'Einnahme' : 'Ausgabe', $row['number'], $row['name'], ReportFormat::money($row['amountCents'] / 100)]);
		}

		$blocks = [];
		foreach ($report['centers'] as $center) {
			$blocks[] = [trim($center['code'] . ' ' . $center['name']), $center];
		}
		$blocks[] = ['Ohne Kostenstelle', $report['unassigned']];
		foreach ($blocks as [$label, $block]) {
			// Budget der Kostenstelle (Issue #335): zwei Zeilen ohne Konto, vor ihren Ist-Zeilen.
			if (($block['plan'] ?? null) !== null) {
				$csv .= CsvFormatter::line([$label, 'Plan Einnahmen', '', '', ReportFormat::money($block['plan']['incomeCents'] / 100)]);
				$csv .= CsvFormatter::line([$label, 'Plan Ausgaben', '', '', ReportFormat::money($block['plan']['expenseCents'] / 100)]);
			}
			foreach (['Einnahme' => $block['income'], 'Ausgabe' => $block['expense']] as $kind => $rows) {
				foreach ($rows as $row) {
					$csv .= CsvFormatter::line([$label, $kind, $row['number'], $row['name'], ReportFormat::money($row['amountCents'] / 100)]);
				}
			}
		}
		$csv .= CsvFormatter::line(['Gesamt', 'Ergebnis', '', '', ReportFormat::money($report['total']['resultCents'] / 100)]);

		$slug = PeriodService::isSelected($periodId)
			? AttachmentArchive::slug($this->periods->find($userId, (int)$periodId)->getLabel())
			: 'alle_zeitraeume';
		return new CsvFile($csv, 'kostenstellen_' . $slug . '.csv');
	}

	private function periodLabel(string $userId, ?int $periodId): string {
		return PeriodService::isSelected($periodId)
			? $this->periods->find($userId, (int)$periodId)->getLabel()
			: $this->l10n->t('alle Zeiträume');
	}

	/**
	 * Ein Block des Berichts: Einnahmen und Ausgaben nach Konten, darunter das Ergebnis.
	 *
	 * @param array<string, mixed> $block
	 * @param array{incomeCents:int, expenseCents:int, resultCents:int}|null $plan Budget der Kostenstelle (Issue #335)
	 */
	private function block(string $title, array $block, ?array $plan = null): string {
		if ($block['income'] === [] && $block['expense'] === [] && $plan === null) {
			return '';
		}
		$h = '<section><h2>' . PrintableReportPage::escape($title) . '</h2>' . $this->planTable($block, $plan);
		if ($block['income'] === [] && $block['expense'] === []) {
			// Geplant, aber noch nichts gebucht: die Plan-Tabelle sagt schon alles.
			return $h . '</section>';
		}
		$h .= '<table>';
		foreach ([$this->l10n->t('Einnahmen') => 'income', $this->l10n->t('Ausgaben') => 'expense'] as $label => $key) {
			if ($block[$key] === []) {
				continue;
			}
			$h .= '<tr><th colspan="2">' . PrintableReportPage::escape($label) . '</th>'
				. '<th class="num">' . PrintableReportPage::escape($this->currency->code()) . '</th></tr>';
			foreach ($block[$key] as $row) {
				$h .= '<tr><td class="nr">' . PrintableReportPage::escape($row['number']) . '</td>'
					. '<td>' . PrintableReportPage::escape($row['name']) . '</td>'
					. '<td class="num">' . ReportFormat::money($row['amountCents'] / 100) . '</td></tr>';
			}
			$h .= $this->sumRow($this->l10n->t('Summe %s', [$label]), $block[$key . 'Cents']);
		}
		$h .= $this->sumRow($this->l10n->t('Ergebnis'), $block['resultCents']);
		return $h . '</table></section>';
	}

	/**
	 * Abschnitt „Pflichtkonten mit offenem Betrag“ (Issue #334); leer, wenn es keine gibt.
	 *
	 * @param list<array{accountId:int, number:string, name:string, amountCents:int, kind:string}> $rows
	 */
	private function requiredOpenSection(array $rows): string {
		if ($rows === []) {
			return '';
		}
		$h = '<section><h2>' . PrintableReportPage::escape($this->l10n->t('Pflichtkonten mit offenem Betrag')) . '</h2>'
			. '<p>' . PrintableReportPage::escape($this->l10n->t('Für diese Konten ist eine Kostenstelle vorgesehen. Die genannten Beträge sind noch keiner Kostenstelle zugeordnet.')) . '</p><table>';
		foreach ($rows as $row) {
			$h .= '<tr><td class="nr">' . PrintableReportPage::escape($row['number']) . '</td>'
				. '<td>' . PrintableReportPage::escape($row['name']) . '</td>'
				. '<td class="num">' . ReportFormat::money($row['amountCents'] / 100) . '</td></tr>';
		}
		return $h . '</table></section>';
	}

	/**
	 * Plan, Ist und Abweichung (Ist − Plan) für Einnahmen, Ausgaben und Ergebnis; leer ohne Plan.
	 *
	 * @param array<string, mixed> $block
	 * @param array{incomeCents:int, expenseCents:int, resultCents:int}|null $plan
	 */
	private function planTable(array $block, ?array $plan): string {
		if ($plan === null) {
			return '';
		}
		$h = '<table><tr><th></th>';
		foreach ([$this->l10n->t('Plan'), $this->l10n->t('Ist'), $this->l10n->t('Abweichung')] as $head) {
			$h .= '<th class="num">' . PrintableReportPage::escape($head) . '</th>';
		}
		$h .= '</tr>';
		foreach ([$this->l10n->t('Einnahmen') => 'incomeCents', $this->l10n->t('Ausgaben') => 'expenseCents', $this->l10n->t('Ergebnis') => 'resultCents'] as $label => $key) {
			$actual = (int)$block[$key];
			$h .= '<tr><td>' . PrintableReportPage::escape($label) . '</td>'
				. '<td class="num">' . ReportFormat::money($plan[$key] / 100) . '</td>'
				. '<td class="num">' . ReportFormat::money($actual / 100) . '</td>'
				. '<td class="num">' . ReportFormat::money(($actual - $plan[$key]) / 100) . '</td></tr>';
		}
		return $h . '</table>';
	}

	private function sumRow(string $label, int $cents): string {
		return '<tr class="sum"><td colspan="2">' . PrintableReportPage::escape($label) . '</td>'
			. '<td class="num">' . ReportFormat::money($cents / 100) . '</td></tr>';
	}
}
