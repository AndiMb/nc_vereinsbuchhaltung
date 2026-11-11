<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\ClubClock;
use OCA\Vereinsbuchhaltung\Service\ClubCurrency;
use OCA\Vereinsbuchhaltung\Service\PeriodLabel;
use OCA\Vereinsbuchhaltung\Service\Report\BudgetStatus;
use OCA\Vereinsbuchhaltung\Service\Report\ReportAsOf;
use OCA\Vereinsbuchhaltung\Service\Report\StructuredReportBuilder;
use OCA\Vereinsbuchhaltung\Service\Report\VisualReportService;
use OCP\IAppConfig;
use OCP\IL10N;

/**
 * Die visuelle Gliederungsansicht (Plan gegen Ist) als druckfertige
 * HTML-Seite: Kennzahlen, Ampelverteilung, größte Abweichungen, Verlauf und
 * der Aufriss nach der Vorlage.
 *
 * Gezeigt wird genau, was {@see VisualReportService::build()} liefert; die
 * Ampelstufen werden hier nicht nachgerechnet. Die Texte je Stufe sind
 * dieselben wie am Bildschirm (`statusMeta` in `src/lib/budgetVisual.js`).
 * Balken sind reines HTML/CSS, der Verlauf ein Inline-SVG ({@see TrendSvg}) –
 * die Seite enthält kein Skript. Farben tragen keine eigene Information:
 * jede Stufe hat Symbol und Text, jeder Balken steht auch als Prozentzahl
 * in der Spalte „Ausschöpfung“.
 */
class GliederungsberichtVisuellRenderer {

	/** Reihenfolge der Stufen in der Verteilung, wie am Bildschirm */
	private const ORDER = [BudgetStatus::CRIT, BudgetStatus::UNPLANNED, BudgetStatus::WARN, BudgetStatus::GOOD];

