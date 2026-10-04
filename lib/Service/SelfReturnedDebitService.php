<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\DebitItem;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebit;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebitMapper;
use OCA\Vereinsbuchhaltung\Service\Sepa\ReturnReasonClassifier;
use OCP\IL10N;

/**
 * Die Rücklastschriften des eigenen Mitglieds für „Mein Beitrag" (Spec §3.4
 * Pflicht-UI „Rücklastschriften als Klartext (nie Code)", Issue #122) – rein
 * lesend.
 *
 * Eine Zeile trägt nur, was das Mitglied sehen darf: Datum, Betrag, die
 * betroffene Forderung (Bezeichnung und Zeitraum) und den wahrheitsfesten
 * Klartext der Rückgabe-Klasse
 * ({@see ReturnReasonClassifier::memberFacingReason()}, Spec §3.6/§3.11).
 * Der rohe ISO-Rückgabecode, der Freitext der Bank (`reason_text`), die
 * Bankgebühr, Kontodaten und alles, was zu einem anderen Mitglied gehört,
 * verlassen den Server hier nie – die Zeile wird aus einer Allow-Liste
 * gebaut, nicht aus `jsonSerialize()` der Entity.
 *
 * IDOR-Schutz: `member_id` kommt vom Aufrufer (SelfController, ausschließlich
 * aus dem {@see ActorContextService}), nie aus einem Request-Parameter. Der
 * Mapper filtert schon in der Abfrage darauf; zusätzlich wird jede Zeile
 * gegen die Forderung geprüft, damit ein Fehler in der Abfrage nicht gleich
 * fremde Daten ausliefert (zweite Verteidigungslinie).
 */
class SelfReturnedDebitService {

	public function __construct(
		private ReturnedDebitMapper $returnedDebits,
		private DebitItemMapper $debitItems,
		private OpenItemMapper $openItems,
		private IL10N $l10n,
	) {
	}

	/**
	 * Die Rücklastschriften des Mitglieds, neueste zuerst.
	 *
	 * @return list<array{receivedAt:string, amountCents:int, description:string|null, periodStart:string|null, periodEnd:string|null, reason:string}>
	 */
	public function findOwn(int $memberId): array {
		$returned = $this->returnedDebits->findByMember($memberId);
		if ($returned === []) {
			return [];
		}
		// Neueste zuerst; bei gleichem Eingangsdatum die zuletzt erfasste.
		usort($returned, static fn (ReturnedDebit $a, ReturnedDebit $b): int => [$b->getReceivedAt(), (int)$b->getId()] <=> [$a->getReceivedAt(), (int)$a->getId()]);

		$items = $this->debitItems->findByIds(array_map(static fn (ReturnedDebit $r): int => $r->getDebitItemId(), $returned));
		$claims = $this->openItems->findByIds(array_values(array_map(static fn (DebitItem $i): int => $i->getOpenItemId(), $items)));

		$rows = [];
		foreach ($returned as $row) {
			$item = $items[$row->getDebitItemId()] ?? null;
			$claim = $item === null ? null : ($claims[$item->getOpenItemId()] ?? null);
			if ($item === null || $claim === null || $claim->getMemberId() !== $memberId) {
				continue;
			}
			$rows[] = [
				'receivedAt' => substr($row->getReceivedAt(), 0, 10),
				'amountCents' => $item->getAmountCents(),
				'description' => $claim->getDescription(),
				'periodStart' => $claim->getPeriodStart(),
				'periodEnd' => $claim->getPeriodEnd(),
				'reason' => ReturnReasonClassifier::memberFacingReason(ReturnReasonClassifier::classify($row->getReasonCode()), $this->l10n),
			];
		}
		return $rows;
	}
}
