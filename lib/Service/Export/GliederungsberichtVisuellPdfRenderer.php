<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

use OCA\Vereinsbuchhaltung\Service\AsciiFileName;
use OCA\Vereinsbuchhaltung\Service\ClubClock;
use OCA\Vereinsbuchhaltung\Service\ClubCurrency;
use OCA\Vereinsbuchhaltung\Service\Pdf\ReportPdf;
use OCA\Vereinsbuchhaltung\Service\Pdf\ReportPdfDocument;
use OCA\Vereinsbuchhaltung\Service\Pdf\ReportPdfFactory;
use OCA\Vereinsbuchhaltung\Service\PeriodLabel;
use OCA\Vereinsbuchhaltung\Service\Report\BudgetStatus;
use OCA\Vereinsbuchhaltung\Service\Report\StructuredReportBuilder;
use OCA\Vereinsbuchhaltung\Service\Report\VisualReportService;
use OCP\IL10N;

/**
 * Die visuelle Gliederungsansicht (Plan gegen Ist) als PDF – derselbe Inhalt
 * wie die Druckseite ({@see GliederungsberichtVisuellRenderer}): Kennzahlen,
 * Ampelverteilung, größte Abweichungen, Verlauf und der Aufriss nach der
 * Vorlage, aber als Datei mit Seitenzahlen und ohne Browser-Kopf und -Fuß.
 *
 * Texte und Stufen je Ampel kommen von der Druckseite, die Zahlen unverändert
 * aus {@see VisualReportService::build()}. Balken zeichnet TCPDF direkt, der
 * Verlauf ist dasselbe SVG wie auf der Druckseite ({@see TrendSvg}). Farben
 * tragen auch hier keine eigene Information: Jede Stufe hat Symbol und Text,
 * jeder Balken steht als Prozentzahl in der Spalte „Ausschöpfung“.
 */
class GliederungsberichtVisuellPdfRenderer {

	/** Reihenfolge der Stufen in der Verteilung, wie am Bildschirm */
	private const ORDER = [BudgetStatus::CRIT, BudgetStatus::UNPLANNED, BudgetStatus::WARN, BudgetStatus::GOOD];

	/** Druckfarben je Ton, dieselben wie auf der Druckseite. */
	private const TONE = [
		'good' => [45, 125, 70],
		'warn' => [199, 119, 0],
		'crit' => [201, 48, 44],
		'serious' => [201, 48, 44],
		'none' => [119, 119, 119],
	];

	/** Feste Spaltenbreiten des Aufrisses in mm; Beträge und Ausschöpfung richten sich nach dem Inhalt, die Bezeichnung nimmt den Rest. */
	private const STATUS_MM = 27.0;
	private const BAR_MM = 20.0;
	private const MAX_DEPTH = 8;
	private const INDENT_MM = 3.5;

	public function __construct(
		private ReportPdfFactory $factory,
		private GliederungsberichtVisuellRenderer $printPage,
		private GliederungsberichtRenderer $table,
		private ClubCurrency $currency,
		private IL10N $l10n,
		private ClubClock $clock,
	) {
	}

	/**
	 * Dateiname, z. B. „Gliederungsbericht-visuell-Ergebnis-je-Bereich-2026.pdf“.
	 *
	 * @param array<string, mixed> $visual aus {@see VisualReportService::build()}
	 */
	public function fileName(array $visual): string {
		return 'Gliederungsbericht-visuell-' . AsciiFileName::of((string)$visual['template']['name']) . '-' . AsciiFileName::of((string)$visual['period']->getLabel()) . '.pdf';
	}

