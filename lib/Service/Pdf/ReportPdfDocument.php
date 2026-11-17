<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Pdf;

/**
 * TCPDF-Dokument der formellen Berichte (Issue #364): ab Seite 2 ein laufender
 * Titel oben, auf jeder Seite unten ein Vermerk links und „Seite n von m“ rechts.
 * Eigene Klasse, weil TCPDF Kopf- und Fußzeile nur über Vererbung anpassen lässt.
 * Liegt in einer eigenen Datei, damit sie erst geladen wird, wenn TCPDF da ist
 * ({@see ReportPdfFactory} prüft das).
 */
class ReportPdfDocument extends \TCPDF {

	/** Steht ab Seite 2 oben, damit eine lose Seite zuzuordnen ist. */
	public string $runningTitle = '';
	/** Steht links in der Fußzeile, z. B. „Erstellt am …“. */
	public string $footerNote = '';
	public string $pageLabel = 'Seite {page} von {pages}';
	/** @var array{0:int, 1:int, 2:int} Akzentfarbe für Linien */
	public array $accent = [34, 34, 34];

	/**
	 * Schriften immer aus dem eigenen vendor/ laden, nicht aus K_PATH_FONTS einer
	 * fremden TCPDF-Kopie (Issue #206, siehe InvoicePdfDocument::AddFont()).
	 */
	public function AddFont($family, $style = '', $fontfile = '', $subset = 'default') {
		if ($fontfile === '' || $fontfile === null) {
			$fontfile = self::fontDir() . '/vbh-font-lookup';
		}
		return parent::AddFont($family, $style, $fontfile, $subset);
	}

	public static function fontDir(): string {
		return dirname(__DIR__, 3) . '/vendor/tecnickcom/tcpdf/fonts';
	}

	public function Header(): void {
		if ($this->getPage() < 2 || $this->runningTitle === '') {
			return;
		}
		$left = $this->lMargin;
		$width = $this->getPageWidth() - $this->lMargin - $this->rMargin;
		$this->SetFont(ReportPdf::FONT, '', 7, '', true);
		$this->SetTextColor(100, 100, 100);
		$this->SetXY($left, 10);
		$this->Cell($width, 3.5, $this->runningTitle, 0, 0, 'L', false, '', 1);
		$this->SetDrawColor(180, 180, 180);
		$this->Line($left, 14.5, $left + $width, 14.5);
		$this->SetTextColor(0, 0, 0);
	}

	public function Footer(): void {
		$left = $this->lMargin;
		$width = $this->getPageWidth() - $this->lMargin - $this->rMargin;
		$top = $this->getPageHeight() - 13;
		$this->SetDrawColor(180, 180, 180);
		$this->Line($left, $top - 1.5, $left + $width, $top - 1.5);
		$this->SetFont(ReportPdf::FONT, '', 7, '', true);
		$this->SetTextColor(100, 100, 100);
		$this->SetXY($left, $top);
		$this->Cell($width * 0.7, 3.5, $this->footerNote, 0, 0, 'L', false, '', 1);

		// TCPDF ersetzt die Platzhalter für „Seite x von y“ erst nach dem Satz, eine
		// rechtsbündige Zelle säße daher zu weit links. Gemessen wird der Text mit gleich
		// vielen Ziffern (alle Ziffern der Schrift sind gleich breit), gesetzt linksbündig
		// an die Stelle, an der er rechtsbündig enden würde (wie InvoicePdfDocument).
		$page = $this->getPage();
		$measure = strtr($this->pageLabel, ['{page}' => (string)$page, '{pages}' => str_repeat('0', strlen((string)$page))]);
		$textWidth = $this->GetStringWidth($measure) + $this->getCellPaddings()['L'] + $this->getCellPaddings()['R'];
		$this->SetXY($left + $width - $textWidth, $top);
		$this->Cell($textWidth + 4, 3.5, strtr($this->pageLabel, ['{page}' => $this->getAliasNumPage(), '{pages}' => $this->getAliasNbPages()]), 0, 0, 'L');
		$this->SetTextColor(0, 0, 0);
	}
}
