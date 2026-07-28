<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\Journal;
use OCA\Vereinsbuchhaltung\Db\JournalLine;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;

/**
 * Übersetzt zugeordnete Bankbuchungen in doppelte Buchungssätze (Soll/Haben).
 *
 * Vorzeichenlogik (Betrag der Bankbuchung):
 *  - Geldeingang  (Betrag > 0): Soll Bankkonto   / Haben Gegenkonto (Ertrag)
 *  - Geldausgang  (Betrag < 0): Soll Gegenkonto  / Haben Bankkonto  (Aufwand)
 *
 * Zuordnen und Aufheben laufen jeweils in einer Transaktion: Buchungssatz,
 * Zeilen und der Status der Bankbuchung ändern sich nur gemeinsam.
 */
class BookingService {

	public function __construct(
		private JournalMapper $journalMapper,
		private JournalLineMapper $lineMapper,
		private BankTransactionMapper $txMapper,
		private AccountService $accountService,
		private AttachmentStorageService $attachmentStorage,
		private YearCloseService $yearClose,
		private AuditService $audit,
		private EntryNumberService $entryNumbers,
		private TransactionRunner $transaction,
	) {
	}

	/**
	 * Ordnet eine Bankbuchung einem Gegenkonto zu und erzeugt den Buchungssatz.
	 */
	public function assign(BankTransaction $tx, int $contraAccountId): BankTransaction {
		$run = fn (): BankTransaction => $this->transaction->run(fn (): BankTransaction => $this->doAssign($tx, $contraAccountId));
		if ($this->transaction->isActive()) {
			return $run();
		}
		return $this->transaction->runWithRetry($run);
	}

	private function doAssign(BankTransaction $tx, int $contraAccountId): BankTransaction {
		$userId = $tx->getUserId();
		$this->yearClose->assertOpen((string)$tx->getBookingDate());
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
		$journal->setDateWithYear((string)$tx->getBookingDate());
		$journal->setEntryNo($this->entryNumbers->next($userId, $journal->getYear()));
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
		$tx = $this->txMapper->update($tx);
		$this->audit->log('Umsatz zugeordnet', 'transaction', $tx->getId(), [
			'date' => $tx->getBookingDate(),
			'amount' => $tx->getAmountCents() / 100,
			'contra' => $contra->getNumber() . ' ' . $contra->getName(),
		]);
		return $tx;
	}

	/**
	 * Hebt eine Zuordnung wieder auf und löscht den Buchungssatz.
	 */
	public function unassign(BankTransaction $tx): BankTransaction {
		return $this->transaction->run(function () use ($tx): BankTransaction {
			$this->yearClose->assertOpen((string)$tx->getBookingDate());
			if ($tx->getJournalId() !== null) {
				$this->removeJournal($tx->getJournalId(), $tx->getUserId());
			}
			$tx->setContraAccountId(null);
			$tx->setJournalId(null);
			$tx->setStatus('unassigned');
			$tx = $this->txMapper->update($tx);
			$this->audit->log('Zuordnung entfernt', 'transaction', $tx->getId(), [
				'date' => $tx->getBookingDate(),
				'amount' => $tx->getAmountCents() / 100,
			]);
			return $tx;
		});
	}

	/**
	 * Entfernt einen Buchungssatz samt Zeilen und Belegen und schließt die
	 * dadurch entstehende Lücke in der Buchungsnummerierung des Jahres.
	 */
	private function removeJournal(int $journalId, string $userId): void {
		try {
			$journal = $this->journalMapper->find($journalId, $userId);
		} catch (\Throwable) {
			return;
		}
		$year = $journal->getYear();
		$this->attachmentStorage->deleteForJournal($journal->getId());
		$this->lineMapper->deleteByJournal($journal->getId());
		$this->journalMapper->delete($journal);
		$this->entryNumbers->renumberYear($userId, $year);
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