	/**
	 * @param array<string, mixed> $visual aus {@see VisualReportService::build()}
	 * @param string $basis `year` oder `time`; alles andere gilt als `year`
	 * @param bool $compress false erzeugt lesbare Streams (nur für Tests)
	 * @return string PDF-Bytes
	 */
	public function render(array $visual, string $basis, bool $compress = true): string {
		$asOf = $visual['asOf'];
		$elapsed = (int)$asOf['elapsed'];
		$total = (int)$asOf['total'];
		// Wie die Druckseite: ohne vergangenen Monat gilt der Jahresplan, und der Kopf sagt es.
		$fallback = $basis === 'time' && $elapsed <= 0;
		$basis = $basis === 'time' && !$fallback ? 'time' : 'year';
		$factor = $elapsed > 0 && $total > 0 ? $elapsed / $total : null;

		$name = (string)$visual['template']['name'];
		$clubName = $this->factory->clubName();
		$basisText = $basis === 'time'
			? $this->l10n->t('Vergleich gegen: zeitanteiligen Plan (%1$s von %2$s Monaten)', [$elapsed, $total])
			: $this->l10n->t('Vergleich gegen: Jahresplan');

		$pdf = $this->factory->create(
			($clubName !== '' ? $clubName . ' – ' : '') . $name,
			$this->l10n->t('Erstellt am %s', [ReportFormat::date($this->clock->today())]),
			$compress,
		);
		$pdf->titleBlock($clubName, $name, implode(' · ', [
			(string)$visual['period']->getLabel(),
			$this->l10n->t('Stand: %s', [$this->printPage->asOfLabel($asOf)]),
			$basisText,
		]), $this->factory->logo());

		if ($fallback) {
			$pdf->paragraph($this->l10n->t('Die Periode hat noch nicht begonnen – der zeitanteilige Vergleich und die Hochrechnung entfallen.'), true);
		}
		foreach ($visual['warnings'] as $warning) {
			$pdf->paragraph('⚠ ' . $this->table->warning($warning));
		}

		$kpis = $visual['kpis'];
		$noPlan = (int)$kpis['income']['plan'] === 0 && (int)$kpis['expense']['plan'] === 0;
		if ($noPlan && (int)$kpis['income']['actual'] === 0 && (int)$kpis['expense']['actual'] === 0) {
			$pdf->paragraph($this->l10n->t('Für diese Periode gibt es weder Planwerte noch Buchungen. Die Planwerte tragen Sie im Finanzplan ein.'));
			return $pdf->output();
		}
		if ($noPlan) {
			$pdf->paragraph('⚠ ' . $this->l10n->t('Für diese Periode ist noch kein Plan erfasst; alle Konten mit Buchungen gelten als ungeplant. Die Planwerte tragen Sie im Finanzplan ein.'));
		}

		$pdf->keepTogether(fn (ReportPdf $p) => $this->kpis($p, $kpis, $basis, $factor));

		$dist = $visual['distribution'][$basis];
		$pdf->heading($this->l10n->t('Ampel'));
		$pdf->paragraph($this->distribution($this->l10n->t('Ausgabenkonten'), 'expense', $dist['expense']));
		$pdf->paragraph($this->distribution($this->l10n->t('Einnahmenkonten'), 'income', $dist['income']));

		$dev = $visual['deviations'][$basis];
		$pdf->keepTogether(fn (ReportPdf $p) => $this->deviations($p, [
			[$this->l10n->t('Größte Überschreitungen (Ausgaben)'), 'expense', $dev['expense'], $this->l10n->t('Kein Ausgabenkonto liegt über dem Plan.')],
			[$this->l10n->t('Größte Lücken (Einnahmen)'), 'income', $dev['income'], $this->l10n->t('Kein Einnahmenkonto liegt unter dem Plan.')],
		]));

		$this->trend($pdf, $visual, $total);
		$this->breakdown($pdf, $visual['rows'], $basis, $factor);
		return $pdf->output();
	}

	/**
	 * Drei Kennzahlen nebeneinander: Ist groß, darunter Stufe, Balken und die
	 * Einzelwerte wie auf der Druckseite.
	 *
	 * @param array<string, array<string, mixed>> $kpis
	 */
	private function kpis(ReportPdf $report, array $kpis, string $basis, ?float $factor): void {
		$pdf = $report->document();
		$gap = 6.0;
		$width = ($report->contentWidth() - 2 * $gap) / 3;
		$top = $pdf->GetY();
		$bottom = $top;
		foreach ([
			[$this->l10n->t('Einnahmen'), 'income'],
			[$this->l10n->t('Ausgaben'), 'expense'],
			[$this->l10n->t('Ergebnis'), 'result'],
		] as $i => [$title, $nature]) {
			$x = ReportPdf::MARGIN_LEFT + $i * ($width + $gap);
			$bottom = max($bottom, $this->kpi($pdf, $x, $top, $width, $title, $nature, $kpis[$nature], $basis, $factor));
		}
		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetFont(ReportPdf::FONT, '', 9, '', true);
		$pdf->SetXY(ReportPdf::MARGIN_LEFT, $bottom + 4);
	}

