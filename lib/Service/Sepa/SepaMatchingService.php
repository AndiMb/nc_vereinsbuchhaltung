<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Sepa;

use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTxSepaDetail;
use OCA\Vereinsbuchhaltung\Db\DebitItem;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebitMapper;
use OCP\IL10N;

/**
 * Matching in drei Stufen (Spec §5/§2.2, Issue #72): reine Vorschlags-
 * Berechnung, KEINE Schreibzugriffe – „alle Stufen erzeugen Vorschläge mit
 * Begründung, keine wirkt automatisch". Die eigentliche Bestätigung
 * übernimmt {@see SepaImportConfirmationService}.
 *
 * Stufen (abnehmende Sicherheit, erste erfolgreiche Stufe gewinnt):
 * 1. `end_to_end_id` exakt gegen {@see DebitItem} – eindeutig dank
 *    Unique-Index, gilt für Rückgaben UND für die Sammelbuchung eines
 *    erfolgreichen Einzugs gleichermaßen (beide tragen dieselbe EndToEndId).
 * 2. `mandate_reference` + Betrag – nahezu eindeutig, mehrere Treffer möglich
 *    (z. B. zwei Forderungen desselben Mandats mit zufällig gleichem Betrag).
 * 3. Betrag + Zahler-IBAN unter offenen Posten, **nur bei Rückgabe-Signal**
 *    (Spec §5) – die schwächste Stufe, deshalb nicht für den Normalfall
 *    „Sammelgutschrift ohne eigene EndToEndId je Posten" verwendet.
 *
 * Mehrdeutigkeit (mehrere Kandidaten einer Stufe) → alle werden
 * zurückgegeben, die Auswahl trifft der Mensch (Spec §5 „Mehrdeutigkeit →
 * alle Kandidaten zur Auswahl"). Kein Kandidat auf keiner Stufe → leere
 * Liste, das ist die Grundlage für die Aufgabe „nicht zuordenbar".
 *
 * Bereits per {@see \OCA\Vereinsbuchhaltung\Db\ReturnedDebit} zurückgebuchte
 * Einzugsposten werden bei Rückgabe-Detailzeilen aus den Kandidaten entfernt
 * (Posten-Guard, Spec §3.6/§5 „max. 1 Rücklastschrift je Einzugsposten") –
 * das verhindert nicht nur die Dublette selbst, sondern schon den
 * irreführenden Vorschlag dazu.
 */
class SepaMatchingService {

	public const STAGE_END_TO_END_ID = 1;
	public const STAGE_MANDATE_AND_AMOUNT = 2;
	public const STAGE_AMOUNT_AND_IBAN = 3;

	public function __construct(
		private DebitItemMapper $debitItems,
		private MandateMapper $mandates,
		private ReturnedDebitMapper $returnedDebits,
		private IL10N $l10n,
	) {
	}

	/**
	 * @return list<array{debitItemId:int, stage:int, reason:string}>
	 */
	public function candidatesFor(BankTxSepaDetail $detail, BankTransaction $tx): array {
		$endToEndId = $detail->getEndToEndId();
		if ($endToEndId !== null) {
			$item = $this->debitItems->findByEndToEndId($endToEndId);
			if ($item !== null && !$this->isBlockedByGuard($item, $detail)) {
				return [$this->candidate(
					$item,
					self::STAGE_END_TO_END_ID,
					$this->l10n->t('End-to-End-Id „%1$s" stimmt exakt mit Einzugsposten #%2$d überein.', [$endToEndId, (int)$item->getId()]),
				)];
			}
		}

		$mandateReference = $detail->getMandateReference();
		if ($mandateReference !== null) {
			$mandate = $this->mandates->findByReference($mandateReference);
			if ($mandate !== null) {
				$amount = abs($detail->getAmountCents());
				$candidates = [];
				foreach ($this->debitItems->findByMandate((int)$mandate->getId()) as $item) {
					if ($item->getAmountCents() !== $amount || $this->isBlockedByGuard($item, $detail)) {
						continue;
					}
					$candidates[] = $this->candidate(
						$item,
						self::STAGE_MANDATE_AND_AMOUNT,
						$this->l10n->t('Mandatsreferenz „%1$s" + Betrag %2$s € passt zu Einzugsposten #%3$d.', [
							$mandateReference,
							number_format($amount / 100, 2, ',', '.'),
							(int)$item->getId(),
						]),
					);
				}
				if ($candidates !== []) {
					return $candidates;
				}
			}
		}

		// Stufe 3 nur bei Rückgabe-Signal (Spec §5) - ohne dieses Signal wäre
		// "Betrag + IBAN" bei jeder normalen Sammelgutschrift ein Zufallstreffer.
		if ($detail->getIsReturn() && $tx->getCounterpartyIban() !== null) {
			$amount = abs($detail->getAmountCents());
			$candidates = [];
			foreach ($this->debitItems->findByAmountAndIban($amount, $tx->getCounterpartyIban()) as $item) {
				if ($this->isBlockedByGuard($item, $detail)) {
					continue;
				}
				$candidates[] = $this->candidate(
					$item,
					self::STAGE_AMOUNT_AND_IBAN,
					$this->l10n->t('Betrag %1$s € und Zahler-IBAN passen zu Einzugsposten #%2$d (schwächste Stufe, bitte prüfen).', [
						number_format($amount / 100, 2, ',', '.'),
						(int)$item->getId(),
					]),
				);
			}
			return $candidates;
		}

		return [];
	}

	/**
	 * Posten-Guard (Spec §3.6/§5): eine Rückgabe-Detailzeile darf keinen
	 * Einzugsposten vorschlagen, der bereits eine Rücklastschrift trägt -
	 * „Dubletten verpuffen still" gilt schon auf Vorschlagsebene.
	 */
	private function isBlockedByGuard(DebitItem $item, BankTxSepaDetail $detail): bool {
		if (!$detail->getIsReturn()) {
			return false;
		}
		return $this->returnedDebits->findByDebitItem((int)$item->getId()) !== null;
	}

	/** @return array{debitItemId:int, stage:int, reason:string} */
	private function candidate(DebitItem $item, int $stage, string $reason): array {
		return ['debitItemId' => (int)$item->getId(), 'stage' => $stage, 'reason' => $reason];
	}
}
