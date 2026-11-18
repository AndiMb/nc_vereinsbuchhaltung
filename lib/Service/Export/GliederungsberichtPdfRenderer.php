<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

use OCA\Vereinsbuchhaltung\Service\AsciiFileName;
use OCA\Vereinsbuchhaltung\Service\ClubClock;
use OCA\Vereinsbuchhaltung\Service\ClubCurrency;
use OCA\Vereinsbuchhaltung\Service\Pdf\ReportPdf;
use OCA\Vereinsbuchhaltung\Service\Pdf\ReportPdfFactory;
use OCA\Vereinsbuchhaltung\Service\Report\StructuredReportBuilder;
use OCA\Vereinsbuchhaltung\Service\Report\StructuredReportService;
use OCP\IL10N;

/**
 * Der Gliederungsbericht (Tabellenansicht) als PDF – derselbe Inhalt wie die
 * Druckseite ({@see GliederungsberichtRenderer}), aber als Datei mit
 * Seitenzahlen und ohne Browser-Kopf und -Fuß, zum Weitergeben an den
 * Zuwendungsgeber oder zur Ablage. Die Zeilenliste liefert unverändert
 * {@see StructuredReportService::build()}; hier entsteht nur das Layout.
 */
class GliederungsberichtPdfRenderer {

	/** Breite der Nummernspalte in mm. */
	private const NUMBER_MM = 16.0;
	/** Mindestbreite der Bezeichnung in mm, damit lange Kontonamen nicht zu oft umbrechen. */
	private const LABEL_MIN_MM = 50.0;
	/** Breite einer Wertspalte in mm: reicht für „-1.234.567,89 €“, wird bei sehr vielen Spalten enger. */
	private const VALUE_MAX_MM = 26.0;
	/** Wie die Druckseite: tiefer eingerückt wird nicht mehr. */
	private const MAX_DEPTH = 8;
	private const INDENT_MM = 3.5;

	public function __construct(
		private ReportPdfFactory $factory,
		private GliederungsberichtRenderer $printPage,
		private ClubCurrency $currency,
		private IL10N $l10n,
		private ClubClock $clock,
	) {
	}

	/**
	 * Dateiname, z. B. „Gliederungsbericht-Ergebnis-je-Bereich-2026.pdf“; Umlaute
	 * ausgeschrieben, Rest siehe {@see AsciiFileName} (wie beim Kassenbericht).
	 *
	 * @param array<string, mixed> $report aus {@see StructuredReportService::build()}
	 */
	public function fileName(array $report): string {
		return 'Gliederungsbericht-' . AsciiFileName::of((string)$report['template']['name']) . '-' . AsciiFileName::of((string)$report['period']->getLabel()) . '.pdf';
	}

	/**
	 * @param array<string, mixed> $report aus {@see StructuredReportService::build()}
	 * @param bool $compress false erzeugt lesbare Streams (nur für Tests)
	 * @return string PDF-Bytes
	 */
	public function render(array $report, bool $compress = true): string {
		$columns = $report['columns'];
		$name = (string)$report['template']['name'];
		$clubName = $this->factory->clubName();
		$today = ReportFormat::date($this->clock->today());

		// Ab fünf Wertspalten quer – dieselbe Schwelle wie auf der Druckseite.
		$pdf = $this->factory->create(
			($clubName !== '' ? $clubName . ' – ' : '') . $name,
			$this->l10n->t('Erstellt am %s', [$today]),
			$compress,
			count($columns) > 4,
		);
		$pdf->titleBlock($clubName, $name, $report['period']->getLabel() . ' · ' . $this->l10n->t('Stand: %s', [$today]), $this->factory->logo());

		foreach ($report['warnings'] as $warning) {
			$pdf->paragraph('⚠ ' . $this->printPage->warning($warning));
		}

		$pdf->table($this->columns($pdf->contentWidth(), $columns), array_map(fn (array $row): array => $this->row($row, count($columns)), $report['rows']));

		$legend = [];
		if ($this->hasFlag($report['rows'], StructuredReportBuilder::FLAG_INACTIVE)) {
			$legend[] = '† ' . $this->l10n->t('Konto nicht mehr aktiv');
		}
		if ($this->hasFlag($report['rows'], StructuredReportBuilder::FLAG_DUPLICATE)) {
			$legend[] = '⚠ ' . $this->l10n->t('Konto ist in mehreren Abschnitten gezählt');
		}
		if ($legend !== []) {
			$pdf->paragraph(implode(' · ', $legend), true);
		}
		return $pdf->output();
	}

