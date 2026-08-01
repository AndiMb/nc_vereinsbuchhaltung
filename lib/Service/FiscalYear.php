<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

/**
 * Das Geschäftsjahr als Datumsbereich.
 *
 * Geschäftsjahr = Kalenderjahr; ein abweichendes Geschäftsjahr kennt die App
 * nicht. Genau deshalb lohnt die eigene Klasse: die Umrechnung „Jahr → ISO-
 * Datumsgrenzen" stand vorher als sprintf('%04d-01-01', $year) an einem Dutzend
 * Stellen ausgeschrieben. Käme je ein abweichendes Geschäftsjahr dazu, wäre das
 * hier die einzige Stelle, an der es sich niederschlägt.
 *
 * Die Grenzen sind ISO-Strings (YYYY-MM-DD), weil das Buchungsdatum so in der
 * Datenbank steht: dort ist der lexikografische Vergleich mit dem
 * chronologischen identisch (siehe JournalLineMapper::sumByAccount()).
 */
final class FiscalYear {

	/** Erster Tag des Geschäftsjahres. */
	public static function start(int $year): string {
		return sprintf('%04d-01-01', $year);
	}

	/** Letzter Tag des Geschäftsjahres. */
	public static function end(int $year): string {
		return sprintf('%04d-12-31', $year);
	}

	/**
	 * Datumsgrenzen eines Geschäftsjahres – oder [null, null] für „alle Jahre".
	 *
	 * Die Auswertungen behandeln „kein Jahr gewählt" und „Jahr 0" gleich: beides
	 * heißt, dass nicht eingegrenzt wird. Deshalb nimmt die Methode ?int und
	 * nicht int – die Fallunterscheidung gehört hierher und nicht in jeden
	 * einzelnen Aufrufer.
	 *
	 * @return array{0: ?string, 1: ?string} [von, bis], beide inklusive
	 */
	public static function range(?int $year): array {
		if (!self::isSelected($year)) {
			return [null, null];
		}
		return [self::start((int)$year), self::end((int)$year)];
	}

	/** Ist überhaupt ein Geschäftsjahr gewählt (nicht „alle Jahre")? */
	public static function isSelected(?int $year): bool {
		return $year !== null && $year > 0;
	}

	/** Das gewählte Geschäftsjahr, oder das laufende, wenn keines gewählt ist. */
	public static function orCurrent(?int $year): int {
		return self::isSelected($year) ? (int)$year : (int)date('Y');
	}
}
