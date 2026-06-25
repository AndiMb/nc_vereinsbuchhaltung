<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Journal;
use OCA\Vereinsbuchhaltung\Db\JournalLine;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;

/**
 * Erstellt und pflegt allgemeine Buchungssätze (Soll an Haben).
 */
class JournalService {

	public function __construct(
		private JournalMapper $journalMapper,
		private JournalLineMapper $lineMapper,
	) {
	}

	/**
	 * Legt einen Buchungssatz "Soll an Haben" an.
	 *
	 * @param int|null $entryNo vorgegebene Buchungsnummer (z.B. beim Massenimport)
	 */
	public function createBooking(
		string $userId,
		string $date,
		string $description,
		?string $docRef,
		int $debitAccountId,
		int $creditAccountId,
		int $amountCents,
		?int $entryNo = null,
	): Journal {
		$amount = abs($amountCents);

		$journal = new Journal();
		$journal->setUserId($userId);
		$year = (int)substr($date, 0, 4);
		$journal->setEntryNo($entryNo ?? $this->journalMapper->getNextEntryNoForYear($userId, $year));
		$journal->setDate($date);
		$journal->setDescription(mb_substr($description, 0, 255));
		$journal->setDocumentRef($docRef !== null ? mb_substr($docRef, 0, 64) : null);
		$journal->setBankTxId(null);
		$journal->setCreatedAt((new \DateTime())->format('Y-m-d H:i:s'));
		$journal = $this->journalMapper->insert($journal);

		$this->addLine($journal->getId(), $debitAccountId, $amount, 0);
		$this->addLine($journal->getId(), $creditAccountId, 0, $amount);

		return $journal;
	}

	public function updateBooking(
		int $id,
		string $userId,
		string $date,
		string $description,
		?string $docRef,
		int $debitAccountId,
		int $creditAccountId,
		int $amountCents,
	): Journal {
		$journal = $this->journalMapper->find($id, $userId);
		$journal->setDate($date);
		$journal->setDescription(mb_substr($description, 0, 255));
		$journal->setDocumentRef($docRef !== null ? mb_substr($docRef, 0, 64) : null);
		$journal = $this->journalMapper->update($journal);

		$this->lineMapper->deleteByJournal($journal->getId());
		$amount = abs($amountCents);
		$this->addLine($journal->getId(), $debitAccountId, $amount, 0);
		$this->addLine($journal->getId(), $creditAccountId, 0, $amount);

		return $journal;
	}

	public function deleteBooking(int $id, string $userId): void {
		$journal = $this->journalMapper->find($id, $userId);
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
}
