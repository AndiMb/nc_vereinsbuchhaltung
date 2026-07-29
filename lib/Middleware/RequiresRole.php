<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Middleware;

use Attribute;

/**
 * Legt ausdrücklich fest, welche Rolle eine Controller-Methode verlangt.
 *
 * Ohne dieses Attribut leitet die {@see PermissionMiddleware} das nötige Recht
 * aus dem HTTP-Verb ab (GET/HEAD = lesen, sonst schreiben). Für die allermeisten
 * Endpunkte stimmt das, es hängt aber daran, dass nie jemand einen ändernden
 * GET-Endpunkt ergänzt – und ein solcher Fehler fiele niemandem auf, weil
 * nichts fehlschlägt: er wäre einfach für Revisoren mit aufrufbar.
 *
 * Wo die Anforderung vom Verb abweicht oder wo eine falsche Einstufung
 * besonders teuer wäre (alles löschen, Rechte vergeben, Jahresabschluss),
 * steht sie deshalb sichtbar an der Methode. Die Middleware zieht dann diese
 * Angabe heran statt der Heuristik.
 *
 * Der Wert ist eine Rollenkonstante aus
 * {@see \OCA\Vereinsbuchhaltung\Service\PermissionService}.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class RequiresRole {

	public function __construct(
		public readonly string $role,
	) {
	}
}
