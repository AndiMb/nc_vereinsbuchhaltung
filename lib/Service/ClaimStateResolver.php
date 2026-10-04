<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\DebitBatch;
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
 *
 * Diese Verfeinerung steht in {@see resolveForDebitItem()} (Issue #102, Lauf-
 * Detail im Einzug-Unterreiter): sie braucht den Einzugsposten samt Lauf und
 * einer eventuellen Rücklastschrift – `resolve()`/`resolveForItem()` kennen
 * die nicht und bleiben bewusst unverändert, weil mehrere Dienste ihr
 * `STATE_OPEN` als „noch offen im Sinne des Erledigens" lesen
 * ({@see ClaimService::assertOpen()}, die Aufgaben-Dienste).
 */
final class ClaimStateResolver {
	public const STATE_OPEN = 'offen';
	public const STATE_CANCELLED = 'storniert';
	public const STATE_SETTLED = 'erledigt';

	/** Nur aus {@see resolveForDebitItem()} (Spec §2.2): Posten in `freigegeben`/`eingereicht`-Lauf, Termin noch nicht erreicht. */
	public const STATE_IN_DEBIT = 'im_einzug';
	/** Posten in `eingereicht`-Lauf, Termin erreicht, keine Rücklastschrift (Spec §2.2). */
	public const STATE_COLLECTED = 'eingezogen';
	/** Rücklastschrift am Posten – die Forderung ist damit wieder offen (Spec §2.2 „zurückgegeben → wieder offen"). */
	public const STATE_RETURNED = 'zurueckgegeben';

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

	/**
	 * Zustand einer Forderung aus Sicht ihres Einzugspostens (Spec §2.2,
	 * Ableitungstabelle): wird im Lauf-Detail für jede Zeile gebraucht.
	 *
	 * Rangfolge: storniert und erledigt (Vermerk) gehen vor allem anderen – sie
	 * gelten unabhängig vom Lauf. Danach entscheidet der Einzug: eine
	 * Rücklastschrift macht aus dem Posten „zurückgegeben", ein verworfener
	 * Lauf gibt die Forderung wieder frei („offen"), ein freigegebener Lauf
	 * heißt „im Einzug", und im eingereichten Lauf entscheidet der Termin: bis
	 * dahin „im Einzug", danach „eingezogen". Der Termin ist der des Laufs
	 * (der bis zur Einreichung nach hinten verschoben sein kann), nicht der
	 * Fälligkeitstag der Forderung.
	 *
	 * Eine zwischenzeitlich gelöschte Forderung (`$claim === null`) hat weder
	 * Storno noch Vermerk – der Zustand folgt dann allein dem Lauf.
	 *
	 * @param string $today Stichtag (JJJJ-MM-TT)
	 */
	public static function resolveForDebitItem(?OpenItem $claim, DebitBatch $batch, bool $returned, string $today): string {
		if ($claim !== null) {
			$own = self::resolveForItem($claim);
			if ($own === self::STATE_CANCELLED || $own === self::STATE_SETTLED) {
				return $own;
			}
		}
		if ($returned) {
			return self::STATE_RETURNED;
		}
		return match ($batch->getStatus()) {
			DebitBatch::STATUS_DISCARDED => self::STATE_OPEN,
			DebitBatch::STATUS_RELEASED => self::STATE_IN_DEBIT,
			default => $batch->getDueDate() > $today ? self::STATE_IN_DEBIT : self::STATE_COLLECTED,
		};
	}
}
