<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit\Support;

use OCA\Vereinsbuchhaltung\Service\Pdf\ReportPdfDocument;

/**
 * PDF-Dokument für Tests, das jeden gesetzten Text mitschreibt: Im fertigen PDF
 * liegt der Text als Glyphennummern vor und lässt sich nicht durchsuchen.
 * Erst nach dem Laden von TCPDF einbinden (require_once im setUp), die Klasse
 * erbt von TCPDF.
 */
class RecordingPdfDocument extends ReportPdfDocument {

	/**
	 * Das Protokoll liegt in einem Objekt, das der Test selbst hält: TCPDF räumt
	 * beim Ausgeben alle Eigenschaften des Dokuments weg.
	 */
	public function __construct(public \ArrayObject $log, string $orientation, public ?\ArrayObject $pageOf = null, public ?\ArrayObject $colorOf = null) {
		parent::__construct($orientation, 'mm', 'A4', true, 'UTF-8', false, 3);
	}

	public function MultiCell($w, $h, $txt, $border = 0, $align = 'J', $fill = false, $ln = 1, $x = null, $y = null, $reseth = true, $stretch = 0, $ishtml = false, $autopadding = true, $maxh = 0, $valign = 'T', $fitcell = false) {
		$this->log[] = (string)$txt;
		$result = parent::MultiCell($w, $h, $txt, $border, $align, $fill, $ln, $x, $y, $reseth, $stretch, $ishtml, $autopadding, $maxh, $valign, $fitcell);
		// Seite je Text (der zuletzt gesetzte gewinnt), um Umbrüche zu prüfen.
		if ($this->pageOf !== null) {
			$this->pageOf[(string)$txt] = $this->getPage();
		}
		if ($this->colorOf !== null) {
			$this->colorOf[(string)$txt] = (string)json_encode($this->fgcolor);
		}
		return $result;
	}
}
