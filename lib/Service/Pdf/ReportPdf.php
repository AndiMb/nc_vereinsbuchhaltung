<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Pdf;

/**
 * Baukasten für formelle Berichte als PDF (Issue #364): Titelblock mit
 * Vereinsname und Logo, Überschriften, Absätze, Tabellen mit wiederholter
 * Kopfzeile nach einem Seitenumbruch und Blöcke, die der Umbruch nicht trennt
 * (etwa die Unterschriften).
 *
 * Gesetzt wird mit der TCPDF-API, nicht über writeHTML(): dessen HTML/CSS-
 * Unterstützung ist dünn (kein Flexbox, kaum Kontrolle über Umbrüche) – genau
 * die Schwächen, derentwegen die Berichte aus dem Browserdruck heraus sollen.
 * Die Zahlen liefern weiter die bestehenden Dienste; hier entsteht nur das Layout.
 *
 * Ein Exemplar entsteht über {@see ReportPdfFactory::create()}.
 */
final class ReportPdf {

	public const FONT = 'dejavusans';
	public const MARGIN_LEFT = 20;
	public const MARGIN_RIGHT = 20;
	private const MARGIN_TOP = 20;
	private const MARGIN_BOTTOM = 20;

	/** Zeilenarten einer Tabelle. */
	public const ROW_NORMAL = 'normal';
	/** Zwischenkopf („Einnahmen | Betrag“); wiederholt sich nach einem Seitenumbruch. */
	public const ROW_HEAD = 'head';
	/** Fette Zwischenüberschrift ohne Füllung; bleibt wie {@see ROW_HEAD} nie allein am Seitenende, ersetzt aber nie den Spaltenkopf. */
	public const ROW_SUBHEAD = 'subhead';
	/** Erläuterung: kleiner, kursiv. */
	public const ROW_NOTE = 'note';
	public const ROW_SUM = 'sum';
	public const ROW_RESULT = 'result';

	private ?int $pages = null;

	public function __construct(
		private ReportPdfDocument $pdf,
	) {
	}

	public function document(): ReportPdfDocument {
		return $this->pdf;
	}

	public static function setUp(ReportPdfDocument $pdf, string $title, string $author): void {
		$pdf->SetCreator('Vereinsbuchhaltung für Nextcloud');
		$pdf->SetAuthor($author);
		$pdf->SetTitle($title);
		$pdf->SetSubject($title);
		$pdf->SetMargins(self::MARGIN_LEFT, self::MARGIN_TOP, self::MARGIN_RIGHT);
		$pdf->SetAutoPageBreak(true, self::MARGIN_BOTTOM);
		$pdf->setPrintHeader(true);
		$pdf->setPrintFooter(true);
		$pdf->setHeaderMargin(10);
		$pdf->setFooterMargin(13);
		$pdf->setFontSubsetting(true);
		$pdf->setCellPaddings(1.5, 1, 1.5, 1);
		$pdf->SetFont(self::FONT, '', 9, '', true);
		$pdf->AddPage();
	}

	public function contentWidth(): float {
		return $this->pdf->getPageWidth() - self::MARGIN_LEFT - self::MARGIN_RIGHT;
	}

	/**
	 * Kopf der ersten Seite: Vereinsname, Titel, eine Zeile Angaben, rechts das Logo.
	 */
	public function titleBlock(string $clubName, string $heading, string $meta, ?string $logo = null): void {
		$logoHeight = PdfLogo::place($this->pdf, $logo, self::MARGIN_RIGHT, 14, 40, 18);
		$textWidth = $this->contentWidth() - ($logoHeight > 0 ? 45 : 0);
		$this->pdf->SetXY(self::MARGIN_LEFT, 16);
		if ($clubName !== '') {
			$this->pdf->SetFont(self::FONT, 'B', 10, '', true);
			$this->pdf->SetTextColor(80, 80, 80);
			$this->pdf->MultiCell($textWidth, 5, $clubName, 0, 'L', false, 1, null, null, true, 0, false, true, 0, 'T');
		}
		$this->pdf->SetFont(self::FONT, 'B', 15, '', true);
		$this->pdf->SetTextColor(0, 0, 0);
		$this->pdf->MultiCell($textWidth, 7, $heading, 0, 'L', false, 1, null, null, true, 0, false, true, 0, 'T');
		if ($meta !== '') {
			$this->pdf->SetFont(self::FONT, '', 8, '', true);
			$this->pdf->SetTextColor(90, 90, 90);
			$this->pdf->MultiCell($textWidth, 4, $meta, 0, 'L', false, 1, null, null, true, 0, false, true, 0, 'T');
		}
		$y = max($this->pdf->GetY(), 14 + $logoHeight) + 2;
		$this->pdf->SetDrawColor(...$this->pdf->accent);
		$this->pdf->SetLineWidth(0.5);
		$this->pdf->Line(self::MARGIN_LEFT, $y, self::MARGIN_LEFT + $this->contentWidth(), $y);
		$this->pdf->SetLineWidth(0.2);
		$this->pdf->SetTextColor(0, 0, 0);
		$this->pdf->SetY($y + 5);
	}

