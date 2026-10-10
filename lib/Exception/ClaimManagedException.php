<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Exception;

/**
 * Ein generischer Offene-Posten-Weg (Bezahlt, Stornieren, Wieder öffnen,
 * Löschen) ist auf eine Forderung des Beitragsmoduls getroffen (Issue #121).
 *
 * Forderungen liegen in derselben Tabelle wie die freien Posten, ihre Regeln
 * aber stehen im {@see \OCA\Vereinsbuchhaltung\Service\ClaimService}
 * (Erledigungsvermerk mit Urheber, Storno nur mit Begründung und nur vor der
 * Einreichung, Erlass statt Löschen). Der generische Weg kennt davon nichts und
 * lehnt deshalb ab, statt zu raten.
 *
 * Bewusst eine {@see \InvalidArgumentException}: alle Aufrufer, die deren
 * Meldung bisher als 400 weitergaben, bleiben unverändert.
 */
class ClaimManagedException extends \InvalidArgumentException {
}