	/** Feste, druckfähige Farben: Nextcloud-Variablen gibt es auf der Druckseite nicht. */
	private const STYLE = '
		* { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
		.note { font-size: 10pt; color: #555; margin: 0 0 12px; }
		.warn { border: 1px solid #c9a227; padding: 6px 10px; margin-bottom: 12px; font-size: 10pt; }
		.kpis { display: flex; gap: 16px; }
		.kpi { flex: 1; min-width: 0; }
		.kpi-actual { font-size: 15pt; font-weight: 600; margin: 2px 0; }
		.kpi dl { display: grid; grid-template-columns: auto auto; gap: 1px 8px; margin: 6px 0 0; font-size: 9.5pt; }
		.kpi dt { color: #555; }
		.kpi dd { margin: 0; text-align: right; white-space: nowrap; }
		.kpi .bar { margin-top: 4px; }
		.hint { font-size: 8.5pt; color: #555; margin: 4px 0 0; }
		.dist p { margin: 2px 0; }
		.cols { display: flex; gap: 16px; }
		.cols > div { flex: 1; min-width: 0; }
		ul.dev { list-style: none; margin: 0; padding: 0; font-size: 10pt; }
		ul.dev li { display: flex; gap: 8px; border-bottom: 1px solid #ddd; padding: 2px 0; }
		ul.dev .label { flex: 1; }
		ul.dev .amount { white-space: nowrap; }
		svg.trend { width: 100%; height: auto; display: block; }
		.legend { font-size: 9pt; color: #555; margin: 4px 0 0; }
		.legend .group + .group { margin-left: 24px; }
		.legend .sample { display: inline-block; width: 22px; vertical-align: middle; margin: 0 4px 0 10px; border-top: 2px solid #222; }
		.legend .group .sample:first-child { margin-left: 0; }
		.legend .sample.income { border-top-color: #5b7a99; }
		.legend .sample.plan { border-top-style: dotted; }
		.legend .sample.forecast { border-top-style: dashed; }
		section.breakdown { page-break-inside: auto; }
		section.breakdown tr { page-break-inside: avoid; }
		thead { display: table-header-group; }
		h2 { page-break-after: avoid; }
		tr.heading td { font-weight: 600; border-bottom: none; padding-top: 10px; }
		tr.heading.top td { font-size: 12pt; border-bottom: 1px solid #999; }
		tr.structural td { color: #555; }
		td.bar-cell { width: 28mm; vertical-align: middle; }
		.status { white-space: nowrap; }
		.status .sym { display: inline-block; width: 1.1em; text-align: center; font-weight: 700; }
		.tone-good .sym { color: #2d7d46; }
		.tone-warn .sym { color: #c77700; }
		.tone-crit .sym, .tone-serious .sym { color: #c9302c; }
		.tone-none .sym { color: #777; }
		.bar { position: relative; display: block; height: 10px; background: #e8e8e8; border: 1px solid #888; }
		.bar .fill { position: absolute; left: 0; top: 0; bottom: 0; background: #8a8a8a; }
		.bar.tone-good .fill { background: #2d7d46; }
		.bar.tone-warn .fill { background: #c77700; }
		.bar.tone-crit .fill { background: #c9302c; }
		.bar.tone-serious { border: 1px dashed #c9302c; }
		.bar.tone-serious .fill { background: repeating-linear-gradient(45deg, #c9302c 0 2px, #fff 2px 5px); }
		.bar .over { position: absolute; right: -2px; top: -3px; bottom: -3px; width: 3px; background: #222; }
		.bar .mark { position: absolute; top: -3px; bottom: -3px; width: 2px; margin-left: -1px; background: #222; }
	';

	public function __construct(
		private IAppConfig $config,
		private IL10N $l10n,
		private ClubClock $clock,
		private ClubCurrency $currency,
		private GliederungsberichtRenderer $table,
	) {
	}

	/**
	 * @param array<string, mixed> $visual aus {@see VisualReportService::build()}
	 * @param string $basis `year` oder `time`; alles andere gilt als `year`
	 */
	public function render(array $visual, string $basis): string {
		$asOf = $visual['asOf'];
		$elapsed = (int)$asOf['elapsed'];
		$total = (int)$asOf['total'];
		// Ohne vergangenen Monat gibt es keinen Zeitanteil: wie am Bildschirm
		// gilt dann der Jahresplan, und der Kopf sagt es.
		$fallback = $basis === 'time' && $elapsed <= 0;
		$basis = $basis === 'time' && !$fallback ? 'time' : 'year';
		$factor = $elapsed > 0 && $total > 0 ? $elapsed / $total : null;

		$period = $visual['period'];
		$clubName = $this->config->getValueString(Application::APP_ID, 'club_name', '');
		$heading = (string)$visual['template']['name'];
		$basisText = $basis === 'time'
			? $this->l10n->t('Vergleich gegen: zeitanteiligen Plan (%1$s von %2$s Monaten)', [$elapsed, $total])
			: $this->l10n->t('Vergleich gegen: Jahresplan');
		$meta = [
			PrintableReportPage::escape((string)$period->getLabel()),
			PrintableReportPage::escape($this->l10n->t('Stand: %s', [$this->asOfLabel($asOf)])),
			PrintableReportPage::escape($basisText),
			PrintableReportPage::escape($this->l10n->t('Erstellt am %s', [ReportFormat::date($this->clock->today())])),
		];

		$h = '<style>' . self::STYLE . '</style>';
		$h .= PrintableReportPage::printHint($this->l10n->t('Zum Drucken oder Als-PDF-Speichern: <strong>Strg+P</strong> (Mac: ⌘P) im Browser.'));
		$h .= PrintableReportPage::header(null, $clubName, $heading, implode(' · ', $meta));
		if ($fallback) {
			$h .= '<p class="note">' . PrintableReportPage::escape($this->l10n->t('Die Periode hat noch nicht begonnen – der zeitanteilige Vergleich und die Hochrechnung entfallen.')) . '</p>';
		}
		foreach ($visual['warnings'] as $warning) {
			$h .= '<p class="warn">⚠ ' . PrintableReportPage::escape($this->table->warning($warning)) . '</p>';
		}

		$kpis = $visual['kpis'];
		$noPlan = (int)$kpis['income']['plan'] === 0 && (int)$kpis['expense']['plan'] === 0;
		if ($noPlan && (int)$kpis['income']['actual'] === 0 && (int)$kpis['expense']['actual'] === 0) {
			$h .= '<p class="note">' . PrintableReportPage::escape($this->l10n->t('Für diese Periode gibt es weder Planwerte noch Buchungen. Die Planwerte tragen Sie im Finanzplan ein.')) . '</p>';
			return PrintableReportPage::document(($clubName !== '' ? $clubName . ' – ' : '') . $heading, $h, darkOnScreen: false);
		}
		if ($noPlan) {
			$h .= '<p class="warn">' . PrintableReportPage::escape($this->l10n->t('Für diese Periode ist noch kein Plan erfasst; alle Konten mit Buchungen gelten als ungeplant. Die Planwerte tragen Sie im Finanzplan ein.')) . '</p>';
		}

		$h .= '<section class="kpis">'
			. $this->kpi($this->l10n->t('Einnahmen'), 'income', $kpis['income'], $basis, $factor)
			. $this->kpi($this->l10n->t('Ausgaben'), 'expense', $kpis['expense'], $basis, $factor)
			. $this->kpi($this->l10n->t('Ergebnis'), 'result', $kpis['result'], $basis, $factor)
			. '</section>';

		$dist = $visual['distribution'][$basis];
		$h .= '<section class="dist"><h2>' . PrintableReportPage::escape($this->l10n->t('Ampel')) . '</h2>'
			. $this->distribution($this->l10n->t('Ausgabenkonten'), 'expense', $dist['expense'])
			. $this->distribution($this->l10n->t('Einnahmenkonten'), 'income', $dist['income'])
			. '</section>';

		$dev = $visual['deviations'][$basis];
		$h .= '<section class="cols"><div>'
			. $this->deviations($this->l10n->t('Größte Überschreitungen (Ausgaben)'), 'expense', $dev['expense'], $this->l10n->t('Kein Ausgabenkonto liegt über dem Plan.'))
			. '</div><div>'
			. $this->deviations($this->l10n->t('Größte Lücken (Einnahmen)'), 'income', $dev['income'], $this->l10n->t('Kein Einnahmenkonto liegt unter dem Plan.'))
			. '</div></section>';

		$h .= $this->trend($visual, $total);
		$h .= $this->breakdown($visual['rows'], $basis, $factor);

		// Feste Druckfarben und das SVG: auch am dunklen Bildschirm heller Grund.
		return PrintableReportPage::document(($clubName !== '' ? $clubName . ' – ' : '') . $heading, $h, darkOnScreen: false);
	}

	/** @param array{value:string} $asOf */
	private function asOfLabel(array $asOf): string {
		if ($asOf['value'] === ReportAsOf::CURRENT) {
			return $this->l10n->t('Aktuell (alle Buchungen)');
		}
		$first = new \DateTimeImmutable($asOf['value'] . '-01');
		return PeriodLabel::months($first->format('Y-m-d'), $first->format('Y-m-t'), $this->l10n) ?? $asOf['value'];
	}

	/**
	 * Symbol, Text und Ton einer Stufe – wie `statusMeta()` am Bildschirm.
	 *
	 * @return array{string, string, string}
	 */
	private function meta(string $level, string $nature): array {
		$expense = $nature === 'expense';
		$result = $nature === 'result';
		return match ($level) {
			BudgetStatus::GOOD => ['✓', $expense ? $this->l10n->t('im Rahmen') : $this->l10n->t('im Plan'), 'good'],
			BudgetStatus::WARN => ['!', $result ? $this->l10n->t('leicht unter Plan') : $this->l10n->t('beobachten'), 'warn'],
			BudgetStatus::CRIT => ['✕', $expense ? $this->l10n->t('überschritten') : ($result ? $this->l10n->t('deutlich unter Plan') : $this->l10n->t('unter Plan')), 'crit'],
			BudgetStatus::UNPLANNED => ['?', $result ? '–' : $this->l10n->t('ungeplant'), $expense ? 'serious' : 'good'],
			default => ['–', $this->l10n->t('ohne Werte'), 'none'],
		};
	}

	private function badge(string $level, string $nature): string {
		[$icon, $label, $tone] = $this->meta($level, $nature);
		return '<span class="status tone-' . $tone . '"><span class="sym">' . $icon . '</span> ' . PrintableReportPage::escape($label) . '</span>';
	}

	/**
	 * Balken wie `bulletGeometry()` am Bildschirm: die Spur ist der Plan,
	 * die Marke der zeitanteilige Plan, der Strich am Ende zeigt „über Plan“.
	 * Nur Zierde – dieselbe Angabe steht als Prozentzahl daneben.
	 */
	private function bar(int $plan, int $actual, ?float $factor, string $tone): string {
		if ($plan <= 0) {
			$over = $actual > 0;
			$fill = $over ? 100.0 : 0.0;
			$marker = null;
		} else {
			$fill = max(0.0, min(100.0, $actual / $plan * 100));
			$marker = $factor !== null && $factor > 0 ? max(0.0, min(100.0, $factor * 100)) : null;
			$over = $actual > $plan;
		}
		return '<span class="bar tone-' . $tone . '" aria-hidden="true">'
			. '<span class="fill" style="width:' . round($fill, 1) . '%"></span>'
			. ($over ? '<span class="over"></span>' : '')
			. ($marker !== null ? '<span class="mark" style="left:' . round($marker, 1) . '%"></span>' : '')
			. '</span>';
	}

	private function money(int $cents): string {
		return PrintableReportPage::escape($this->currency->format($cents));
	}

	private function signedMoney(int $cents): string {
		return ($cents > 0 ? '+' : '') . $this->money($cents);
	}

	/** Ausschöpfung als ganze Prozent, „“ ohne Verhältnis. */
	private static function percent(?float $ratio): string {
		return $ratio === null ? '' : number_format(round($ratio * 100), 0, ',', '.') . ' %';
	}

	/** @param array<string, mixed> $kpi */
	private function kpi(string $title, string $nature, array $kpi, string $basis, ?float $factor): string {
		$status = $kpi['status'][$basis] ?? ['level' => BudgetStatus::NONE, 'ratio' => null];
		$plan = (int)$kpi['plan'];
		$actual = (int)$kpi['actual'];
		$forecast = $kpi['forecast'] ?? null;

		$h = '<div class="kpi"><h2>' . PrintableReportPage::escape($title) . '</h2>'
			. '<div class="kpi-actual">' . $this->money($actual) . '</div>'
			. '<div>' . $this->badge((string)$status['level'], $nature) . '</div>';
		if ($nature !== 'result') {
			$h .= $this->bar($plan, $actual, $factor, $this->meta((string)$status['level'], $nature)[2]);
		}
		$h .= '<dl><dt>' . PrintableReportPage::escape($this->l10n->t('Ist')) . '</dt><dd>' . $this->money($actual) . '</dd>'
			. '<dt>' . PrintableReportPage::escape($this->l10n->t('Plan')) . '</dt><dd>' . $this->money($plan) . '</dd>';
		if ($nature === 'result') {
			$h .= '<dt>' . PrintableReportPage::escape($this->l10n->t('Differenz')) . '</dt><dd>' . $this->signedMoney($actual - $plan) . '</dd>';
		} else {
			$ratio = $status['ratio'] ?? null;
			$h .= '<dt>' . PrintableReportPage::escape($this->l10n->t('Ausschöpfung')) . '</dt><dd>' . ($ratio === null ? '–' : self::percent((float)$ratio)) . '</dd>';
		}
		if ($factor !== null) {
			$h .= '<dt>' . PrintableReportPage::escape($this->l10n->t('Zeitanteiliger Plan')) . '</dt><dd>' . $this->money((int)round($plan * $factor)) . '</dd>';
		}
		if ($forecast !== null) {
			$h .= '<dt>' . PrintableReportPage::escape($this->l10n->t('Hochrechnung')) . '</dt><dd>' . $this->money((int)$forecast) . '</dd>';
		}
		$h .= '</dl>';
		if ($forecast !== null) {
			$h .= '<p class="hint">' . PrintableReportPage::escape($this->l10n->t('Lineare Fortschreibung; saisonale Posten verzerren.')) . '</p>';
		}
		return $h . '</div>';
	}

	/** @param array<string, int> $counts */
	private function distribution(string $title, string $nature, array $counts): string {
		$parts = [];
		foreach (self::ORDER as $level) {
			$parts[] = (int)($counts[$level] ?? 0) . ' ' . $this->meta($level, $nature)[1];
		}
		return '<p>' . PrintableReportPage::escape($title . ': ' . implode(' · ', $parts)) . '</p>';
	}

	/**
	 * Überschreitungen mit Vorzeichen; eine Lücke bei Einnahmen ist ein
	 * Fehlbetrag, ein „+“ davor läse sich wie ein Mehrertrag.
	 *
	 * @param list<array<string, mixed>> $items
	 */
	private function deviations(string $title, string $nature, array $items, string $empty): string {
		$h = '<h2>' . PrintableReportPage::escape($title) . '</h2>';
		if ($items === []) {
			return $h . '<p class="note">' . PrintableReportPage::escape($empty) . '</p>';
		}
		$h .= '<ul class="dev">';
		foreach ($items as $item) {
			$amount = (int)$item['amount'];
			$h .= '<li>' . $this->badge((string)$item['level'], $nature)
				. '<span class="label">' . PrintableReportPage::escape(trim($item['number'] . ' ' . $item['label'])) . '</span>'
				. '<span class="amount">' . ($nature === 'income' ? $this->money($amount) : $this->signedMoney($amount)) . '</span></li>';
		}
		return $h . '</ul>';
	}

	/** @param array<string, mixed> $visual */
	private function trend(array $visual, int $total): string {
		// Eigener Text: das schlichte „Verlauf“ ist im Katalog „History“ (Änderungsverlauf).
		$title = $this->l10n->t('Verlauf (kumuliert)');
		$h = '<section><h2>' . PrintableReportPage::escape($title) . '</h2>';
		$series = $visual['series'];
		if ((int)$visual['asOf']['elapsed'] <= 0 || $series['months'] === [] || $total <= 0) {
			return $h . '<p class="note">' . PrintableReportPage::escape($this->l10n->t('Die Periode hat noch nicht begonnen – der Verlauf erscheint ab ihrem ersten Monat.')) . '</p></section>';
		}

		// Monate der Periode ab ihrem Beginn, kurz beschriftet („Okt“)
		$first = new \DateTimeImmutable(substr((string)$visual['period']->getStartDate(), 0, 7) . '-01');
		$labels = [];
		for ($i = 0; $i < $total; $i++) {
			$labels[] = mb_substr(PeriodLabel::monthName((int)$first->modify('+' . $i . ' months')->format('n'), $this->l10n), 0, 3);
		}
		$kpis = $visual['kpis'];
		$h .= TrendSvg::render(
			$series,
			(int)$kpis['income']['plan'],
			(int)$kpis['expense']['plan'],
			$kpis['income']['forecast'] === null ? null : (int)$kpis['income']['forecast'],
			$kpis['expense']['forecast'] === null ? null : (int)$kpis['expense']['forecast'],
			$labels,
			fn (int $cents): string => $this->currency->format($cents),
			['title' => $title, 'desc' => $this->l10n->t('Verlauf: kumuliertes Ist je Monat mit Plan-Linie und Hochrechnung.')],
		);
		// Zwei Gruppen wie am Bildschirm: Farbe je Art, Linienart je Reihe.
		$h .= '<p class="legend">'
			. '<span class="group">'
			. '<span class="sample income"></span>' . PrintableReportPage::escape($this->l10n->t('Einnahmen'))
			. '<span class="sample"></span>' . PrintableReportPage::escape($this->l10n->t('Ausgaben'))
			. '</span><span class="group">'
			. '<span class="sample"></span>' . PrintableReportPage::escape($this->l10n->t('Ist'))
			. '<span class="sample forecast"></span>' . PrintableReportPage::escape($this->l10n->t('Hochrechnung'))
			. '<span class="sample plan"></span>' . PrintableReportPage::escape($this->l10n->t('Plan'))
			. '</span></p>';
		return $h . '</section>';
	}

	/** @param list<array<string, mixed>> $rows */
	private function breakdown(array $rows, string $basis, ?float $factor): string {
		// Der Aufriss darf über Seiten laufen (die gemeinsame Regel hält jede
		// section zusammen und schöbe ihn ganz auf die nächste Seite); der
		// Tabellenkopf wiederholt sich, Zeilen bleiben ganz.
		$h = '<section class="breakdown"><h2>' . PrintableReportPage::escape($this->l10n->t('Aufriss nach Vorlage')) . '</h2>'
			. '<table><thead><tr><th>' . PrintableReportPage::escape($this->l10n->t('Bezeichnung')) . '</th>'
			. '<th>' . PrintableReportPage::escape($this->l10n->t('Ampel')) . '</th>'
			. '<th>' . PrintableReportPage::escape($this->l10n->t('Ist zu Plan')) . '</th>'
			. '<th class="num">' . PrintableReportPage::escape($this->l10n->t('Ist')) . '</th>'
			. '<th class="num">' . PrintableReportPage::escape($this->l10n->t('Plan')) . '</th>'
			. '<th class="num">' . PrintableReportPage::escape($this->l10n->t('Ausschöpfung')) . '</th></tr></thead><tbody>';
		foreach ($rows as $row) {
			$h .= $this->row($row, $basis, $factor);
		}
		$h .= '</tbody></table>';

		$legend = [];
		if ($this->hasFlag($rows, StructuredReportBuilder::FLAG_INACTIVE)) {
			$legend[] = '† ' . PrintableReportPage::escape($this->l10n->t('Konto nicht mehr aktiv'));
		}
		if ($this->hasFlag($rows, StructuredReportBuilder::FLAG_DUPLICATE)) {
			$legend[] = '⚠ ' . PrintableReportPage::escape($this->l10n->t('Konto ist in mehreren Abschnitten gezählt'));
		}
		if ($legend !== []) {
			$h .= '<p class="meta">' . implode(' · ', $legend) . '</p>';
		}
		return $h . '</section>';
	}

	/** @param array<string, mixed> $row */
	private function row(array $row, string $basis, ?float $factor): string {
		$kind = (string)$row['kind'];
		$indent = ' style="padding-left:' . (6 + min((int)$row['depth'], 8) * 14) . 'px"';
		$label = PrintableReportPage::escape((string)$row['label']);
		if ($kind === 'heading') {
			$class = (int)$row['depth'] === 0 ? 'heading top' : 'heading';
			return '<tr class="' . $class . '"><td colspan="6"' . $indent . '>' . $label . '</td></tr>';
		}

		$flags = is_array($row['flags'] ?? null) ? $row['flags'] : [];
		$nature = (string)($row['nature'] ?? 'expense');
		$covered = in_array('covered', $flags, true);
		$status = $row['status'][$basis] ?? ['level' => BudgetStatus::NONE, 'ratio' => null];
		$level = (string)$status['level'];

		if ($kind === 'account') {
			$number = (string)($row['number'] ?? '');
			$label = ($number !== '' ? PrintableReportPage::escape($number) . ' ' : '') . $label
				. (in_array(StructuredReportBuilder::FLAG_INACTIVE, $flags, true) ? ' †' : '')
				. (in_array(StructuredReportBuilder::FLAG_DUPLICATE, $flags, true) ? ' ⚠' : '');
		}
		$class = match ($kind) {
			'footer' => ' class="sum"',
			'total' => ' class="result"',
			default => in_array(StructuredReportBuilder::FLAG_STRUCTURAL, $flags, true) ? ' class="structural"' : '',
		};

		$statusCell = $covered
			? '<span class="status">' . PrintableReportPage::escape($this->l10n->t('im Oberkonto geplant')) . '</span>'
			: $this->badge($level, $nature);
		$isResult = $nature === 'result';
		// Gedeckte Unterkonten haben keinen eigenen Plan und deshalb keinen
		// Balken (Plan 0 läse sich als „weit über Plan“) – wie am Bildschirm.
		$bar = $isResult || $covered ? '' : $this->bar((int)$row['plan'], (int)$row['actual'], $factor, $this->meta($level, $nature)[2]);
		$ratio = $isResult || ($status['ratio'] ?? null) === null ? '' : self::percent((float)$status['ratio']);

		return '<tr' . $class . '><td' . $indent . '>' . $label . '</td>'
			. '<td>' . $statusCell . '</td>'
			. '<td class="bar-cell">' . $bar . '</td>'
			. '<td class="num">' . $this->money((int)$row['actual']) . '</td>'
			. '<td class="num">' . $this->money((int)$row['plan']) . '</td>'
			. '<td class="num">' . $ratio . '</td></tr>';
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