	/**
	 * Abschnittsüberschrift. Bleibt nie allein am Seitenende stehen: Passt danach
	 * nicht mehr wenigstens ein Stück Inhalt, beginnt sie auf der nächsten Seite.
	 */
	public function heading(string $text): void {
		$this->ensureSpace(22);
		$this->pdf->SetFont(self::FONT, 'B', 11, '', true);
		$this->pdf->MultiCell(0, 6, $text, 0, 'L', false, 1, null, null, true, 0, false, true, 0, 'T');
		$this->pdf->Ln(1);
	}

	public function paragraph(string $text, bool $small = false, bool $bold = false): void {
		$this->pdf->SetFont(self::FONT, $bold ? 'B' : '', $small ? 7.5 : 9, '', true);
		if ($small) {
			$this->pdf->SetTextColor(90, 90, 90);
		}
		$this->pdf->MultiCell(0, $small ? 3.8 : 4.6, $text, 0, 'L', false, 1, null, null, true, 0, false, true, 0, 'T');
		$this->pdf->SetTextColor(0, 0, 0);
		$this->pdf->Ln(1.5);
	}

	/**
	 * Eine Anschrift: Zeilen ohne Absatzabstand, die erste fett (Name).
	 *
	 * @param list<string> $lines
	 */
	public function address(array $lines): void {
		$padding = $this->pdf->getCellPaddings();
		$this->pdf->setCellPaddings($padding['L'], 0, $padding['R'], 0);
		foreach ($lines as $i => $line) {
			$this->pdf->SetFont(self::FONT, $i === 0 ? 'B' : '', 9, '', true);
			$this->pdf->MultiCell(0, 4.6, $line, 0, 'L', false, 1, null, null, true, 0, false, true, 0, 'T');
		}
		$this->pdf->setCellPaddings($padding['L'], $padding['T'], $padding['R'], $padding['B']);
		$this->pdf->Ln(1.5);
	}

	public function space(float $mm): void {
		$this->pdf->Ln($mm);
	}

	/**
	 * Eine Reihe umrahmter Felder nebeneinander, oben klein die Beschriftung,
	 * darunter der Wert – wie die Felder eines amtlichen Vordrucks (Issue #10).
	 *
	 * @param list<array{label: string, value: string, width: float}> $fields
	 *                                                                        `width` ist der Anteil an der Breite
	 */
	public function boxes(array $fields, float $minHeight = 12): void {
		$total = $this->contentWidth();
		$padding = $this->pdf->getCellPaddings();
		// Die Werte stehen auf einer Höhe, auch wenn eine Beschriftung umbricht.
		$labelHeight = 0.0;
		$this->pdf->SetFont(self::FONT, '', 6.5, '', true);
		foreach ($fields as $field) {
			$labelHeight = max($labelHeight, $this->pdf->getStringHeight($field['width'] * $total, $field['label']));
		}
		$height = $minHeight;
		$this->pdf->SetFont(self::FONT, '', 9.5, '', true);
		foreach ($fields as $field) {
			$height = max($height, $labelHeight + $this->pdf->getStringHeight($field['width'] * $total, $field['value']) + 1);
		}
		$this->ensureSpace($height + 2);
		$y = $this->pdf->GetY();
		$x = (float)self::MARGIN_LEFT;
		$this->pdf->SetDrawColor(0, 0, 0);
		$this->pdf->SetLineWidth(0.2);
		foreach ($fields as $field) {
			$width = $field['width'] * $total;
			$this->pdf->Rect($x, $y, $width, $height);
			$this->pdf->SetFont(self::FONT, '', 6.5, '', true);
			$this->pdf->SetTextColor(90, 90, 90);
			$this->pdf->MultiCell($width, 3, $field['label'], 0, 'L', false, 1, $x, $y + 0.3, true, 0, false, true, 0, 'T');
			$this->pdf->SetFont(self::FONT, '', 9.5, '', true);
			$this->pdf->SetTextColor(0, 0, 0);
			$this->pdf->MultiCell($width, 4.6, $field['value'], 0, 'L', false, 1, $x, $y + 0.3 + $labelHeight + 0.5, true, 0, false, true, 0, 'T');
			$x += $width;
		}
		$this->pdf->setCellPaddings($padding['L'], $padding['T'], $padding['R'], $padding['B']);
		$this->pdf->SetFont(self::FONT, '', 9, '', true);
		$this->pdf->SetXY(self::MARGIN_LEFT, $y + $height);
		$this->pdf->Ln(2.5);
	}

