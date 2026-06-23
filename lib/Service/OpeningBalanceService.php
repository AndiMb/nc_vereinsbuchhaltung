<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\Journal;
use OCA\Vereinsbuchhaltung\Db\JournalLine;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;

/**
 * Pflegt die Eröffnungsbuchung eines Kontos (z.B. Anfangsbestand des Bankkontos)
 * als doppelten Buchungssatz gegen das Eigenkapital.
 *
 *  Aktivkonto, positiver Saldo:  Soll Konto / Haben Eigenkapital
 *  Aktivkonto, negativer Saldo:  Soll Eigenkapital / Haben Konto
 */
class OpeningBalanceService {

	public function __construct(
		private JournalMapper $journalMapper,
		private JournalLineMapper $lineMapper,
		private AccountService $accountService,
	) {
	}

	/**
	 * Synchronisiert die Eröffnungsbuchung mit dem aktuell am Konto
	 * gespeicherten Eröffnungssaldo. Vorhandene Eröffnungsbuchung wird neu
	 * erzeugt; bei Saldo 0 wird keine Buchung angelegt.
	 */
	public function sync(Account $account): void {
		$userId = $account->getUserId();

		// bestehende Eröffnungsbuchung dieses Kontos entfernen
		$existing = $this->journalMapper->findOpeningForAccount($userId, $account->getId());
		if ($existing !== null) {
			$this->lineMapper->deleteByJournal($existing->getId());
			$this->journalMapper->delete($existing);
		}

		$amount = $account->getOpeningBalanceCents();
		if ($amount === 0) {
			return;
		}

		$equity = $this->accountService->getOpeningEquityAccount($userId);
		if ($equity->getId() === $account->getId()) {
			// Eigenkapital kann nicht gegen sich selbst eröffnet werden
			return;
		}

		$journal = new Journal();
		$journal->setUserId($userId);
		$journal->setEntryNo($this->journalMapper->getNextEntryNo($userId));
		$journal->setDate($account->getOpeningDate() ?? (new \DateTime())->format('Y-m-d'));
		$journal->setDescription('Eröffnungsbuchung ' . $account->getNumber() . ' ' . $account->getName());
		$journal->setDocumentRef(JournalMapper::OPENING_REF);
		$journal->setBankTxId(null);
		$journal->setCreatedAt((new \DateTime())->format('Y-m-d H:i:s'));
		$journal = $this->journalMapper->insert($journal);

		$abs = abs($amount);
		$naturalDebit = in_array($account->getType(), ['asset', 'expense'], true);
		$accountOnDebit = ($amount >= 0) === $naturalDebit;

		if ($accountOnDebit) {
			$this->addLine($journal->getId(), $account->getId(), $abs, 0);
			$this->addLine($journal->getId(), $equity->getId(), 0, $abs);
		} else {
			$this->addLine($journal->getId(), $account->getId(), 0, $abs);
			$this->addLine($journal->getId(), $equity->getId(), $abs, 0);
		}
	}

	private function addLine(int $journalId, int $accountId, int $debit, int $credit): void {
		$line = new JournalLine();
		$line->setJournalId($journalId);
		$line->setAccountId($accountId);
		$line->setDebitCents($debit);
		$line->setCreditCents($credit);
		$this->lineMapper->insert($line);
	}
}
