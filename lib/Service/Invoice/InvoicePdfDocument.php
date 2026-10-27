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
		$top = $this->getPageHeight() - 27;
		// Die Spalten sind verschieden breit: die mittlere trägt die langen Registerangaben.
		// Zwischen ihnen bleibt Luft, und der Text steht auf der Linie des Fließtextes (1 mm Zellrand).
		$gap = 5;
		$inset = 1;
		$columns = [0.28, 0.38, 0.34];

		$this->SetDrawColor(180, 180, 180);
		$this->Line($left, $top - 2, $left + $width, $top - 2);
		$this->SetTextColor(100, 100, 100);

		$x = $left + $inset;
		foreach ([$this->footerLeft, $this->footerMiddle, $this->footerRight] as $i => $lines) {
			$columnWidth = $columns[$i] * ($width - $inset);
			$this->SetXY($x, $top);
			foreach (array_values($lines) as $n => $line) {
				// Die erste Zeile jeder Spalte (Vereinsname, „Bankverbindung“) ist die Überschrift.
				$this->SetFont('dejavusans', $n === 0 && $i !== 1 ? 'B' : '', 7, '', true);
				$this->SetX($x);
				$this->MultiCell($i === 2 ? $columnWidth : $columnWidth - $gap, 3.6, $line, 0, 'L', false, 1, null, null, true, 0, false, true, 0, 'T');
			}
			$x += $columnWidth;
		}
		$this->SetFont('dejavusans', '', 7, '', true);
		// Die Seitenzahl steht mittig unten. TCPDF ersetzt die Platzhalter für „Seite x von y“ erst
		// nach dem Satz, daher zentriert „C“ nicht genau; wir messen den Text mit gleich vielen
		// Ziffern (alle Ziffern der Schrift sind gleich breit) und setzen ihn linksbündig an die Stelle.
		$page = $this->getPage();
		$measure = strtr($this->pageLabel, ['{page}' => (string)$page, '{pages}' => str_repeat('0', strlen((string)$page))]);
		$textWidth = $this->GetStringWidth($measure);
		$this->SetXY($this->getPageWidth() / 2 - $textWidth / 2 - $this->getCellPaddings()['L'], $this->getPageHeight() - 10);
		$this->Cell($textWidth + 4, 3, strtr($this->pageLabel, ['{page}' => $this->getAliasNumPage(), '{pages}' => $this->getAliasNbPages()]), 0, 0, 'L');
		$this->SetTextColor(0, 0, 0);
	}
}
