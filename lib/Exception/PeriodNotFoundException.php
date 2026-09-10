<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Exception;

/**
 * Es wurde ein Geschäftsjahr angefragt, das es nicht (mehr) gibt.
 *
 * Der praktische Fall: ein zweiter Nutzer hat die Geschäftsjahr-Regel
 * umgestellt, während im Browser noch die alte Zeitraum-Auswahl steht. Die
 * PermissionMiddleware übersetzt das in HTTP 404 samt Bitte, neu zu laden –
 * das ist ehrlicher, als stillschweigend die Zahlen aller Zeiträume zu zeigen.
 */
class PeriodNotFoundException extends \RuntimeException {
}
