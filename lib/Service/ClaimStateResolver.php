<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\OpenItem;

/**
 * Leitet den Anzeigezustand einer Forderung ab (Spec §2.2 „Forderung
 * (Claim)"): „Zustand ist vollständig abgeleitet, kein Statusfeld."
 *
 * Die vollständige Ableitungstabelle der Spec kennt fünf Zustände (offen, im
 * Einzug, eingezogen, zurückgegeben → wieder offen, storniert) – die drei
 * einzugsabhängigen hängen an Einzugsposten/Lastschriftlauf, die es laut
 * Issue #68 erst ab Ticket #70 gibt. Diese Klasse deckt bewusst nur den schon
 * jetzt entscheidbaren Teil ab (offen / storniert / erledigt) und lässt für
 * den Rest einen sauberen Erweiterungspunkt:
 *
 * - **storniert**: `cancelledAt` gesetzt.
 * - **erledigt**: Erledigungsvermerk gesetzt (`settledAt`), ob per `paid` oder
 *   `waived` steht separat in `status` (siehe {@see OpenItem::STATUSES}).
 * - **offen**: alles andere – das schließt in dieser Ableitung sowohl "noch
 *   nicht eingezogen" als auch "im Einzug"/"eingezogen"/"zurückgegeben" ein,
 *   solange es keinen Einzugsposten gibt, der das weiter auflösen könnte.
 *
 * Erweiterungspunkt für Ticket #70: sobald `DebitItem`/`DebitBatch`/
 * `ReturnedDebit` existieren, verfeinert sich der `offen`-Zweig anhand eines
 * Joins über `assignmentId`/die Forderung in „im Einzug"/„eingezogen"/
 * „zurückgegeben" – die beiden anderen Zweige (storniert/erledigt) bleiben
 * unverändert, weil sie mit dem Einzug nichts zu tun haben.
 */
final class ClaimStateResolver {
	public const STATE_OPEN = 'offen';
	public const STATE_CANCELLED = 'storniert';
	public const STATE_SETTLED = 'erledigt';

	public static function resolve(string $status, ?string $cancelledAt, ?string $settledAt): string {
		if ($cancelledAt !== null || $status === 'cancelled') {
			return self::STATE_CANCELLED;
		}
		if ($settledAt !== null || in_array($status, ['paid', 'waived'], true)) {
			return self::STATE_SETTLED;
		}
		return self::STATE_OPEN;
	}

	public static function resolveForItem(OpenItem $item): string {
		return self::resolve($item->getStatus(), $item->getCancelledAt(), $item->getSettledAt());
	}
}
