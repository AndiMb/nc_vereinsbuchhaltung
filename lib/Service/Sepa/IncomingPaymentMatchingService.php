<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Sepa;

use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Service\ClaimStateResolver;
use OCP\IL10N;

/**
 * Zuordnungs-Vorschlag für Zahlungseingänge (Spec §2.2 „Zuordnungs-Vorschlag
 * (Settlement Proposal)"/§3.6, Issue #72): „importierte Gutschrift passt auf
 * offene Forderung" – Vorschlag statt Auto-Erledigen.
 *
 * Bewusst UNABHÄNGIG von `vbh_bank_tx_sepa_details`/{@see SepaMatchingService}:
 * ein Überweiser hat kein SEPA-Mandat und keinen Einzugsposten, sein
 * Zahlungseingang trägt deshalb meist gar keine strukturierten SEPA-Felder
 * (siehe {@see \OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportExtractionService}).
 * Der einzig verlässliche Anknüpfungspunkt ist der Betrag, ergänzt um eine
 * lose Namensübereinstimmung als Begründungs-Bonus – nie als hartes
 * Ausschlusskriterium, weil Verwendungszweck/Zahlername je nach Bank und
 * Mitglied stark variieren.
 *
 * Reine Vorschlags-Berechnung ohne Schreibzugriff, wie {@see SepaMatchingService}.
 * Die Bestätigung eines Vorschlags läuft über den bestehenden, generischen
 * Zuordnungspfad (`BookingService::assign()`) plus Erledigungsvermerk –
 * siehe {@see SepaImportConfirmationService::confirmIncomingPayment()}.
 */
class IncomingPaymentMatchingService {

	public function __construct(
		private OpenItemMapper $openItems,
		private IL10N $l10n,
	) {
	}

	/**
	 * @return list<array{openItemId:int, memberId:?int, reason:string}>
	 */
	public function suggestFor(BankTransaction $tx): array {
		// Nur Geldeingänge kommen als Zahlung auf eine Forderung in Frage.
		if ($tx->getAmountCents() <= 0) {
			return [];
		}
		$amount = $tx->getAmountCents();
		$haystack = mb_strtolower(trim(($tx->getCounterparty() ?? '') . ' ' . ($tx->getPurpose() ?? '')));

		$suggestions = [];
		foreach ($this->openItems->findClaims() as $item) {
			if ($item->getAmountCents() !== $amount) {
				continue;
			}
			if (ClaimStateResolver::resolveForItem($item) !== ClaimStateResolver::STATE_OPEN) {
				continue;
			}
			$suggestions[] = [
				'openItemId' => (int)$item->getId(),
				'memberId' => $item->getMemberId(),
				'reason' => $this->reason($item, $amount, $haystack),
			];
		}
		return $suggestions;
	}

	private function reason(OpenItem $item, int $amount, string $haystack): string {
		$amountText = number_format($amount / 100, 2, ',', '.');
		$debtor = trim($item->getDebtor());
		if ($debtor !== '' && $haystack !== '' && str_contains($haystack, mb_strtolower($debtor))) {
			return $this->l10n->t('Betrag %1$s € passt zur offenen Forderung von %2$s, dessen Name auch im Zahlungstext steht.', [$amountText, $debtor]);
		}
		return $this->l10n->t('Betrag %1$s € passt zur offenen Forderung von %2$s.', [$amountText, $debtor]);
	}
}