	/**
	 * Eine Tabelle über die volle Breite.
	 *
	 * Spalten: Anteil an der Breite und Ausrichtung ('L'/'R'), Beschriftung für die
	 * Kopfzeile (alle leer = keine Kopfzeile). Zeilen: Zellen, Art ({@see ROW_HEAD} usw.),
	 * optional `span` (je Zelle die Zahl der Spalten, die sie einnimmt) und
	 * `indent` (Einzug in mm; gilt für die erste Zelle, oder für die in `indentCell`
	 * genannte – etwa die Bezeichnung hinter einer Nummernspalte) und `align` (Ausrichtung
	 * der ersten Zelle, wenn die der letzten überspannten Spalte nicht passt).
	 *
	 * Bricht die Tabelle um, steht oben auf der neuen Seite wieder der letzte
	 * Zwischenkopf – oder die Kopfzeile, wenn es keinen gab. Ein Zwischenkopf mit
	 * `repeat => false` (z. B. ein Abschnittstitel über die volle Breite) ersetzt
	 * die Kopfzeile nicht: Sonst fehlten auf Folgeseiten die Spaltenköpfe.
	 *
	 * @param list<array{label?: string, width: float, align?: string}> $columns
	 * @param list<array{cells: list<string>, style?: string, span?: list<int>, indent?: float, indentCell?: int, repeat?: bool, align?: string, muted?: bool}> $rows
	 */
	public function table(array $columns, array $rows): void {
		$total = $this->contentWidth();
		$widths = array_map(static fn (array $c): float => $c['width'] * $total, $columns);
		$aligns = array_map(static fn (array $c): string => $c['align'] ?? 'L', $columns);
		$labels = array_map(static fn (array $c): string => $c['label'] ?? '', $columns);

		$repeat = implode('', $labels) !== '' ? ['cells' => $labels, 'style' => self::ROW_HEAD] : null;
		if ($repeat !== null) {
			$this->ensureSpace($this->rowHeight($widths, $repeat) * 2);
			$this->row($widths, $aligns, $repeat);
		}
		foreach ($rows as $index => $row) {
			$style = $row['style'] ?? self::ROW_NORMAL;
			$height = $this->rowHeight($widths, $row);
			// Ein Zwischenkopf zieht wenigstens eine Zeile mit auf die Seite – und eine
			// ganze Kette von Köpfen (Abschnitt, Unterabschnitt) die erste Zeile darunter,
			// sonst bliebe der Abschnittstitel allein am Seitenende, wenn erst der
			// Unterabschnitt umbricht.
			$replaces = $style === self::ROW_HEAD && ($row['repeat'] ?? true);
			$needed = $height;
			if ($this->isHead($style)) {
				for ($next = $index + 1; $next < count($rows); $next++) {
					$needed += $this->rowHeight($widths, $rows[$next]);
					if (!$this->isHead($rows[$next]['style'] ?? self::ROW_NORMAL)) {
						break;
					}
				}
			}
			if ($this->pdf->GetY() + $needed > $this->bottom()) {
				$this->pdf->AddPage();
				if ($repeat !== null && !$replaces) {
					$this->row($widths, $aligns, $repeat);
				}
			}
			$this->row($widths, $aligns, $row, $height);
			if ($replaces) {
				$repeat = $row;
			}
		}
		$this->pdf->Ln(3);
	}

