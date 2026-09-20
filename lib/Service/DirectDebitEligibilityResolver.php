<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;

/**
 * Ob eine Forderung überhaupt für den SEPA-Lastschriftweg in Frage kommt
 * (Spec §3.5 „payment_method an der Zuweisung … verhindert, dass reine
 * Überweiser einen Dauer-Störfall erzeugen. Überweiser bekommen Forderungen,
 * aber nie Einzugsposten, Vorabinfo oder Störfall."). Eine einzige,
 * zentrale Stelle für diese Regel – gebraucht von
 * {@see ContributionPreNotificationService} (wer bekommt eine Vorabinfo),
 * {@see ContributionCycleTaskService} (Vorwarnfenster-Aufgabe zählt nur
 * einzugsfähige Forderungen) und der Lauf-Vorschau
 * {@see DebitRunQueryService} – dieselbe Bedingung an drei Stellen von Hand
 * nachzubauen hätte sie irgendwann auseinanderlaufen lassen.
 *
 * Zwei Fälle:
 * - Turnus-Forderung (assignment_id gesetzt): nur bei
 *   `payment_method: direct_debit` überhaupt in Frage – eine
 *   Überweiser-Zuweisung liefert nie eine Vorabinfo/einen Einzugsposten.
 * - Manuelle Einzelforderung (kein assignment_id, Issue #68
 *   `ClaimService::createManual()`): hat kein eigenes `payment_method`-Feld
 *   ("schmale Tür", frei einsetzbar) – sie kommt für den Lastschriftweg in
 *   Frage, sobald das Mitglied ein einzugsfähiges Mandat hat, sonst bleibt sie
 *   ein normaler offener Posten zur manuellen Klärung (Spec §3.3: „auch ohne
 *   aktives Mandat anlegbar").
 *
 * In beiden Fällen zusätzlich: das Mitglied braucht ein **aktives** Mandat
 * (Spec §2.2 Zustandsmodell: „nur `active` einzugsfähig").
 */
final class DirectDebitEligibilityResolver {

	public function __construct(
		private AssignmentMapper $assignments,
		private MandateMapper $mandates,
	) {
	}

	public function isEligible(OpenItem $item): bool {
		if ($item->getAssignmentId() !== null) {
			$assignment = $this->assignments->find($item->getAssignmentId());
			if ($assignment->getPaymentMethod() !== Assignment::PAYMENT_METHOD_DIRECT_DEBIT) {
				return false;
			}
		}
		return $this->hasCollectibleMandate($item->getMemberId());
	}

	public function hasCollectibleMandate(?int $memberId): bool {
		if ($memberId === null) {
			return false;
		}
		/** @var Mandate|null $mandate */
		$mandate = $this->mandates->findLiveByMember($memberId)[0] ?? null;
		return $mandate !== null && $mandate->isCollectible();
	}
}
