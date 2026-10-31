<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Middleware;

use Attribute;

/**
 * Die Methode verändert nichts, obwohl sie per POST aufgerufen wird (der
 * Formularstand geht als Body mit): die {@see RevisionMiddleware} erhöht den
 * Änderungsstand nicht, sonst meldeten alle anderen Browser bei jedem Aufruf
 * eine Änderung aus der Ferne.
 *
 * Mit `$unlessNonEmpty` bleibt der Aufruf ein Schreibzugriff, der Stand
 * steigt aber nur, wenn das genannte Feld der DataResponse nicht leer ist
 * (z. B. `linked` bei der Sammelbestätigung ohne einziges verknüpftes Paar).
 */
#[Attribute(Attribute::TARGET_METHOD)]
class NoRevisionBump {

	public function __construct(
		public readonly ?string $unlessNonEmpty = null,
	) {
	}
}