	/**
	 * Unterschriftszeilen nebeneinander, mit Raum zum Unterschreiben darüber.
	 *
	 * @param list<string> $labels
	 */
	public function signatures(array $labels): void {
		if ($labels === []) {
			return;
		}
		$this->pdf->Ln(14);
		$gap = 8;
		$width = ($this->contentWidth() - $gap * (count($labels) - 1)) / count($labels);
		$y = $this->pdf->GetY();
		$this->pdf->SetDrawColor(0, 0, 0);
		$this->pdf->SetFont(self::FONT, '', 7.5, '', true);
		$bottom = $y;
		foreach (array_values($labels) as $i => $label) {
			$x = self::MARGIN_LEFT + $i * ($width + $gap);
			$this->pdf->Line($x, $y, $x + $width, $y);
			$this->pdf->SetXY($x, $y + 1);
			$this->pdf->MultiCell($width, 3.8, $label, 0, 'L', false, 1, null, null, true, 0, false, true, 0, 'T');
			$bottom = max($bottom, $this->pdf->GetY());
		}
		$this->pdf->SetY($bottom + 2);
	}

	/**
	 * Zeichnet einen Block so, dass ihn kein Seitenumbruch trennt: Läuft er über
	 * das Seitenende, wird er zurückgenommen und auf der nächsten Seite neu gesetzt.
	 * Steht er schon oben auf einer Seite und passt trotzdem nicht, bleibt er, wie
	 * er ist – eine leere Seite davor hülfe nicht.
	 *
	 * @param callable(ReportPdf): void $draw
	 */
	public function keepTogether(callable $draw): void {
		$page = $this->pdf->getPage();
		$atTop = $this->pdf->GetY() <= self::MARGIN_TOP + 0.5;
		$this->pdf->startTransaction();
		$draw($this);
		if ($this->pdf->getPage() === $page || $atTop) {
			$this->pdf->commitTransaction();
			return;
		}
		$this->pdf->rollbackTransaction(true);
		$this->pdf->AddPage();
		$draw($this);
	}

	/** Beginnt eine neue Seite, wenn bis zum unteren Rand weniger als so viel Platz ist. */
	public function ensureSpace(float $mm): void {
		if ($this->pdf->GetY() + $mm > $this->bottom()) {
			$this->pdf->AddPage();
		}
	}

	/** Seitenzahl; bleibt auch nach {@see output()} abrufbar, wenn TCPDF seinen Zustand schon verworfen hat. */
	public function pageCount(): int {
		return $this->pages ?? (int)$this->pdf->getNumPages();
	}

	/** @return string PDF-Bytes */
	public function output(): string {
		$this->pages = (int)$this->pdf->getNumPages();
		return $this->pdf->Output('', 'S');
	}

	private function isHead(string $style): bool {
		return $style === self::ROW_HEAD || $style === self::ROW_SUBHEAD;
	}

	private function bottom(): float {
		return $this->pdf->getPageHeight() - $this->pdf->getBreakMargin();
	}

	/**
	 * Breiten und Ausrichtungen der Zellen einer Zeile, mit zusammengefassten Spalten.
	 *
	 * @param list<float> $widths
	 * @param list<string> $aligns
	 * @param array{cells: list<string>, span?: list<int>, align?: string} $row
	 * @return list<array{0: float, 1: string}>
	 */
	private function cells(array $widths, array $aligns, array $row): array {
		$out = [];
		$column = 0;
		foreach (array_values($row['cells']) as $i => $_) {
			$span = max(1, (int)($row['span'][$i] ?? 1));
			$width = array_sum(array_slice($widths, $column, $span));
			// Die Ausrichtung kommt von der letzten überspannten Spalte: Eine Summenzeile,
			// deren Beschriftung zwei Spalten einnimmt, bleibt links, der Betrag rechts.
			// Eine Überschrift über die ganze Breite endete so rechts; dafür gibt es `align`.
			$out[] = [$width, ($i === 0 ? ($row['align'] ?? null) : null) ?? $aligns[min($column + $span - 1, count($aligns) - 1)] ?? 'L'];
			$column += $span;
		}
		return $out;
	}

