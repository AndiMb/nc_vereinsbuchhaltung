<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

/**
 * Mandatsreferenz `<Präfix>-<lfd. Nr.>` (Spec §2.2), frei überschreibbar –
 * anders als die zufällige Hex-Referenz des alten Mandatssystems
 * ({@see \OCA\Vereinsbuchhaltung\Service\Sepa\SepaReference}). Reine
 * Rechenklasse: nimmt die bereits vorhandenen Referenzen desselben Präfixes
 * entgegen (siehe {@see \OCA\Vereinsbuchhaltung\Db\MandateMapper::findReferencesWithPrefix()})
 * und ermittelt die nächste freie laufende Nummer daraus – ohne selbst eine
 * Datenbankabfrage zu machen, damit sich die eigentliche Logik (Parsen,
 * Maximum, Kollisionsfreiheit) per PHPUnit prüfen lässt.
 */
class MandateReferenceGenerator {

	public const DEFAULT_PREFIX = 'M';

	/**
	 * @param list<string> $existingReferences bereits vergebene Referenzen mit demselben Präfix
	 */
	public function next(string $prefix, array $existingReferences): string {
		$prefix = trim($prefix) !== '' ? trim($prefix) : self::DEFAULT_PREFIX;
		$max = 0;
		foreach ($existingReferences as $reference) {
			$suffix = $this->numericSuffix($prefix, $reference);
			if ($suffix !== null && $suffix > $max) {
				$max = $suffix;
			}
		}
		return $prefix . '-' . ($max + 1);
	}

	private function numericSuffix(string $prefix, string $reference): ?int {
		$expectedPrefix = $prefix . '-';
		if (!str_starts_with($reference, $expectedPrefix)) {
			return null;
		}
		$tail = substr($reference, strlen($expectedPrefix));
		return preg_match('/^\d+$/', $tail) === 1 ? (int)$tail : null;
	}
}