	/**
	 * @param array<string, mixed> $kpi
	 * @return float unteres Ende der Spalte
	 */
	private function kpi(ReportPdfDocument $pdf, float $x, float $y, float $width, string $title, string $nature, array $kpi, string $basis, ?float $factor): float {
		$status = $kpi['status'][$basis] ?? ['level' => BudgetStatus::NONE, 'ratio' => null];
		$level = (string)$status['level'];
		$plan = (int)$kpi['plan'];
		$actual = (int)$kpi['actual'];
		$forecast = $kpi['forecast'] ?? null;
		[$icon, $label, $tone] = $this->printPage->meta($level, $nature);

		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetFont(ReportPdf::FONT, 'B', 11, '', true);
		$pdf->MultiCell($width, 6, $title, 0, 'L', false, 1, $x, $y, true, 0, false, true, 0, 'T');
		$pdf->SetFont(ReportPdf::FONT, 'B', 15, '', true);
		$pdf->MultiCell($width, 7, $this->currency->format($actual), 0, 'L', false, 1, $x, null, true, 0, false, true, 0, 'T');
		$pdf->SetFont(ReportPdf::FONT, 'B', 9, '', true);
		$pdf->SetTextColor(...self::TONE[$tone]);
		$pdf->MultiCell($width, 5, $icon . ' ' . $label, 0, 'L', false, 1, $x, null, true, 0, false, true, 0, 'T');
		$pdf->SetTextColor(0, 0, 0);
		if ($nature !== 'result') {
			$barY = $pdf->GetY() + 1;
			$this->bar($pdf, $x + 1.5, $barY, $width - 3, 3.0, $plan, $actual, $factor, $tone);
			$pdf->SetY($barY + 4.5);
		}

		$pairs = [[$this->l10n->t('Ist'), $this->currency->format($actual)], [$this->l10n->t('Plan'), $this->currency->format($plan)]];
		if ($nature === 'result') {
			$pairs[] = [$this->l10n->t('Differenz'), $this->signedMoney($actual - $plan)];
		} else {
			$ratio = $status['ratio'] ?? null;
			$pairs[] = [$this->l10n->t('Ausschöpfung'), $ratio === null ? '–' : GliederungsberichtVisuellRenderer::percent((float)$ratio)];
		}
		if ($factor !== null) {
			$pairs[] = [$this->l10n->t('Zeitanteiliger Plan'), $this->currency->format((int)round($plan * $factor))];
		}
		if ($forecast !== null) {
			$pairs[] = [$this->l10n->t('Hochrechnung'), $this->currency->format((int)$forecast)];
		}
		$pdf->SetFont(ReportPdf::FONT, '', 8.5, '', true);
		foreach ($pairs as [$key, $value]) {
			// Der Betrag bekommt, was er braucht; die Beschriftung den Rest.
			$valueWidth = min($width * 0.6, $pdf->GetStringWidth($value) + 4);
			$rowY = $pdf->GetY();
			$pdf->SetTextColor(90, 90, 90);
			$pdf->MultiCell($width - $valueWidth, 4.2, $key, 0, 'L', false, 1, $x, $rowY, true, 0, false, true, 0, 'T');
			$keyBottom = $pdf->GetY();
			$pdf->SetTextColor(0, 0, 0);
			$pdf->MultiCell($valueWidth, 4.2, $value, 0, 'R', false, 1, $x + $width - $valueWidth, $rowY, true, 0, false, true, 0, 'T');
			$pdf->SetY(max($pdf->GetY(), $keyBottom));
		}
		if ($forecast !== null) {
			$pdf->SetFont(ReportPdf::FONT, '', 7, '', true);
			$pdf->SetTextColor(90, 90, 90);
			$pdf->MultiCell($width, 3.5, $this->l10n->t('Lineare Fortschreibung; saisonale Posten verzerren.'), 0, 'L', false, 1, $x, $pdf->GetY() + 0.5, true, 0, false, true, 0, 'T');
			$pdf->SetTextColor(0, 0, 0);
		}
		return $pdf->GetY();
	}

