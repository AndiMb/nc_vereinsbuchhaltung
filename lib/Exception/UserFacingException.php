<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Exception;

/**
 * Ein Fehler, dessen Meldung für Menschen geschrieben und bereits übersetzt
 * ist. Nur sie darf ein Controller unverändert an den Browser geben; jede
 * andere Ausnahme kann Pfade, SQL oder Bibliothekstexte enthalten und geht
 * nur ins Log (Issue #261, siehe InternalErrorResponder).
 *
 * Erbt von RuntimeException, damit bestehende `catch (\RuntimeException)`
 * weiter greifen.
 */
class UserFacingException extends \RuntimeException {
}
