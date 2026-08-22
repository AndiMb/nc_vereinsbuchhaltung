<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

/**
 * Reiner Versionsvergleich für den "Was ist neu"-Splash-Screen.
 *
 * Die kuratierten Inhalte selbst liegen bewusst nur im Frontend
 * (src/data/whatsNew.js), rollenabhängig und in normaler Sprache formuliert -
 * das Backend kennt hier nur Versionsnummern, keine Texte.
 */
class WhatsNewService {

	/**
	 * Vergleicht zwei "x.y.z"-Versionsnummern elementweise als Zahlen-Tupel,
	 * nicht als String - sonst wäre "0.9.0" > "0.10.0". Fehlende Segmente
	 * zählen als 0.
	 *
	 * @return int negativ wenn $a < $b, 0 bei Gleichheit, positiv wenn $a > $b
	 */
	public static function versionCompare(string $a, string $b): int {
		$pa = self::segments($a);
		$pb = self::segments($b);
		for ($i = 0; $i < max(count($pa), count($pb)); $i++) {
			$diff = ($pa[$i] ?? 0) <=> ($pb[$i] ?? 0);
			if ($diff !== 0) {
				return $diff;
			}
		}
		return 0;
	}

	public static function isNewer(string $candidate, string $lastSeen): bool {
		return self::versionCompare($candidate, $lastSeen) > 0;
	}

	/**
	 * @return list<int>
	 */
	private static function segments(string $version): array {
		if (preg_match('/^\d+(\.\d+)*$/', $version) !== 1) {
			return [0];
		}
		return array_map('intval', explode('.', $version));
	}
}
