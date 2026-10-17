<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

use OCA\Vereinsbuchhaltung\Service\BudgetTree;
use OCP\IL10N;

/**
 * Die Auffälligkeiten des Planbaums als Zeichen – für die Druckseiten.
 *
 * Zeichen statt Farben, damit sie auch auf einem Schwarzweiß-Ausdruck
 * erkennbar bleiben. Finanzplan-Druck ({@see FinanzplanRenderer}) und
 * Kassenbericht ({@see KassenberichtRenderer}) verwenden dieselben Zeichen
 * und dieselbe Legende.
 */
final class BudgetMarks {

	/** Zeichen der Auffälligkeiten, in der Reihenfolge der Legende. */
	public const MARKS = [
		BudgetTree::FLAG_OVERRUN => '▲',
		BudgetTree::FLAG_UNPLANNED => '○',
		BudgetTree::FLAG_UNPLANNED_BELOW => '◌',
		BudgetTree::FLAG_CONFLICT => '⚠',
	];

	/**
	 * Die Zeichen zu den Flags eines Knotens, je mit Leerzeichen davor – oder
	 * '' ohne Auffälligkeit.
	 *
	 * @param list<string> $flags
	 */
	public static function marks(array $flags): string {
		$marks = '';
		foreach (self::MARKS as $flag => $mark) {
			if (in_array($flag, $flags, true)) {
				$marks .= ' ' . $mark;
			}
		}
		return $marks;
	}

	public static function legend(IL10N $l10n): string {
		$texts = [
			BudgetTree::FLAG_OVERRUN => $l10n->t('Ausgaben über Plan'),
			BudgetTree::FLAG_UNPLANNED => $l10n->t('Ist ohne Plan'),
			BudgetTree::FLAG_UNPLANNED_BELOW => $l10n->t('ungeplante Buchungen in Unterkonten'),
			BudgetTree::FLAG_CONFLICT => $l10n->t('Plankonflikt'),
		];
		$parts = [];
		foreach (self::MARKS as $flag => $mark) {
			$parts[] = $mark . ' ' . PrintableReportPage::escape($texts[$flag]);
		}
		return '<p class="meta">' . implode(' · ', $parts) . '<br>'
			. PrintableReportPage::escape($l10n->t('Plan und Ist schließen alle Unterkonten ein.')) . '</p>';
	}

	/** Warnung über der Tabelle, weil Konflikte die Plansummen doppelt zählen. */
	public static function conflictNote(IL10N $l10n, int $count): string {
		if ($count <= 0) {
			return '';
		}
		return '<p><strong>⚠ ' . PrintableReportPage::escape($l10n->n(
			'%n Plankonflikt: In einem Zweig ist auf mehreren Ebenen geplant, die Summen zählen diese Pläne doppelt.',
			'%n Plankonflikte: In einem Zweig ist auf mehreren Ebenen geplant, die Summen zählen diese Pläne doppelt.',
			$count,
		)) . '</strong></p>';
	}
}
