<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\AccountStatementService;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCP\IConfig;
use OCP\IL10N;

/**
 * Das Kontenblatt eines Kontos als druckfertige HTML-Seite – etwa zum
 * Abheften für die Kassenprüfung.
 *
 * Dieselben Zahlen wie der Kontoauszug im Reiter Konten und der CSV-Export
 * ({@see AccountStatementService}), immer chronologisch. Gedruckt bzw. als PDF
 * gespeichert wird im Browser, wie beim {@see KassenberichtRenderer}.
 */
class AccountStatementRenderer {

	public function __construct(
		private AccountStatementService $statements,
		private PeriodService $periods,
		private IConfig $config,
		private IL10N $l10n,
	) {
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException wenn das Konto fehlt
	 */
	public function render(string $userId, int $accountId, bool $includeChildren, ?int $periodId): string {
		$statement = $this->statements->build($userId, $accountId, $includeChildren, $periodId);
		$account = $statement['account'];
		$accountLabel = trim($account->getNumber() . ' ' . $account->getName());
		$periodLabel = PeriodService::isSelected($periodId)
			? $this->periods->find($userId, (int)$periodId)->getLabel()
			: $this->l10n->t('alle Zeiträume');

		$clubName = $this->config->getAppValue(Application::APP_ID, 'club_name', '');
		$heading = $this->l10n->t('Kontenblatt %s', [$accountLabel]);

		$meta = [
			PrintableReportPage::escape($this->l10n->t('Zeitraum: %s', [$periodLabel])),
		];
		if ($includeChildren) {
			$meta[] = PrintableReportPage::escape($this->l10n->t('inkl. Unterkonten'));
		}
		$meta[] = PrintableReportPage::escape($this->l10n->t('Erstellt am %s', [ReportFormat::date(date('Y-m-d'))]));

		$h = PrintableReportPage::printHint($this->l10n->t('Zum Drucken oder Als-PDF-Speichern: <strong>Strg+P</strong> (Mac: ⌘P) im Browser.'));
		$h .= PrintableReportPage::header(null, $clubName, $heading, implode(' · ', $meta));
		$h .= $this->table($statement, $includeChildren);

		return PrintableReportPage::document(($clubName !== '' ? $clubName . ' – ' : '') . $heading, $h);
	}

	/**
	 * Die Spalte „Konto" nur mit Unterkonten: ohne sie steht dort in jeder
	 * Zeile dasselbe Konto, und auf A4 hochkant ist jede Spalte knapp.
	 *
	 * @param array<string, mixed> $statement aus {@see AccountStatementService::build()}
	 */
	private function table(array $statement, bool $withAccount): string {
		$cols = $withAccount ? 9 : 8;
		$h = '<section><table>';
		$h .= '<tr><th class="nr">' . $this->l10n->t('Nr.') . '</th>'
			. '<th>' . $this->l10n->t('Datum') . '</th>'
			. '<th>' . $this->l10n->t('Beschreibung') . '</th>'
			. '<th>' . $this->l10n->t('Beleg') . '</th>'
			. ($withAccount ? '<th>' . $this->l10n->t('Konto') . '</th>' : '')
			. '<th>' . $this->l10n->t('Gegenkonto') . '</th>'
			. '<th class="num">' . $this->l10n->t('Soll') . '</th>'
			. '<th class="num">' . $this->l10n->t('Haben') . '</th>'
			. '<th class="num">' . $this->l10n->t('Saldo') . '</th></tr>';

		if ($statement['carryDate'] !== null) {
			$h .= '<tr><td class="nr"></td><td>' . ReportFormat::date($statement['carryDate']) . '</td>'
				. '<td colspan="' . ($cols - 3) . '"><em>' . $this->l10n->t('Saldovortrag aus dem vorherigen Zeitraum') . '</em></td>'
				. '<td class="num">' . ReportFormat::money((float)$statement['carry']) . '</td></tr>';
		}
		foreach ($statement['rows'] as $row) {
			$h .= '<tr><td class="nr">' . PrintableReportPage::escape((string)($row['entryNo'] ?? '')) . '</td>'
				. '<td>' . ReportFormat::date((string)$row['date']) . '</td>'
				. '<td>' . PrintableReportPage::escape((string)($row['description'] ?? '')) . '</td>'
				. '<td>' . PrintableReportPage::escape((string)($row['documentRef'] ?? '')) . '</td>'
				. ($withAccount ? '<td>' . PrintableReportPage::escape((string)$row['account']) . '</td>' : '')
				. '<td>' . PrintableReportPage::escape((string)$row['contra']) . '</td>'
				. '<td class="num">' . ($row['debit'] ? ReportFormat::money((float)$row['debit']) : '') . '</td>'
				. '<td class="num">' . ($row['credit'] ? ReportFormat::money((float)$row['credit']) : '') . '</td>'
				. '<td class="num">' . ReportFormat::money((float)$row['saldo']) . '</td></tr>';
		}
		$totals = $statement['totals'];
		$h .= '<tr class="sum"><td colspan="' . ($cols - 3) . '">'
			. PrintableReportPage::escape($this->l10n->n('Summe (%n Buchung)', 'Summe (%n Buchungen)', (int)$totals['count']))
			. '</td>'
			. '<td class="num">' . ReportFormat::money((float)$totals['debit']) . '</td>'
			. '<td class="num">' . ReportFormat::money((float)$totals['credit']) . '</td>'
			. '<td class="num">' . ReportFormat::money((float)$totals['balance']) . '</td></tr>';
		return $h . '</table></section>';
	}
}