	/**
	 * Balken wie auf der Druckseite: die Spur ist der Plan, die Marke der
	 * zeitanteilige Plan, der Strich am Ende zeigt „über Plan“. Ungeplante
	 * Ausgaben (Ton `serious`) bekommen einen gestrichelten Rahmen.
	 */
	private function bar(ReportPdfDocument $pdf, float $x, float $y, float $width, float $height, int $plan, int $actual, ?float $factor, string $tone): void {
		if ($plan <= 0) {
			$over = $actual > 0;
			$fill = $over ? 1.0 : 0.0;
			$marker = null;
		} else {
			$fill = max(0.0, min(1.0, $actual / $plan));
			$marker = $factor !== null && $factor > 0 ? max(0.0, min(1.0, $factor)) : null;
			$over = $actual > $plan;
		}
		$color = $tone === 'none' ? [138, 138, 138] : self::TONE[$tone];
		$serious = $tone === 'serious';

		$pdf->SetLineStyle(['width' => 0.2, 'dash' => 0, 'color' => [136, 136, 136]]);
		$pdf->SetFillColor(232, 232, 232);
		$pdf->Rect($x, $y, $width, $height, 'F');
		if ($fill > 0) {
			// Ungeplant gilt als schwerwiegend: hell gefüllt und gestrichelt umrandet,
			// damit es sich auch schwarzweiß von „überschritten“ unterscheidet.
			$pdf->SetFillColor(...($serious ? [238, 190, 188] : $color));
			$pdf->Rect($x, $y, $width * $fill, $height, 'F');
		}
		$pdf->SetLineStyle(['width' => $serious ? 0.3 : 0.2, 'dash' => $serious ? '0.8,0.6' : 0, 'color' => $serious ? $color : [136, 136, 136]]);
		$pdf->Rect($x, $y, $width, $height, 'D');
		$pdf->SetLineStyle(['width' => 0.2, 'dash' => 0, 'color' => [0, 0, 0]]);
		$pdf->SetFillColor(34, 34, 34);
		if ($over) {
			$pdf->Rect($x + $width - 0.4, $y - 0.8, 0.8, $height + 1.6, 'F');
		}
		if ($marker !== null) {
			$pdf->Rect($x + $width * $marker - 0.25, $y - 0.8, 0.5, $height + 1.6, 'F');
		}
		$pdf->SetFillColor(242, 242, 242);
	}

	private function signedMoney(int $cents): string {
		return ($cents > 0 ? '+' : '') . $this->currency->format($cents);
	}

	/** @param array<string, int> $counts */
	private function distribution(string $title, string $nature, array $counts): string {
		$parts = [];
		foreach (self::ORDER as $level) {
			$parts[] = (int)($counts[$level] ?? 0) . ' ' . $this->printPage->meta($level, $nature)[1];
		}
		return $title . ': ' . implode(' · ', $parts);
	}

