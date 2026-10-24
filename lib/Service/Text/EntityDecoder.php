<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Text;

/**
 * Löst HTML-Entitäten in Texten aus Fremdformaten auf (Issue #112).
 *
 * xbuc-Dateien aus „zero Buchhaltung" tragen Sonderzeichen teils als
 * Entität ("&#252;"), teils ohne "&" ("#252;"), teils mehrfach kodiert
 * ("&amp;#252;"). Aufgelöst wird deshalb bis zum Fixpunkt. Reihenfolge
 * wichtig: "#252;" wird erst ergänzt, wenn sich nichts mehr dekodieren lässt.
 * Sonst würde aus "&amp;#252;" zuerst "&amp;&#252;" und am Ende "&ü" – bei
 * dreifacher Kodierung ebenso auf einer Zwischenstufe ("&amp;amp;#252;").
 *
 * Genutzt vom xbuc-Import ({@see clean()}), vom Dublettenabgleich der
 * Kontoauszüge ({@see decode()}) und von der Reparatur bereits gespeicherter
 * Texte ({@see repair()}).
 */
final class EntityDecoder {

	/** Jede Zahl ohne "&" gilt als Entität – im xbuc-Format ist das so üblich. */
	private const BARE_ANY = '/(?<!&)#(\d+);/';

	/**
	 * In gespeicherten Texten nur Zahlen, die sicher eine Entität meinen:
	 * Zeichen ab 128 (Umlaute, ß, €) und 20, das Leerzeichen-Ersatzzeichen von
	 * zero Buchhaltung. „Rechnung #12;" bleibt so, wie es ist.
	 */
	private const BARE_SAFE = '/(?<!&)#(20|12[89]|1[3-9]\d|[2-9]\d\d|[1-9]\d{3,});/';

	/** Benannte, dezimale und hexadezimale Entitäten mit "&". */
	private const ENTITY = '/&(?:#\d+|#x[0-9a-f]+|[a-z][a-z0-9]*);/i';

	private const MAX_ROUNDS = 8;

	/** Entitäten bis zum Fixpunkt auflösen, ohne sonst etwas zu ändern. */
	public static function decode(string $s): string {
		return self::resolve($s, self::BARE_ANY);
	}

	/**
	 * Wie {@see decode()}, dazu Steuerzeichen entfernen und trimmen – so liest
	 * der xbuc-Import jedes Textfeld.
	 */
	public static function clean(string $s): string {
		return self::finish(self::decode($s));
	}

	/** Enthält der gespeicherte Text etwas, das {@see repair()} auflösen würde? */
	public static function needsRepair(string $s): bool {
		return preg_match(self::ENTITY, $s) === 1 || preg_match(self::BARE_SAFE, $s) === 1;
	}

	/**
	 * Einen gespeicherten Text reparieren. Wie {@see clean()}, aber mit der
	 * vorsichtigen Regel für Zahlen ohne "&" ({@see BARE_SAFE}).
	 */
	public static function repair(string $s): string {
		return self::finish(self::resolve($s, self::BARE_SAFE));
	}

	private static function resolve(string $s, string $bare): string {
		for ($i = 0; $i < self::MAX_ROUNDS && (str_contains($s, '&') || str_contains($s, '#')); $i++) {
			// Referenzen auf Steuerzeichen (&#0; bis &#31;, z.B. "#20;" als
			// Leerzeichen-Ersatz von zero Buchhaltung) werden zu Leerzeichen,
			// vor dem Dekodieren: html_entity_decode lässt sie teils als
			// Literal stehen, teils macht es Tab oder Seitenvorschub daraus.
			$next = preg_replace('/&#(?:\d|[12]\d|3[01]);/', ' ', $s) ?? $s;
			$decoded = html_entity_decode($next, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$next = $decoded !== $next ? $decoded : (preg_replace($bare, '&#$1;', $next) ?? $next);
			if ($next === $s) {
				break;
			}
			$s = $next;
		}
		return $s;
	}

	private static function finish(string $s): string {
		// Steuerzeichen (außer Tab/Zeilenumbruch) entfernen
		$s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? $s;
		return trim($s);
	}
}
