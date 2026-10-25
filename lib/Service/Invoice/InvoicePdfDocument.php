<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Invoice;

/**
 * TCPDF-Dokument mit der Fußzeile der Rechnung: Angaben des Vereins in drei
 * Spalten und die Seitenzahl. Eigene Klasse, weil TCPDF die Fußzeile nur
 * über Vererbung anpassen lässt. Liegt in einer eigenen Datei, damit sie
 * nur geladen wird, wenn TCPDF auch da ist (der Renderer prüft das).
 */
class InvoicePdfDocument extends \TCPDF {

	/** @var list<string> */
	public array $footerLeft = [];
	/** @var list<string> */
	public array $footerMiddle = [];
	/** @var list<string> */
	public array $footerRight = [];
	public string $pageLabel = 'Seite {page} von {pages}';

	public function Header(): void {
		// Der Briefkopf steht im Inhalt der ersten Seite, nicht in der Kopfzeile.
	}

	public function Footer(): void {
		$left = $this->lMargin;
		$width = $this->getPageWidth() - $this->lMargin - $this->rMargin;
		$column = $width / 3;
		$top = $this->getPageHeight() - 24;

		$this->SetDrawColor(180, 180, 180);
		$this->Line($left, $top - 1, $left + $width, $top - 1);
		$this->SetTextColor(100, 100, 100);
		$this->SetFont('dejavusans', '', 6.5, '', true);

		foreach ([$this->footerLeft, $this->footerMiddle, $this->footerRight] as $i => $lines) {
			$this->SetXY($left + $i * $column, $top);
			$this->MultiCell($column - 2, 3.2, implode("\n", $lines), 0, 'L', false, 0, null, null, true, 0, false, true, 16, 'T');
		}
		$this->SetXY($left, $this->getPageHeight() - 9);
		$this->Cell($width, 3, strtr($this->pageLabel, ['{page}' => $this->getAliasNumPage(), '{pages}' => $this->getAliasNbPages()]), 0, 0, 'R');
		$this->SetTextColor(0, 0, 0);
	}
}
