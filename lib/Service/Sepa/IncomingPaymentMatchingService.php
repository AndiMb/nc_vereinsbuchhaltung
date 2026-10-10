<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Sepa;

use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\IncomingPaymentRejectionMapper;
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
 * Sie kennt nur ein Gedächtnis: abgelehnte Paare (Umsatz, Forderung) werden
 * nicht erneut vorgeschlagen (Issue #105, Migration 000153).
 * Die Bestätigung eines Vorschlags läuft über den bestehenden, generischen
 * Zuordnungspfad (`BookingService::assign()`) plus Erledigungsvermerk –
 * siehe {@see SepaImportConfirmationService::confirmIncomingPayment()}.
 */
class IncomingPaymentMatchingService {

	public function __construct(
		private OpenItemMapper $openItems,
		private IncomingPaymentRejectionMapper $rejections,
		private IL10N $l10n,
	) {
	}

	/**
	 * @return list<array{openItemId:int, memberId:?int, reason:string}>
	 */
	public function suggestFor(BankTransaction $tx): array {
		return $this->suggestForMany([$tx])[(int)$tx->getId()] ?? [];
	}

	/**
	 * Die Vorschläge für mehrere Umsätze auf einmal (Bankabgleich, Issue #105):
	 * die Forderungen werden nur EINMAL geladen und nach Betrag einsortiert,
	 * statt je Umsatz die ganze Liste zu lesen – bei hunderten unzugeordneten
	 * Umsätzen und tausenden Forderungen der Unterschied zwischen einer und
	 * hunderten Abfragen.
	 *
	 * Ein Paar (Umsatz, Forderung), das jemand abgelehnt hat
	 * ({@see IncomingPaymentRejectionMapper}), wird nie wieder vorgeschlagen.
	 *
	 * @param list<BankTransaction> $txs
	 * @return array<int,list<array{openItemId:int, memberId:?int, reason:string}>> Umsatz-ID => Vorschläge; nur Umsätze mit mindestens einem
	 */
	public function suggestForMany(array $txs): array {
		// Nur Geldeingänge kommen als Zahlung auf eine Forderung in Frage.
		$incoming = array_values(array_filter($txs, static fn (BankTransaction $tx): bool => $tx->getAmountCents() > 0));
		if ($incoming === []) {
			return [];
		}

		/** @var array<int,list<OpenItem>> $byAmount noch offene Forderungen je Betrag, in der Reihenfolge von findClaims() */
		$byAmount = [];
		foreach ($this->openItems->findClaims() as $item) {
			if (ClaimStateResolver::resolveForItem($item) === ClaimStateResolver::STATE_OPEN) {
				$byAmount[$item->getAmountCents()][] = $item;
			}
		}
		$rejected = $this->rejections->findAllKeys();

		$result = [];
		foreach ($incoming as $tx) {
			$txId = (int)$tx->getId();
			$amount = $tx->getAmountCents();
			$haystack = mb_strtolower(trim(($tx->getCounterparty() ?? '') . ' ' . ($tx->getPurpose() ?? '')));
			$referenced = self::referencedItemIds($tx->getPurpose());
			foreach ($byAmount[$amount] ?? [] as $item) {
				if (isset($rejected[IncomingPaymentRejectionMapper::key($txId, (int)$item->getId())])) {
					continue;
				}
				$byReference = in_array((int)$item->getId(), $referenced, true);
				$suggestion = [
					'openItemId' => (int)$item->getId(),
					'memberId' => $item->getMemberId(),
					'reason' => $byReference
						? $this->l10n->t('Betrag %1$s € passt zur offenen Forderung von %2$s, deren Nummer F-%3$d im Zahlungstext steht.', [number_format($amount / 100, 2, ',', '.'), trim($item->getDebtor()), (int)$item->getId()])
						: $this->reason($item, $amount, $haystack),
				];
				// Die Forderung, deren Nummer im Zahlungstext steht, kommt zuerst.
				if ($byReference) {
					$result[$txId] = [$suggestion, ...($result[$txId] ?? [])];
				} else {
					$result[$txId][] = $suggestion;
				}
			}
		}
		return $result;
	}

	/**
	 * Forderungsnummern „F-<ID>" aus dem Zahlungstext – so steht eine Forderung im Verwendungszweck der
	 * Zahlungsaufforderung (siehe DunningLadderService).
	 *
	 * @return list<int>
	 */
	private static function referencedItemIds(?string $purpose): array {
		if ($purpose === null || $purpose === '') {
			return [];
		}
		preg_match_all('/\bF-(\d{1,9})\b/i', $purpose, $matches);
		return array_map('intval', $matches[1]);
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
