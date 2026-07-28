<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\Journal;
use OCA\Vereinsbuchhaltung\Db\JournalLine;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;

/**
 * Pflegt die Eröffnungsbuchung eines Kontos (z.B. Anfangsbestand des Bankkontos)
 * als doppelten Buchungssatz gegen das Eigenkapital.
 *
 *  Aktivkonto, positiver Saldo:  Soll Konto / Haben Eigenkapital
 *  Aktivkonto, negativer Saldo:  Soll Eigenkapital / Haben Konto
 *
 * Läuft in einer Transaktion: die alte Eröffnungsbuchung wird entfernt und die
 * neue angelegt – ein Abbruch dazwischen ließe das Konto ohne Anfangsbestand
 * zurück.
 */
class OpeningBalanceService {

	public function __construct(
		private JournalMapper $journalMapper,
		private JournalLineMapper $lineMapper,
		private AccountService $accountService,
		private AttachmentStorageService $attachmentStorage,
		private YearCloseService $yearClose,
		private EntryNumberService $entryNumbers,
		private TransactionRunner $transaction,
	) {
	}

	/**
	 * Synchronisiert die Eröffnungsbuchung mit dem aktuell am Konto
	 * gespeicherten Eröffnungssaldo. Vorhandene Eröffnungsbuchung wird neu
	 * erzeugt; bei Saldo 0 wird keine Buchung angelegt.
	 */
	public function sync(Account $account): void {
		$run = fn () => $this->transaction->run(fn () => $this->doSync($account));
		if ($this->transaction->isActive()) {
			$run();
			return;
		}
		$this->transaction->runWithRetry($run);
	}

	private function doSync(Account $account): void {
		$userId = $account->getUserId();

		// Festschreibung: weder eine bestehende Eröffnungsbuchung eines
		// abgeschlossenen Jahres entfernen noch eine neue dort anlegen.
		$existing = $this->journalMapper->findOpeningForAccount($userId, $account->getId());
		if ($existing !== null) {
			$this->yearClose->assertOpen((string)$existing->getDate());
		}
		$newDate = $account->getOpeningDate() ?? (new \DateTime())->format('Y-m-d');
		if ($account->getOpeningBalanceCents() !== 0) {
			$this->yearClose->assertOpen($newDate);
		}

		// bestehende Eröffnungsbuchung dieses Kontos entfernen
		if ($existing !== null) {
			$existingYear = $existing->getYear();
			$this->attachmentStorage->deleteForJournal($existing->getId());
			$this->lineMapper->deleteByJournal($existing->getId());
			$this->journalMapper->delete($existing);
			// Lücke in der Nummerierung schließen (siehe EntryNumberService).
			$this->entryNumbers->renumberYear($userId, $existingYear);
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
		$date = $account->getOpeningDate() ?? (new \DateTime())->format('Y-m-d');
		$journal->setDateWithYear($date);
		$journal->setEntryNo($this->entryNumbers->next($userId, $journal->getYear()));
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
