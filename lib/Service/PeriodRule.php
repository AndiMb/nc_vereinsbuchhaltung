<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

/**
 * Die Regel, nach der Geschäftsjahre entstehen – reine Datumsarithmetik ohne
 * Zustand, ohne Datenbank, ohne Nextcloud (siehe {@see BillingPeriod} für
 * dasselbe Muster). Genau deshalb lässt sie sich in tests/unit vollständig
 * prüfen, und das muss sie auch: ein Fehler hier fällt nicht als
 * Fehlermeldung auf, sondern als Buchung, die im falschen Geschäftsjahr
 * landet – und damit als falscher Kassenbericht.
 *
 * Die Regel beschreibt ein Raster: ab Tag/Monat des Startdatums folgen
 * Perioden von jeweils `lengthMonths` Monaten Länge. Die eigentlichen
 * Perioden stehen in der Datenbank (vbh_periods); dieses Raster sagt nur,
 * wo eine neue Periode anfinge, wenn eine gebraucht wird. Wer eine Grenze
 * von Hand verschiebt, weicht bewusst vom Raster ab – die App richtet sich
 * danach und findet über {@see containing()} von selbst wieder ins Raster
 * zurück.
 *
 * Alle Daten sind ISO-Strings (JJJJ-MM-TT), weil das Buchungsdatum so in der
 * Datenbank steht: dort ist der lexikografische Vergleich mit dem
 * chronologischen identisch.
 */
final class PeriodRule {

	/**
	 * Vorgefertigte Regeln. Die Namen stehen so auch im Frontend und in der
	 * gespeicherten Regel; wer eine ergänzt, ergänzt beides.
	 *
	 * @var array<string, array{startDay:int, startMonth:int, lengthMonths:int}>
	 */
	public const PRESETS = [
		// Der Normalfall und die Vorgabe: Geschäftsjahr = Kalenderjahr.
		'calendar' => ['startDay' => 1, 'startMonth' => 1, 'lengthMonths' => 12],
		// Issue #8: der häufigste abweichende Fall im Vereinswesen.
		'oct-sep' => ['startDay' => 1, 'startMonth' => 10, 'lengthMonths' => 12],
		// Kindergärten und Schulen rechnen im Schuljahr.
		'aug-jul' => ['startDay' => 1, 'startMonth' => 8, 'lengthMonths' => 12],
		// Studentische Vereine: Winter- ab Oktober, Sommersemester ab April.
		'semester' => ['startDay' => 1, 'startMonth' => 10, 'lengthMonths' => 6],
	];

	/** Frei wählbare Regel – Tag, Monat und Länge kommen dann aus dem Request. */
	public const PRESET_CUSTOM = 'custom';

	/**
	 * Erlaubte Periodenlängen: nur Teiler von 12.
	 *
	 * Eine Länge, die 12 nicht teilt (etwa 5), liefe Jahr für Jahr weiter
	 * gegen den Kalender – nach zwölf Perioden begänne das Geschäftsjahr in
	 * einem anderen Monat als am Anfang. Für eine Vereinsbuchhaltung ist das
	 * kein sinnvoller Zustand: Beitragsjahre, Mitgliederversammlungen und
	 * Fristen hängen am Kalender. Mit einem Teiler von 12 fällt der Beginn
	 * jedes Zyklus dagegen immer auf denselben Tag im Jahr.
	 */
	public const LENGTHS = [1, 2, 3, 4, 6, 12];

	/** @var array{preset:string, startDay:int, startMonth:int, lengthMonths:int} */
	public const DEFAULT_RULE = [
		'preset' => 'calendar',
		'startDay' => 1,
		'startMonth' => 1,
		'lengthMonths' => 12,
	];

