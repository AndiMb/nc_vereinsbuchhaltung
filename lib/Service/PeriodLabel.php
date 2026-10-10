<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCP\IL10N;

/**
 * Eine Beitragsperiode in Monatsnamen: „November 2026", „Oktober bis Dezember 2026".
 *
 * Für die Mails an Mitglieder: „01.11.2026 – 30.11.2026" sagt dasselbe, aber niemand liest es als
 * „der Beitrag für November". Die Monatsnamen folgen der Sprache des Empfängers ($l).
 */
final class PeriodLabel {
	/**
	 * @param string|null $start Beginn der Periode (JJJJ-MM-TT)
	 * @param string|null $end Ende der Periode (JJJJ-MM-TT)
	 * @return string|null `null`, wenn die Periode keine ganzen Monate umfasst – dann bleibt der Datumsbereich
	 *                     die ehrlichere Angabe
	 */
	public static function months(?string $start, ?string $end, IL10N $l): ?string {
		if ($start === null || $end === null || $start === '' || $end === '') {
			return null;
		}
		try {
			$from = new \DateTimeImmutable($start);
			$to = new \DateTimeImmutable($end);
		} catch (\Exception) {
			return null;
		}
		if ($from > $to || $from->format('j') !== '1' || $to->format('Y-m-d') !== $to->format('Y-m-t')) {
			return null;
		}

		$fromName = self::monthName((int)$from->format('n'), $l);
		$toName = self::monthName((int)$to->format('n'), $l);
		$fromYear = (int)$from->format('Y');
		$toYear = (int)$to->format('Y');
		if ($fromYear === $toYear && $from->format('n') === $to->format('n')) {
			return $l->t('%1$s %2$d', [$fromName, $fromYear]);
		}
		if ($fromYear === $toYear) {
			return $l->t('%1$s bis %2$s %3$d', [$fromName, $toName, $toYear]);
		}
		return $l->t('%1$s %2$d bis %3$s %4$d', [$fromName, $fromYear, $toName, $toYear]);
	}

	private static function monthName(int $month, IL10N $l): string {
		return match ($month) {
			1 => $l->t('Januar'),
			2 => $l->t('Februar'),
			3 => $l->t('März'),
			4 => $l->t('April'),
			5 => $l->t('Mai'),
			6 => $l->t('Juni'),
			7 => $l->t('Juli'),
			8 => $l->t('August'),
			9 => $l->t('September'),
			10 => $l->t('Oktober'),
			11 => $l->t('November'),
			default => $l->t('Dezember'),
		};
	}
}
