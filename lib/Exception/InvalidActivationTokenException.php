<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Exception;

/**
 * Der Einmal-Link (Issue #67) ist unbekannt oder das Geheimnis stimmt nicht
 * (falscher/manipulierter Token) - dieselbe Antwort wie bei einem
 * ausgedachten Link, damit sich "falscher Selector" und "richtiger Selector,
 * falscher Validator" von außen nicht unterscheiden lassen.
 */
class InvalidActivationTokenException extends \RuntimeException {
}
