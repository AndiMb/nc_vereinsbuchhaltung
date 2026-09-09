<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Exception;

/**
 * Schreibzugriff auf ein abgeschlossenes (festgeschriebenes) Geschäftsjahr.
 * Wird zentral in der PermissionMiddleware in HTTP 423 (Locked) übersetzt.
 *
 * Hieß bis 0.32.0 YearClosedException; seit ein Geschäftsjahr nicht mehr
 * zwingend ein Kalenderjahr ist (Issue #8), heißt es nach dem, was es sperrt.
 */
class PeriodClosedException extends \RuntimeException {
}
