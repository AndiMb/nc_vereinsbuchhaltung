<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Report;

/**
 * Der Stand des Gliederungsberichts: bis zu welchem Monat gerechnet wird und
 * wie viel der Periode (in Kalendermonaten) schon vergangen ist.
 *
 * Der Stand ist ein Monat (`YYYY-MM`, bis Monatsende) oder `current` (die
 * ganze Periode bis zu ihrem Ende, Zeitanteil bis zum heutigen Monat).
 * „Heute“ kommt immer als Argument herein; die Klasse liest keine Uhr.
 *
 * Wie {@see BudgetStatus} statisch und ohne Datenbank.
 */
final class ReportAsOf {

	public const CURRENT = 'current';

	/**
	 * @param string $start Periodenbeginn `YYYY-MM-DD`
	 * @param string $end Periodenende `YYYY-MM-DD`
	 * @param string $today heute `YYYY-MM-DD`
	 * @param string|null $asOf `YYYY-MM`, `current` oder null (Vorgabe)
	 * @return array{value:string, until:string, elapsed:int, total:int, options:list<string>, default:string}
	 * @throws \InvalidArgumentException bei einem Stand, der nicht zur Auswahl gehört
	 */
	public static function resolve(string $start, string $end, string $today, ?string $asOf): array {
		$startMonth = self::monthIndex($start);
		$endMonth = self::monthIndex($end);
		$todayMonth = self::monthIndex($today);
		$total = $endMonth - $startMonth + 1;

		$options = [];
		for ($m = $startMonth; $m <= min($todayMonth, $endMonth); $m++) {
			$options[] = self::monthLabel($m);
		}

		if ($today > $end) {
			$default = self::monthLabel($endMonth);
		} elseif (in_array(self::monthLabel($todayMonth - 1), $options, true)) {
			$default = self::monthLabel($todayMonth - 1);
		} else {
			$default = self::CURRENT;
		}

		$value = $asOf ?? $default;
		if ($value === self::CURRENT) {
			return [
				'value' => $value,
				'until' => $end,
				'elapsed' => max(0, min($total, $todayMonth - $startMonth + 1)),
				'total' => $total,
				'options' => $options,
				'default' => $default,
			];
		}
		if (!in_array($value, $options, true)) {
			throw new \InvalidArgumentException('Ungültiger Stand: ' . $value);
		}

		$monthEnd = (new \DateTimeImmutable($value . '-01'))->modify('last day of this month')->format('Y-m-d');
		return [
			'value' => $value,
			'until' => min($monthEnd, $end),
			'elapsed' => self::monthIndex($value . '-01') - $startMonth + 1,
			'total' => $total,
			'options' => $options,
			'default' => $default,
		];
	}

	/**
	 * Fortlaufende Monatsnummer (Jahr × 12 + Monat − 1) eines `YYYY-MM-DD`.
	 */
	private static function monthIndex(string $date): int {
		$d = new \DateTimeImmutable($date);
		return (int)$d->format('Y') * 12 + (int)$d->format('n') - 1;
	}

	private static function monthLabel(int $monthIndex): string {
		return sprintf('%04d-%02d', intdiv($monthIndex, 12), $monthIndex % 12 + 1);
	}
}