	/**
	 * Die beiden Listen der größten Abweichungen nebeneinander. Überschreitungen
	 * mit Vorzeichen; eine Lücke bei Einnahmen ist ein Fehlbetrag, ein „+“ davor
	 * läse sich wie ein Mehrertrag.
	 *
	 * @param list<array{0: string, 1: string, 2: list<array<string, mixed>>, 3: string}> $lists
	 */
	private function deviations(ReportPdf $report, array $lists): void {
		$pdf = $report->document();
		$gap = 8.0;
		$width = ($report->contentWidth() - $gap) / 2;
		$report->ensureSpace(25);
		$top = $pdf->GetY();
		$bottom = $top;
		foreach ($lists as $i => [$title, $nature, $items, $empty]) {
			$x = ReportPdf::MARGIN_LEFT + $i * ($width + $gap);
			$pdf->SetFont(ReportPdf::FONT, 'B', 10, '', true);
			$pdf->MultiCell($width, 6, $title, 0, 'L', false, 1, $x, $top, true, 0, false, true, 0, 'T');
			$y = $pdf->GetY() + 1;
			if ($items === []) {
				$pdf->SetFont(ReportPdf::FONT, 'I', 8.5, '', true);
				$pdf->SetTextColor(90, 90, 90);
				$pdf->MultiCell($width, 4.2, $empty, 0, 'L', false, 1, $x, $y, true, 0, false, true, 0, 'T');
				$pdf->SetTextColor(0, 0, 0);
				$bottom = max($bottom, $pdf->GetY());
				continue;
			}
			// Ampel und Betrag so breit wie ihr längster Eintrag, die Bezeichnung den Rest.
			$pdf->SetFont(ReportPdf::FONT, '', 8.5, '', true);
			$rows = [];
			$statusWidth = 0.0;
			$amountWidth = 0.0;
			foreach ($items as $item) {
				[$icon, $label, $tone] = $this->printPage->meta((string)$item['level'], $nature);
				$amount = (int)$item['amount'];
				$row = [$icon . ' ' . $label, $tone, trim($item['number'] . ' ' . $item['label']), $nature === 'income' ? $this->currency->format($amount) : $this->signedMoney($amount)];
				$statusWidth = max($statusWidth, $pdf->GetStringWidth($row[0]) + 4);
				$amountWidth = max($amountWidth, $pdf->GetStringWidth($row[3]) + 4);
				$rows[] = $row;
			}
			$labelWidth = $width - $statusWidth - $amountWidth;
			foreach ($rows as [$status, $tone, $text, $money]) {
				$height = max(5.0, $pdf->getStringHeight($labelWidth, $text));
				$pdf->SetTextColor(...self::TONE[$tone]);
				$pdf->MultiCell($statusWidth, $height, $status, 0, 'L', false, 0, $x, $y, true, 0, false, true, $height, 'M');
				$pdf->SetTextColor(0, 0, 0);
				$pdf->MultiCell($labelWidth, $height, $text, 0, 'L', false, 0, $x + $statusWidth, $y, true, 0, false, true, $height, 'M');
				$pdf->MultiCell($amountWidth, $height, $money, 0, 'R', false, 0, $x + $statusWidth + $labelWidth, $y, true, 0, false, true, $height, 'M');
				$y += $height;
				$pdf->SetDrawColor(221, 221, 221);
				$pdf->SetLineWidth(0.1);
				$pdf->Line($x, $y, $x + $width, $y);
				$pdf->SetLineWidth(0.2);
			}
			$bottom = max($bottom, $y);
		}
		$pdf->SetFont(ReportPdf::FONT, '', 9, '', true);
		$pdf->SetXY(ReportPdf::MARGIN_LEFT, $bottom + 5);
	}

	/** @param array<string, mixed> $visual */
	private function trend(ReportPdf $report, array $visual, int $total): void {
		$pdf = $report->document();
		// Eigener Text: das schlichte „Verlauf“ ist im Katalog „History“ (Änderungsverlauf).
		$title = $this->l10n->t('Verlauf (kumuliert)');
		$series = $visual['series'];
		$width = $report->contentWidth();
		$height = $width * TrendSvg::HEIGHT / TrendSvg::WIDTH;
		if ((int)$visual['asOf']['elapsed'] <= 0 || $series['months'] === [] || $total <= 0) {
			$report->heading($title);
			$report->paragraph($this->l10n->t('Die Periode hat noch nicht begonnen – der Verlauf erscheint ab ihrem ersten Monat.'), true);
			return;
		}
		// Überschrift, Diagramm und Legende bleiben auf einer Seite.
		$report->ensureSpace($height + 22);
		$report->heading($title);

		$first = new \DateTimeImmutable(substr((string)$visual['period']->getStartDate(), 0, 7) . '-01');
		$labels = [];
		for ($i = 0; $i < $total; $i++) {
			$labels[] = mb_substr(PeriodLabel::monthName((int)$first->modify('+' . $i . ' months')->format('n'), $this->l10n), 0, 3);
		}
		$kpis = $visual['kpis'];
		$svg = TrendSvg::render(
			$series,
			(int)$kpis['income']['plan'],
			(int)$kpis['expense']['plan'],
			$kpis['income']['forecast'] === null ? null : (int)$kpis['income']['forecast'],
			$kpis['expense']['forecast'] === null ? null : (int)$kpis['expense']['forecast'],
			$labels,
			fn (int $cents): string => $this->currency->format($cents),
			[],
			ReportPdf::FONT,
		);
		$y = $pdf->GetY();
		$pdf->ImageSVG('@' . $svg, ReportPdf::MARGIN_LEFT, $y, $width, $height);
		$pdf->SetLineStyle(['width' => 0.2, 'dash' => 0, 'color' => [0, 0, 0]]);
		$this->legend($pdf, $y + $height + 1.5);
	}

