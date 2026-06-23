<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\Journal;
use OCA\Vereinsbuchhaltung\Db\JournalLine;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;

/**
 * Übersetzt zugeordnete Bankbuchungen in doppelte Buchungssätze (Soll/Haben).
 *
 * Vorzeichenlogik (Betrag der Bankbuchung):
 *  - Geldeingang  (Betrag > 0): Soll Bankkonto   / Haben Gegenkonto (Ertrag)
 *  - Geldausgang  (Betrag < 0): Soll Gegenkonto  / Haben Bankkonto  (Aufwand)
 */
class BookingService {

	public function __construct(
		private JournalMapper $journalMapper,
		private JournalLineMapper $lineMapper,
		private BankTransactionMapper $txMapper,
		private AccountService $accountService,
	) {
	}

	/**
	 * Ordnet eine Bankbuchung einem Gegenkonto zu und erzeugt den Buchungssatz.
	 */
	public function assign(BankTransaction $tx, int $contraAccountId): BankTransaction {
		$userId = $tx->getUserId();
		// Gegenkonto validieren (gehört dem Nutzer)
		$contra = $this->accountService->find($contraAccountId, $userId);
		$bank = $this->accountService->getDefaultBankAccount($userId);

		// Bereits gebuchte Zuordnung zuerst zurücknehmen (Re-Assign)
		if ($tx->getJournalId() !== null) {
			$this->removeJournal($tx->getJournalId(), $userId);
		}

		$amount = $tx->getAmountCents();
		$abs = abs($amount);

		$journal = new Journal();
		$journal->setUserId($userId);
		$journal->setEntryNo($this->journalMapper->getNextEntryNo($userId));
		$journal->setDate($tx->getBookingDate());
		$journal->setDescription($this->buildDescription($tx));
		$journal->setBankTxId($tx->getId());
		$journal->setCreatedAt((new \DateTime())->format('Y-m-d H:i:s'));
		$journal = $this->journalMapper->insert($journal);

		if ($amount >= 0) {
			$this->addLine($journal->getId(), $bank->getId(), $abs, 0);
			$this->addLine($journal->getId(), $contra->getId(), 0, $abs);
		} else {
			$this->addLine($journal->getId(), $contra->getId(), $abs, 0);
			$this->addLine($journal->getId(), $bank->getId(), 0, $abs);
		}

		$tx->setContraAccountId($contraAccountId);
		$tx->setJournalId($journal->getId());
		$tx->setStatus('assigned');
		return $this->txMapper->update($tx);
	}

	/**
	 * Hebt eine Zuordnung wieder auf und löscht den Buchungssatz.
	 */
	public function unassign(BankTransaction $tx): BankTransaction {
		if ($tx->getJournalId() !== null) {
			$this->removeJournal($tx->getJournalId(), $tx->getUserId());
		}
		$tx->setContraAccountId(null);
		$tx->setJournalId(null);
		$tx->setStatus('unassigned');
		return $this->txMapper->update($tx);
	}

	private function removeJournal(int $journalId, string $userId): void {
		try {
			$journal = $this->journalMapper->find($journalId, $userId);
		} catch (\Throwable) {
			return;
		}
		$this->lineMapper->deleteByJournal($journal->getId());
		$this->journalMapper->delete($journal);
	}

	private function addLine(int $journalId, int $accountId, int $debit, int $credit): void {
		$line = new JournalLine();
		$line->setJournalId($journalId);
		$line->setAccountId($accountId);
		$line->setDebitCents($debit);
		$line->setCreditCents($credit);
		$this->lineMapper->insert($line);
	}

	private function buildDescription(BankTransaction $tx): string {
		$parts = array_filter([
			$tx->getCounterparty(),
			$tx->getPurpose(),
		]);
		$desc = implode(' – ', $parts);
		return mb_substr($desc !== '' ? $desc : 'Bankbuchung', 0, 255);
	}
}