	/**
	 * Nummer und Wertspalten haben feste Breiten, die Bezeichnung nimmt den Rest:
	 * Sie ist der Text, der Platz braucht, die Beträge brauchen nur ihre Ziffern.
	 *
	 * @param list<array{label: string}> $columns
	 * @return list<array{label: string, width: float, align?: string}>
	 */
	private function columns(float $total, array $columns): array {
		// Bei sehr vielen Spalten schrumpfen die Wertspalten gleichmäßig, statt dass die
		// Bezeichnung unter ihre Mindestbreite (oder ins Negative) rutscht.
		$value = min(self::VALUE_MAX_MM, ($total - self::NUMBER_MM - self::LABEL_MIN_MM) / max(1, count($columns)));
		$out = [
			['label' => $this->l10n->t('Nr.'), 'width' => self::NUMBER_MM / $total],
			['label' => $this->l10n->t('Bezeichnung'), 'width' => ($total - self::NUMBER_MM - $value * count($columns)) / $total],
		];
		foreach ($columns as $c) {
			$out[] = ['label' => (string)$c['label'], 'width' => $value / $total, 'align' => 'R'];
		}
		return $out;
	}

	/**
	 * Zeilenarten wie {@see GliederungsberichtRenderer}: Überschrift, Erläuterung,
	 * Konto, freie Zeile, Gesamtergebnis, sonst Summe.
	 *
	 * @param array<string, mixed> $row
	 * @return array{cells: list<string>, style?: string, span?: list<int>, indent?: float, indentCell?: int, repeat?: bool, align?: string, muted?: bool}
	 */
	private function row(array $row, int $width): array {
		$indent = min((int)$row['depth'], self::MAX_DEPTH) * self::INDENT_MM;
		$label = (string)$row['label'];
		$wide = [$width + 2];
		switch ($row['kind']) {
			case 'heading':
				// Ein Abschnittstitel über die volle Breite würde als Zwischenkopf die
				// Spaltenköpfe ersetzen; `repeat => false` hält sie auf Folgeseiten.
				return $row['depth'] === 0
					? ['style' => ReportPdf::ROW_HEAD, 'repeat' => false, 'span' => $wide, 'align' => 'L', 'cells' => [$label]]
					: ['style' => ReportPdf::ROW_SUBHEAD, 'span' => $wide, 'align' => 'L', 'indent' => $indent, 'cells' => [$label]];
			case 'note':
				return ['style' => ReportPdf::ROW_NOTE, 'span' => $wide, 'align' => 'L', 'indent' => $indent, 'cells' => [$label]];
			case 'account':
				$flags = $row['flags'];
				$marks = (in_array(StructuredReportBuilder::FLAG_INACTIVE, $flags, true) ? ' †' : '')
					. (in_array(StructuredReportBuilder::FLAG_DUPLICATE, $flags, true) ? ' ⚠' : '');
				// Wie die Druckseite (Klasse „structural“): strukturelle Konten grau absetzen.
				return ['indent' => $indent, 'indentCell' => 1, 'muted' => in_array(StructuredReportBuilder::FLAG_STRUCTURAL, $flags, true), 'cells' => [(string)$row['number'], $label . $marks, ...$this->cells($row['values'])]];
			case 'line':
				return ['indent' => $indent, 'indentCell' => 1, 'cells' => ['', $label, ...$this->cells($row['values'])]];
			case 'total':
				return ['style' => ReportPdf::ROW_RESULT, 'span' => [2], 'cells' => [$label, ...$this->cells($row['values'])]];
			default:
				return ['style' => ReportPdf::ROW_SUM, 'indent' => $indent, 'indentCell' => 1, 'cells' => ['', $label, ...$this->cells($row['values'])]];
		}
	}

	/**
	 * Ein fehlender Wert (Spalte ohne Periode) bleibt ein Strich statt „0,00“ –
	 * wie auf der Druckseite.
	 *
	 * @param list<?int> $values
	 * @return list<string>
	 */
	private function cells(array $values): array {
		return array_map(fn (?int $v): string => $v === null ? '–' : ReportFormat::cents($v, $this->currency->symbol()), $values);
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