	/** Zwei Gruppen wie am Bildschirm: Farbe je Art, Linienart je Reihe. */
	private function legend(ReportPdfDocument $pdf, float $y): void {
		$income = self::rgb(TrendSvg::INCOME_COLOR);
		$expense = self::rgb(TrendSvg::EXPENSE_COLOR);
		$pdf->SetFont(ReportPdf::FONT, '', 7.5, '', true);
		$pdf->SetTextColor(90, 90, 90);
		$x = (float)ReportPdf::MARGIN_LEFT;
		foreach ([
			[$income, 0, $this->l10n->t('Einnahmen'), 0.0],
			[$expense, 0, $this->l10n->t('Ausgaben'), 0.0],
			[$expense, 0, $this->l10n->t('Ist'), 8.0],
			[$expense, '1.6,0.9', $this->l10n->t('Hochrechnung'), 0.0],
			[$expense, '0.4,0.9', $this->l10n->t('Plan'), 0.0],
		] as [$color, $dash, $text, $space]) {
			$x += $space;
			$pdf->SetLineStyle(['width' => 0.6, 'dash' => $dash, 'color' => $color, 'cap' => 'butt']);
			$pdf->Line($x, $y + 2, $x + 7, $y + 2);
			$x += 8.5;
			$textWidth = $pdf->GetStringWidth($text) + 3;
			$pdf->MultiCell($textWidth, 4, $text, 0, 'L', false, 0, $x - 1.5, $y, true, 0, false, true, 4, 'M');
			$x += $textWidth + 1;
		}
		$pdf->SetLineStyle(['width' => 0.2, 'dash' => 0, 'color' => [0, 0, 0]]);
		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetFont(ReportPdf::FONT, '', 9, '', true);
		$pdf->SetXY(ReportPdf::MARGIN_LEFT, $y + 8);
	}

	/** @param list<array<string, mixed>> $rows */
	private function breakdown(ReportPdf $report, array $rows, string $basis, ?float $factor): void {
		// Überschrift und Spaltenkopf nie allein am Seitenende: wenigstens ein paar Zeilen mit.
		$report->ensureSpace(40);
		$report->heading($this->l10n->t('Aufriss nach Vorlage'));
		$lines = [];
		foreach ($rows as $i => $row) {
			$lines[] = $this->row($row, $basis, $factor, self::hasRowsBelow($rows, $i));
		}
		$total = $report->contentWidth();
		$ratioLabel = $this->l10n->t('Ausschöpfung');
		// Beträge und Spaltenkopf brechen nicht um: gemessen in der breitesten Schrift
		// (fett, wie in Summenzeilen), plus Innenabstand.
		$pdf = $report->document();
		$pdf->SetFont(ReportPdf::FONT, 'B', 8.5, '', true);
		$amount = 14.0;
		foreach ($lines as $line) {
			if (count($line['cells']) === 6) {
				$amount = max($amount, $pdf->GetStringWidth($line['cells'][3]) + 4, $pdf->GetStringWidth($line['cells'][4]) + 4);
			}
		}
		$ratio = max(14.0, $pdf->GetStringWidth($ratioLabel) + 4);
		$pdf->SetFont(ReportPdf::FONT, '', 9, '', true);
		$label = $total - self::STATUS_MM - self::BAR_MM - 2 * $amount - $ratio;
		$columns = [
			['label' => $this->l10n->t('Bezeichnung'), 'width' => $label / $total],
			['label' => $this->l10n->t('Ampel'), 'width' => self::STATUS_MM / $total],
			['label' => $this->l10n->t('Ist zu Plan'), 'width' => self::BAR_MM / $total],
			['label' => $this->l10n->t('Ist'), 'width' => $amount / $total, 'align' => 'R'],
			['label' => $this->l10n->t('Plan'), 'width' => $amount / $total, 'align' => 'R'],
			['label' => $ratioLabel, 'width' => $ratio / $total, 'align' => 'R'],
		];
		$report->table($columns, $lines);

		$legend = [];
		if ($this->hasFlag($rows, StructuredReportBuilder::FLAG_INACTIVE)) {
			$legend[] = '† ' . $this->l10n->t('Konto nicht mehr aktiv');
		}
		if ($this->hasFlag($rows, StructuredReportBuilder::FLAG_DUPLICATE)) {
			$legend[] = '⚠ ' . $this->l10n->t('Konto ist in mehreren Abschnitten gezählt');
		}
		if ($legend !== []) {
			$report->paragraph(implode(' · ', $legend), true);
		}
	}

