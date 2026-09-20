<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Exception;

/**
 * Der Einmal-Link (Issue #67) wurde bereits verwendet - das Mandat ist
 * bereits aktiv. Tritt nur beim Versuch auf, ihn ein ZWEITES Mal zu
 * verbrauchen (POST .../accept); die reine Anzeige (GET) wirft dafür nicht,
 * siehe {@see \OCA\Vereinsbuchhaltung\Service\MandateActivationService::view()}
 * ("bereits bestätigt" ist ein normaler, kein fehlerhafter Anzeigezustand -
 * ein Mitglied, das den Link zweimal öffnet, soll keine Fehlerseite sehen).
 */
class ConsumedActivationTokenException extends \RuntimeException {
}
