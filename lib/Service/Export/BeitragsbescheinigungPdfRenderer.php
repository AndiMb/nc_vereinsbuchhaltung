<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Service\ClubCurrency;
use OCA\Vereinsbuchhaltung\Service\Pdf\ReportPdf;
use OCA\Vereinsbuchhaltung\Service\Pdf\ReportPdfFactory;
use OCA\Vereinsbuchhaltung\Service\SafeFileName;
use OCP\IL10N;

/**
 * Die informelle Beitragsbestätigung als PDF (Issue #364) – derselbe Inhalt
 * wie die HTML-Seite ({@see BeitragsbescheinigungRenderer::collect()}), als
 * Datei, die ein Mitglied speichern oder weitergeben kann. Weiterhin KEINE
 * Zuwendungsbestätigung nach § 10b EStG (Issue #10); der Hinweis steht im PDF
 * an derselben Stelle wie in der HTML-Fassung.
 */
class BeitragsbescheinigungPdfRenderer {

	public function __construct(
		private BeitragsbescheinigungRenderer $report,
		private ReportPdfFactory $factory,
		private ClubCurrency $currency,
		private IL10N $l10n,
	) {
	}

	/** Dateiname aus der Überschrift, z. B. „Beitragsbestätigung 2025-26.pdf“. */
	public function fileName(string $heading): string {
		return SafeFileName::of(str_replace('/', '-', $heading), 80) . '.pdf';
	}

	/**
	 * @param int|null $anchorYear siehe {@see BeitragsbescheinigungRenderer::collect()}
	 * @param bool $compress false erzeugt lesbare Streams (nur für Tests)
	 * @return array{content: string, fileName: string}
	 * @throws \OCP\AppFramework\Db\DoesNotExistException wenn es das Mitglied nicht gibt
	 */
	public function render(int $memberId, ?int $anchorYear = null, bool $compress = true): array {
		$d = $this->report->collect($memberId, $anchorYear);
		$title = ($d['clubName'] !== '' ? $d['clubName'] . ' – ' : '') . $d['heading'];

		$pdf = $this->factory->create($title, $d['meta'], $compress);
		$pdf->titleBlock($d['clubName'], $d['heading'], $d['meta'], $this->factory->logo());
		$pdf->paragraph($this->report->disclaimer());
		$pdf->space(2);
		$pdf->address($this->report->recipientLines($d['member']));
		$pdf->space(2);

		if ($d['rows'] === []) {
			$pdf->paragraph($this->l10n->t('Keine bezahlten Beitrags-Forderungen in diesem Beitragsjahr.'));
		} else {
			$pdf->heading($this->l10n->t('Bezahlte Beiträge'));
			$rows = array_map(fn (OpenItem $item): array => ['cells' => [
				$this->report->periodLabel($item),
				(string)($item->getDescription() ?? ''),
				ReportFormat::cents($item->getAmountCents(), $this->currency->symbol()),
			]], $d['rows']);
			$rows[] = ['style' => ReportPdf::ROW_RESULT, 'span' => [2, 1], 'cells' => [$this->l10n->t('Summe'), ReportFormat::cents($d['sumCents'], $this->currency->symbol())]];
			$pdf->table([
				['label' => $this->l10n->t('Fälligkeitsperiode'), 'width' => 0.3],
				['label' => $this->l10n->t('Beschreibung'), 'width' => 0.48],
				['label' => $this->l10n->t('Betrag'), 'width' => 0.22, 'align' => 'R'],
			], $rows);
		}
		return ['content' => $pdf->output(), 'fileName' => $this->fileName($d['heading'])];
	}
}