	/**
	 * Wie {@see GliederungsberichtVisuellRenderer}: Folgen auf die Kontozeile
	 * tiefer eingerückte Kontozeilen, bleibt sie bei der Zeile darunter.
	 *
	 * @param list<array<string, mixed>> $rows
	 */
	private static function hasRowsBelow(array $rows, int $index): bool {
		$row = $rows[$index];
		$next = $rows[$index + 1] ?? null;
		return ($row['kind'] ?? '') === 'account' && $next !== null
			&& ($next['kind'] ?? '') === 'account' && (int)$next['depth'] > (int)$row['depth'];
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed> Zeile für {@see ReportPdf::table()}
	 */
	private function row(array $row, string $basis, ?float $factor, bool $group): array {
		$kind = (string)$row['kind'];
		$indent = min((int)$row['depth'], self::MAX_DEPTH) * self::INDENT_MM;
		$label = (string)$row['label'];
		if ($kind === 'heading') {
			// Ein Abschnittstitel ersetzt den Spaltenkopf auf Folgeseiten nicht (wie im Tabellen-PDF).
			return (int)$row['depth'] === 0
				? ['style' => ReportPdf::ROW_HEAD, 'repeat' => false, 'span' => [6], 'align' => 'L', 'indent' => $indent, 'size' => 10.0, 'cells' => [$label]]
				: ['style' => ReportPdf::ROW_SUBHEAD, 'span' => [6], 'align' => 'L', 'indent' => $indent, 'size' => 9.0, 'cells' => [$label]];
		}

		$flags = is_array($row['flags'] ?? null) ? $row['flags'] : [];
		$nature = (string)($row['nature'] ?? 'expense');
		$covered = in_array('covered', $flags, true);
		$status = $row['status'][$basis] ?? ['level' => BudgetStatus::NONE, 'ratio' => null];
		$level = (string)$status['level'];
		[$icon, $statusLabel, $tone] = $this->printPage->meta($level, $nature);

		if ($kind === 'account') {
			$number = (string)($row['number'] ?? '');
			$label = ($number !== '' ? $number . ' ' : '') . $label
				. (in_array(StructuredReportBuilder::FLAG_INACTIVE, $flags, true) ? ' †' : '')
				. (in_array(StructuredReportBuilder::FLAG_DUPLICATE, $flags, true) ? ' ⚠' : '');
		}
		$isResult = $nature === 'result';
		$plan = (int)$row['plan'];
		$actual = (int)$row['actual'];
		$ratio = $isResult || ($status['ratio'] ?? null) === null ? '' : GliederungsberichtVisuellRenderer::percent((float)$status['ratio']);

		$out = [
			'style' => match ($kind) {
				'footer' => ReportPdf::ROW_SUM,
				'total' => ReportPdf::ROW_RESULT,
				default => ReportPdf::ROW_NORMAL,
			},
			'indent' => $indent,
			'cells' => [
				$label,
				$covered ? $this->l10n->t('im Oberkonto geplant') : $icon . ' ' . $statusLabel,
				'',
				$this->currency->format($actual),
				$this->currency->format($plan),
				$ratio,
			],
			'colors' => [1 => $covered ? self::TONE['none'] : self::TONE[$tone]],
			'muted' => in_array(StructuredReportBuilder::FLAG_STRUCTURAL, $flags, true),
			'keepWithNext' => $group,
		];
		// Gedeckte Unterkonten haben keinen eigenen Plan und deshalb keinen Balken
		// (Plan 0 läse sich als „weit über Plan“) – wie am Bildschirm.
		if (!$isResult && !$covered) {
			$out['decorate'] = function (ReportPdfDocument $pdf, array $cells, float $y, float $height) use ($plan, $actual, $factor, $tone): void {
				[$x, $width] = $cells[2];
				$this->bar($pdf, $x + 1.5, $y + ($height - 2.6) / 2, $width - 3, 2.6, $plan, $actual, $factor, $tone);
			};
		}
		return $out;
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

	/** @return array{0:int, 1:int, 2:int} */
	private static function rgb(string $hex): array {
		return [(int)hexdec(substr($hex, 1, 2)), (int)hexdec(substr($hex, 3, 2)), (int)hexdec(substr($hex, 5, 2))];
	}
}
