<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Exception;

/**
 * Der Einmal-Link (Issue #67) war einmal gültig, seine Frist
 * ({@see \OCA\Vereinsbuchhaltung\Db\MandateActivationToken::VALIDITY_DAYS})
 * ist aber abgelaufen. Eigene Ausnahme statt {@see InvalidActivationTokenException}:
 * die Zustimmungsseite soll dem Mitglied "der Link ist abgelaufen, bitte
 * einen neuen anfordern" zeigen können statt eines nichtssagenden Fehlers.
 */
class ExpiredActivationTokenException extends \RuntimeException {
}
