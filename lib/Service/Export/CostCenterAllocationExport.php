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

		foreach ($report['centers'] as $center) {
			$title = trim($center['code'] . ' – ' . $center['name'], ' –');
			if (!$center['active']) {
				$title .= ' (' . $this->l10n->t('inaktiv') . ')';
			}
			$h .= $this->block($title, $center);
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

		$blocks = [];
		foreach ($report['centers'] as $center) {
			$blocks[] = [trim($center['code'] . ' ' . $center['name']), $center];
		}
		$blocks[] = ['Ohne Kostenstelle', $report['unassigned']];
		foreach ($blocks as [$label, $block]) {
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
	 */
	private function block(string $title, array $block): string {
		if ($block['income'] === [] && $block['expense'] === []) {
			return '';
		}
		$h = '<section><h2>' . PrintableReportPage::escape($title) . '</h2><table>';
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

	private function sumRow(string $label, int $cents): string {
		return '<tr class="sum"><td colspan="2">' . PrintableReportPage::escape($label) . '</td>'
			. '<td class="num">' . ReportFormat::money($cents / 100) . '</td></tr>';
	}
}
