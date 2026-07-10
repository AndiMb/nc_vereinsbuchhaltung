<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Exception;

/**
 * Wird geworfen, wenn ein Datensatz seit dem Laden durch den Client von
 * jemand anderem geändert wurde (optimistisches Locking).
 */
class ConflictException extends \RuntimeException {
}