	/**
	 * Prüft und vervollständigt eine Regel.
	 *
	 * Bei einem bekannten Preset gewinnen dessen Werte – so kann eine
	 * gespeicherte Regel nicht in einen Zustand geraten, in dem der Name
	 * „oct-sep" lautet, die Zahlen aber etwas anderes sagen.
	 *
	 * @param array<string, mixed> $rule
	 * @return array{preset:string, startDay:int, startMonth:int, lengthMonths:int}
	 * @throws \InvalidArgumentException bei unbrauchbaren Werten
	 */
	public static function validate(array $rule): array {
		$preset = (string)($rule['preset'] ?? self::PRESET_CUSTOM);

		if (isset(self::PRESETS[$preset])) {
			return array_merge(['preset' => $preset], self::PRESETS[$preset]);
		}
		if ($preset !== self::PRESET_CUSTOM) {
			throw new \InvalidArgumentException('Unbekanntes Geschäftsjahr-Preset: ' . $preset);
		}

		$startDay = (int)($rule['startDay'] ?? 1);
		$startMonth = (int)($rule['startMonth'] ?? 1);
		$lengthMonths = (int)($rule['lengthMonths'] ?? 12);

		if ($startDay < 1 || $startDay > 31) {
			throw new \InvalidArgumentException('Starttag muss zwischen 1 und 31 liegen.');
		}
		if ($startMonth < 1 || $startMonth > 12) {
			throw new \InvalidArgumentException('Startmonat muss zwischen 1 und 12 liegen.');
		}
		if (!in_array($lengthMonths, self::LENGTHS, true)) {
			throw new \InvalidArgumentException('Periodenlänge muss ein Teiler von 12 sein (1, 2, 3, 4, 6 oder 12).');
		}

		// Eine „eigene" Regel, die zufällig einem Preset entspricht, wird als
		// dieses Preset gespeichert: sonst zeigte die Einstellungsseite
		// „Eigene Regel" an, obwohl der Nutzer genau das Kalenderjahr gewählt hat.
		foreach (self::PRESETS as $name => $values) {
			if ($values === ['startDay' => $startDay, 'startMonth' => $startMonth, 'lengthMonths' => $lengthMonths]) {
				return array_merge(['preset' => $name], $values);
			}
		}

		return [
			'preset' => self::PRESET_CUSTOM,
			'startDay' => $startDay,
			'startMonth' => $startMonth,
			'lengthMonths' => $lengthMonths,
		];
	}

	/**
	 * Die Rasterperiode, in die $date fällt.
	 *
	 * @param array{startDay:int, startMonth:int, lengthMonths:int, preset?:string} $rule
	 * @return array{0:string, 1:string} [von, bis], beide inklusive
	 * @throws \InvalidArgumentException bei unmöglichem Datum
	 */
	public static function containing(array $rule, string $date): array {
		self::assertDate($date);
		$starts = self::startsAround($rule, (int)substr($date, 0, 4));

		$found = null;
		foreach ($starts as $i => $start) {
			if ($start <= $date) {
				$found = $i;
				continue;
			}
			break;
		}
		if ($found === null || !isset($starts[$found + 1])) {
			// Kann nur eintreten, wenn startsAround() zu wenige Jahre erzeugt –
			// dann lieber laut scheitern als still ein falsches Jahr liefern.
			throw new \LogicException('Kein Geschäftsjahr-Raster für ' . $date . ' gefunden.');
		}

		return [$starts[$found], self::previousDay($starts[$found + 1])];
	}

	/**
	 * Vorschlag für die Bezeichnung der Periode, die bei $start beginnt.
	 *
	 * Zwölf Monate ab dem 1. Januar heißen schlicht „2025"; jedes andere
	 * Zwölfmonatsjahr „2025/26", weil die bloße Zahl sonst nicht verriete,
	 * welches der beiden Kalenderjahre gemeint ist. Kürzere Perioden hängen
	 * ihre laufende Nummer im Zyklus an: „2025/26-1", „2025/26-2".
	 *
	 * Der Vorschlag muss nicht eindeutig sein – der PeriodService hängt bei
	 * Bedarf eine Unterscheidung an. Die Bezeichnung ist ohnehin frei
	 * änderbar; dies ist nur der Startwert.
	 *
	 * @param array{startDay:int, startMonth:int, lengthMonths:int, preset?:string} $rule
	 */
	public static function proposeLabel(array $rule, string $start): string {
		[$anchorYear, $index] = self::gridIndex($rule, $start);

		$base = ($rule['startMonth'] === 1 && $rule['startDay'] === 1)
			? (string)$anchorYear
			: sprintf('%d/%02d', $anchorYear, ($anchorYear + 1) % 100);

		return $rule['lengthMonths'] === 12 ? $base : $base . '-' . ($index + 1);
	}

