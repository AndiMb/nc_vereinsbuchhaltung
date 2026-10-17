<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\Report\StructuredReportBuilder;
use OCA\Vereinsbuchhaltung\Service\Report\StructuredReportService;
use OCP\IConfig;
use OCP\IL10N;

/**
 * Der Gliederungsbericht als druckfertige HTML-Seite – etwa für den
 * Zuwendungsgeber oder die Mitgliederversammlung.
 *
 * Dieselbe Zeilenliste wie am Bildschirm und im CSV-Export
 * ({@see StructuredReportService}). Ab fünf Wertspalten wird quer gedruckt.
 * Warnungen (nicht oder doppelt erfasste Konten) stehen über der Tabelle,
 * damit sie auch auf dem Ausdruck nicht übersehen werden.
 */
class GliederungsberichtRenderer {

	public function __construct(
		private IConfig $config,
		private IL10N $l10n,
	) {
	}

	/**
	 * @param array<string, mixed> $report aus {@see StructuredReportService::build()}
	 */
	public function render(array $report): string {
		$period = $report['period'];
		$columns = $report['columns'];
		$clubName = $this->config->getAppValue(Application::APP_ID, 'club_name', '');
		$heading = $report['template']['name'];
		$meta = [
			PrintableReportPage::escape($period->getLabel()),
			PrintableReportPage::escape($this->l10n->t('Stand: %s', [ReportFormat::date(date('Y-m-d'))])),
		];

		$h = count($columns) > 4 ? '<style>@page { size: A4 landscape; } body { max-width: 297mm; }</style>' : '';
		$h .= '<style>'
			. 'tr.heading td { font-weight: 600; border-bottom: none; padding-top: 10px; }'
			. 'tr.heading.top td { font-size: 12pt; border-bottom: 1px solid #999; }'
			. 'tr.note td { font-style: italic; font-size: 9pt; color: #555; border-bottom: none; }'
			. 'tr.structural td { color: #555; }'
			. '.warn { border: 1px solid #c9a227; padding: 6px 10px; margin-bottom: 12px; font-size: 10pt; }'
			. '</style>';
		$h .= PrintableReportPage::printHint($this->l10n->t('Zum Drucken oder Als-PDF-Speichern: <strong>Strg+P</strong> (Mac: ⌘P) im Browser.'));
		$h .= PrintableReportPage::header(null, $clubName, $heading, implode(' · ', $meta));

		foreach ($report['warnings'] as $warning) {
			$h .= '<p class="warn">⚠ ' . PrintableReportPage::escape($this->warning($warning)) . '</p>';
		}

		$h .= '<section><table><tr><th class="nr">' . $this->l10n->t('Nr.') . '</th><th>' . $this->l10n->t('Bezeichnung') . '</th>';
		foreach ($columns as $c) {
			$h .= '<th class="num">' . PrintableReportPage::escape($c['label']) . '</th>';
		}
		$h .= '</tr>';

		foreach ($report['rows'] as $row) {
			$h .= $this->row($row, count($columns));
		}
		$h .= '</table></section>';

		$legend = [];
		if ($this->hasFlag($report['rows'], StructuredReportBuilder::FLAG_INACTIVE)) {
			$legend[] = '† ' . PrintableReportPage::escape($this->l10n->t('Konto nicht mehr aktiv'));
		}
		if ($this->hasFlag($report['rows'], StructuredReportBuilder::FLAG_DUPLICATE)) {
			$legend[] = '⚠ ' . PrintableReportPage::escape($this->l10n->t('Konto ist in mehreren Abschnitten gezählt'));
		}
		if ($legend !== []) {
			$h .= '<p class="meta">' . implode(' · ', $legend) . '</p>';
		}

		return PrintableReportPage::document(($clubName !== '' ? $clubName . ' – ' : '') . $heading, $h);
	}

	/**
	 * Warnung als Satz, wie sie auch der Bildschirm zeigt.
	 *
	 * @param array{code:string, accounts:list<string>} $warning
	 */
	public function warning(array $warning): string {
		$list = implode(', ', $warning['accounts']);
		return $warning['code'] === StructuredReportBuilder::WARN_DUPLICATE
			? $this->l10n->t('Diese Konten sind in mehreren Abschnitten gezählt: %s', [$list])
			: $this->l10n->t('Diese Konten erfasst kein Abschnitt der Vorlage, sie stehen unter „Nicht erfasste Konten“: %s', [$list]);
	}

	/** @param array<string, mixed> $row */
	private function row(array $row, int $width): string {
		$indent = ' style="padding-left:' . (6 + min((int)$row['depth'], 8) * 14) . 'px"';
		$label = PrintableReportPage::escape((string)$row['label']);
		switch ($row['kind']) {
			case 'heading':
				$class = $row['depth'] === 0 ? 'heading top' : 'heading';
				return '<tr class="' . $class . '"><td class="nr"></td><td colspan="' . ($width + 1) . '"' . $indent . '>' . $label . '</td></tr>';
			case 'note':
				return '<tr class="note"><td class="nr"></td><td colspan="' . ($width + 1) . '"' . $indent . '>' . $label . '</td></tr>';
			case 'account':
				$flags = $row['flags'];
				$marks = (in_array(StructuredReportBuilder::FLAG_INACTIVE, $flags, true) ? ' †' : '')
					. (in_array(StructuredReportBuilder::FLAG_DUPLICATE, $flags, true) ? ' ⚠' : '');
				$class = in_array(StructuredReportBuilder::FLAG_STRUCTURAL, $flags, true) ? ' class="structural"' : '';
				return '<tr' . $class . '><td class="nr">' . PrintableReportPage::escape((string)$row['number']) . '</td>'
					. '<td' . $indent . '>' . $label . $marks . '</td>' . $this->cells($row['values']) . '</tr>';
			case 'line':
				return '<tr><td class="nr"></td><td' . $indent . '>' . $label . '</td>' . $this->cells($row['values']) . '</tr>';
			case 'total':
				return '<tr class="result"><td class="nr"></td><td>' . $label . '</td>' . $this->cells($row['values']) . '</tr>';
			default:
				return '<tr class="sum"><td class="nr"></td><td' . $indent . '>' . $label . '</td>' . $this->cells($row['values']) . '</tr>';
		}
	}

	/** @param list<?int> $values */
	private function cells(array $values): string {
		$h = '';
		foreach ($values as $v) {
			$h .= '<td class="num">' . ($v === null ? '–' : ReportFormat::cents($v)) . '</td>';
		}
		return $h;
	}

	/** @param list<array<string, mixed>> $rows */
	private function hasFlag(array $rows, string $flag): bool {
		foreach ($rows as $r) {
			if (in_array($flag, $r['flags'] ?? [], true)) {
				return true;
			}
		}
		return false;
	}
}