	/**
	 * @param list<float> $widths
	 * @param array{cells: list<string>, style?: string, span?: list<int>, indent?: float, indentCell?: int, repeat?: bool, align?: string} $row
	 */
	private function rowHeight(array $widths, array $row): float {
		$this->font($row['style'] ?? self::ROW_NORMAL);
		$height = 0.0;
		foreach ($this->cells($widths, array_fill(0, count($widths), 'L'), $row) as $i => [$width]) {
			$indent = $i === (int)($row['indentCell'] ?? 0) ? (float)($row['indent'] ?? 0) : 0.0;
			$height = max($height, $this->pdf->getStringHeight($width - $indent, $row['cells'][$i]));
		}
		return max($height, 5.0);
	}

	/**
	 * @param list<float> $widths
	 * @param list<string> $aligns
	 * @param array{cells: list<string>, style?: string, span?: list<int>, indent?: float, indentCell?: int, align?: string, muted?: bool} $row
	 */
	private function row(array $widths, array $aligns, array $row, ?float $height = null): void {
		$style = $row['style'] ?? self::ROW_NORMAL;
		$height ??= $this->rowHeight($widths, $row);
		$this->font($style);
		// `muted`: graue Schrift, etwa für strukturelle Konten; danach wieder schwarz.
		$muted = (bool)($row['muted'] ?? false);
		if ($muted) {
			$this->pdf->SetTextColor(110, 110, 110);
		}
		$fill = $style === self::ROW_HEAD || $style === self::ROW_RESULT;
		$this->pdf->SetFillColor(242, 242, 242);
		$y = $this->pdf->GetY();
		$x = (float)self::MARGIN_LEFT;
		foreach ($this->cells($widths, $aligns, $row) as $i => [$width, $align]) {
			$indent = $i === (int)($row['indentCell'] ?? 0) ? (float)($row['indent'] ?? 0) : 0.0;
			if ($fill && $indent > 0) {
				$this->pdf->Rect($x, $y, $indent, $height, 'F');
			}
			$this->pdf->MultiCell($width - $indent, $height, $row['cells'][$i], 0, $align, $fill, 0, $x + $indent, $y, true, 0, false, true, $height, 'M');
			$x += $width;
		}
		$right = self::MARGIN_LEFT + array_sum($widths);
		$bottom = $y + $height;
		match ($style) {
			self::ROW_HEAD => $this->line(self::MARGIN_LEFT, $bottom, $right, [120, 120, 120], 0.3),
			self::ROW_SUM => $this->line(self::MARGIN_LEFT, $y, $right, [0, 0, 0], 0.3),
			self::ROW_RESULT => $this->line(self::MARGIN_LEFT, $y, $right, $this->pdf->accent, 0.6),
			default => $this->line(self::MARGIN_LEFT, $bottom, $right, [225, 225, 225], 0.1),
		};
		$this->pdf->SetXY(self::MARGIN_LEFT, $bottom);
		$this->pdf->SetFont(self::FONT, '', 9, '', true);
		if ($muted) {
			$this->pdf->SetTextColor(0, 0, 0);
		}
	}

	/** @param array{0:int, 1:int, 2:int}|array<int, int> $rgb */
	private function line(float $from, float $y, float $to, array $rgb, float $width): void {
		$this->pdf->SetDrawColor(...array_values($rgb));
		$this->pdf->SetLineWidth($width);
		$this->pdf->Line($from, $y, $to, $y);
		$this->pdf->SetLineWidth(0.2);
	}

	private function font(string $style): void {
		if ($style === self::ROW_NOTE) {
			$this->pdf->SetFont(self::FONT, 'I', 7.5, '', true);
			return;
		}
		$bold = $style === self::ROW_HEAD || $style === self::ROW_SUBHEAD || $style === self::ROW_SUM || $style === self::ROW_RESULT;
		$this->pdf->SetFont(self::FONT, $bold ? 'B' : '', $style === self::ROW_HEAD ? 8 : 8.5, '', true);
	}
}
