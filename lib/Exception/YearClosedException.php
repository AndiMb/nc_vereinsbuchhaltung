<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Exception;

/**
 * Schreibzugriff auf ein abgeschlossenes (festgeschriebenes) Geschäftsjahr.
 * Wird zentral in der PermissionMiddleware in HTTP 423 (Locked) übersetzt.
 */
class YearClosedException extends \RuntimeException {
}