	/**
	 * Zyklusjahr und laufende Nummer der Rasterperiode, die $date enthält.
	 *
	 * @param array{startDay:int, startMonth:int, lengthMonths:int, preset?:string} $rule
	 * @return array{0:int, 1:int} [Jahr des Zyklusbeginns, Nummer ab 0]
	 */
	public static function gridIndex(array $rule, string $date): array {
		self::assertDate($date);
		$perCycle = intdiv(12, $rule['lengthMonths']);
		$year = (int)substr($date, 0, 4);

		for ($y = $year + 1; $y >= $year - 1; $y--) {
			for ($k = $perCycle - 1; $k >= 0; $k--) {
				if (self::startAt($rule, $y, $k) <= $date) {
					return [$y, $k];
				}
			}
		}
		throw new \LogicException('Kein Geschäftsjahr-Raster für ' . $date . ' gefunden.');
	}

	/** Der Tag vor $date. */
	public static function previousDay(string $date): string {
		self::assertDate($date);
		return (new \DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
	}

	/** Der Tag nach $date. */
	public static function nextDay(string $date): string {
		self::assertDate($date);
		return (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
	}

	/**
	 * Alle Rasterbeginne rund um ein Kalenderjahr, aufsteigend sortiert.
	 *
	 * Vier Jahrgänge, weil eine Periode bis zu zwölf Monate lang ist: die
	 * Periode um ein Datum im Jahr J kann im Jahr J−1 beginnen, und ihr
	 * Nachfolger (den {@see containing()} für die Obergrenze braucht) kann im
	 * Jahr J+1 liegen.
	 *
	 * @param array{startDay:int, startMonth:int, lengthMonths:int, preset?:string} $rule
	 * @return list<string>
	 */
	private static function startsAround(array $rule, int $year): array {
		$perCycle = intdiv(12, $rule['lengthMonths']);
		$starts = [];
		for ($y = $year - 1; $y <= $year + 2; $y++) {
			for ($k = 0; $k < $perCycle; $k++) {
				$starts[] = self::startAt($rule, $y, $k);
			}
		}
		$starts = array_values(array_unique($starts));
		sort($starts);
		return $starts;
	}

	/**
	 * Beginn der $k-ten Periode des Zyklus, der im Jahr $anchorYear anfängt.
	 *
	 * Der Starttag wird auf die Länge des Zielmonats gekürzt: wer zum 31.
	 * beginnt, beginnt im Februar am 28. bzw. 29. Gerechnet wird dabei immer
	 * mit dem ursprünglichen Starttag aus der Regel, nicht mit dem gekürzten –
	 * sonst zöge ein kurzer Monat den Stichtag dauerhaft nach vorn.
	 *
	 * @param array{startDay:int, startMonth:int, lengthMonths:int, preset?:string} $rule
	 */
	private static function startAt(array $rule, int $anchorYear, int $k): string {
		$total = ($rule['startMonth'] - 1) + $k * $rule['lengthMonths'];
		$year = $anchorYear + (int)floor($total / 12);
		$month = (($total % 12) + 12) % 12 + 1;

		$daysInMonth = (int)(new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
		return sprintf('%04d-%02d-%02d', $year, $month, min($rule['startDay'], $daysInMonth));
	}

	/** @throws \InvalidArgumentException wenn es den Tag nicht gibt */
	private static function assertDate(string $date): void {
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			throw new \InvalidArgumentException('Kein gültiges Datum: ' . $date);
		}
	}
}
