<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Exception;

/**
 * Wird geworfen, wenn dem aktuellen Nutzer das nötige Recht fehlt.
 */
class ForbiddenException extends \RuntimeException {
}
