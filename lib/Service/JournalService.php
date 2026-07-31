<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\Journal;
use OCA\Vereinsbuchhaltung\Db\JournalLine;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Exception\ConflictException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Erstellt und pflegt allgemeine Buchungssätze (Soll an Haben).
 *
 * Alle Schreibpfade laufen in einer Transaktion: Kopf und die beiden
 * Soll-/Haben-Zeilen entstehen und verschwinden ausschließlich gemeinsam,
 * sonst bliebe eine halbe – und damit unausgeglichene – Buchung zurück.
 */
class JournalService {

	public function __construct(
		private JournalMapper $journalMapper,
		private JournalLineMapper $lineMapper,
		private AccountMapper $accountMapper,
		private AttachmentStorageService $attachmentStorage,
		private YearCloseService $yearClose,
		private AuditService $audit,
		private EntryNumberService $entryNumbers,
		private TransactionRunner $transaction,
	) {
	}

	/**
	 * Legt einen Buchungssatz "Soll an Haben" an.
	 *
	 * @param int|null $entryNo vorgegebene Buchungsnummer (z.B. beim Massenimport)
	 * @param bool $audit Einzeleintrag im Änderungsprotokoll (beim Massenimport
	 *                    abschalten – der Import protokolliert sich als Ganzes)
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
		bool $audit = true,
	): Journal {
		$insert = fn (): Journal => $this->transaction->run(
			fn (): Journal => $this->insertBooking($userId, $date, $description, $docRef, $debitAccountId, $creditAccountId, $amountCents, $entryNo, $audit),
		);

		// Nummer vorgegeben (Import vergibt sie selbst) oder wir stecken schon in
		// einer größeren Transaktion: dann kein Wiederholungsversuch, siehe
		// TransactionRunner::runWithRetry().
		if ($entryNo !== null || $this->transaction->isActive()) {
			return $insert();
		}
		return $this->transaction->runWithRetry($insert);
	}

	private function insertBooking(
		string $userId,
		string $date,
		string $description,
		?string $docRef,
		int $debitAccountId,
		int $creditAccountId,
		int $amountCents,
		?int $entryNo,
		bool $audit,
	): Journal {
		$this->yearClose->assertOpen($date);
		$amount = abs($amountCents);

		$journal = new Journal();
		$journal->setUserId($userId);
		$journal->setDateWithYear($date);
		$journal->setEntryNo($entryNo ?? $this->entryNumbers->next($userId, $journal->getYear()));
		$journal->setDescription(mb_substr($description, 0, 255));
		$journal->setDocumentRef($docRef !== null ? mb_substr($docRef, 0, 64) : null);
		$journal->setBankTxId(null);
		$journal->setCreatedAt((new \DateTime())->format('Y-m-d H:i:s'));
		$journal->setUpdatedAt(self::now());
		$journal = $this->journalMapper->insert($journal);

		$this->addLine($journal->getId(), $debitAccountId, $amount, 0);
		$this->addLine($journal->getId(), $creditAccountId, 0, $amount);

		if ($audit) {
			$this->audit->log('Buchung angelegt', 'journal', $journal->getId(), [
				'entryNo' => $journal->getEntryNo(),
				'date' => $date,
				'description' => $journal->getDescription(),
				'amount' => $amount / 100,
			]);
		}
		return $journal;
	}

	/**
	 * @param string|null $expectedUpdatedAt updatedAt-Stand, den der Client beim
	 *        Laden gesehen hat (optimistisches Locking); weicht er vom aktuellen
	 *        Stand ab, hat zwischenzeitlich jemand anderes gespeichert.
	 * @throws ConflictException bei zwischenzeitlicher Fremdänderung
	 */
	public function updateBooking(
		int $id,
		string $userId,
		string $date,
		string $description,
		?string $docRef,
		int $debitAccountId,
		int $creditAccountId,
		int $amountCents,
		?string $expectedUpdatedAt = null,
	): Journal {
		$update = fn (): Journal => $this->transaction->run(
			fn (): Journal => $this->applyUpdate($id, $userId, $date, $description, $docRef, $debitAccountId, $creditAccountId, $amountCents, $expectedUpdatedAt),
		);
		if ($this->transaction->isActive()) {
			return $update();
		}
		// Ein Jahreswechsel vergibt eine neue Nummer – dabei ist derselbe
		// Wettlauf möglich wie beim Anlegen.
		return $this->transaction->runWithRetry($update);
	}

	private function applyUpdate(
		int $id,
		string $userId,
		string $date,
		string $description,
		?string $docRef,
		int $debitAccountId,
		int $creditAccountId,
		int $amountCents,
		?string $expectedUpdatedAt,
	): Journal {
		$journal = $this->journalMapper->find($id, $userId);
		// Sowohl das bisherige als auch das neue Jahr müssen offen sein
		// (sonst ließe sich eine Buchung aus einem abgeschlossenen Jahr
		// herausziehen oder in eines hineinschieben).
		$this->yearClose->assertOpen((string)$journal->getDate());
		$this->yearClose->assertOpen($date);
		if (($journal->getUpdatedAt() ?? '') !== ($expectedUpdatedAt ?? '')) {
			throw new ConflictException('Die Buchung wurde zwischenzeitlich von einer anderen Person geändert.');
		}

		$oldYear = $journal->getYear();
		$newYear = Journal::yearOf($date);

		$journal->setDateWithYear($date);
		if ($newYear !== $oldYear) {
			// Die Nummer gehört zum alten Jahr: im neuen Jahr eine frische
			// vergeben, sonst kollidiert sie dort mit einer bestehenden.
			$journal->setEntryNo($this->entryNumbers->next($userId, $newYear));
		}
		$journal->setDescription(mb_substr($description, 0, 255));
		$journal->setDocumentRef($docRef !== null ? mb_substr($docRef, 0, 64) : null);
		$journal->setUpdatedAt(self::now());
		$journal = $this->journalMapper->update($journal);

		$this->lineMapper->deleteByJournal($journal->getId());
		$amount = abs($amountCents);
		$this->addLine($journal->getId(), $debitAccountId, $amount, 0);
		$this->addLine($journal->getId(), $creditAccountId, 0, $amount);

		if ($newYear !== $oldYear) {
			// Das alte Jahr hat jetzt eine Lücke – schließen.
			$this->entryNumbers->renumberYear($userId, $oldYear);
		}

		$this->audit->log('Buchung geändert', 'journal', $journal->getId(), [
			'entryNo' => $journal->getEntryNo(),
			'date' => $date,
			'description' => $journal->getDescription(),
			'amount' => $amount / 100,
		]);
		return $journal;
	}

	/**
	 * Bucht eine einzelne Seite eines Buchungssatzes auf ein anderes Konto um.
	 *
	 * Gedacht für den Kontoauszug: wer beim Durchsehen eines Kontos eine falsch
	 * zugeordnete Buchung findet, korrigiert genau diese eine Seite, ohne den
	 * ganzen Buchungssatz neu zu erfassen. Betrag, Datum, Beschreibung und die
	 * Gegenseite bleiben unangetastet – Soll und Haben können dadurch nicht
	 * auseinanderlaufen.
	 *
	 * @param string|null $expectedUpdatedAt siehe {@see updateBooking()}
	 * @throws DoesNotExistException wenn Buchung oder Zielkonto fehlen
	 * @throws ConflictException bei zwischenzeitlicher Fremdänderung
	 * @throws \InvalidArgumentException wenn die Umbuchung fachlich nicht geht
	 */
	public function reassignLine(
		int $journalId,
		string $userId,
		int $fromAccountId,
		int $toAccountId,
		?string $expectedUpdatedAt = null,
	): Journal {
		return $this->transaction->run(function () use ($journalId, $userId, $fromAccountId, $toAccountId, $expectedUpdatedAt): Journal {
			$journal = $this->journalMapper->find($journalId, $userId);
			$this->yearClose->assertOpen((string)$journal->getDate());
			if (($journal->getUpdatedAt() ?? '') !== ($expectedUpdatedAt ?? '')) {
				throw new ConflictException('Die Buchung wurde zwischenzeitlich von einer anderen Person geändert.');
			}
			// Zielkonto muss existieren und zu diesem Bestand gehören – sonst
			// zeigte die Zeile anschließend auf ein Konto, das in keiner
			// Auswertung vorkommt (siehe AccountService::delete()).
			$target = $this->accountMapper->find($toAccountId, $userId);
			$from = $this->accountMapper->find($fromAccountId, $userId);

			$lines = $this->lineMapper->findByJournal($journalId);
			$moveIds = self::reassignPlan(
				array_map(
					static fn (JournalLine $l): array => ['id' => $l->getId(), 'accountId' => $l->getAccountId()],
					$lines,
				),
				$fromAccountId,
				$toAccountId,
			);
			foreach ($lines as $line) {
				if (in_array($line->getId(), $moveIds, true)) {
					$line->setAccountId($target->getId());
					$this->lineMapper->update($line);
				}
			}

			$journal->setUpdatedAt(self::now());
			$journal = $this->journalMapper->update($journal);

			$this->audit->log('Buchung umgebucht', 'journal', $journal->getId(), [
				'entryNo' => $journal->getEntryNo(),
				'date' => $journal->getDate(),
				'von' => $from->getNumber() . ' ' . $from->getName(),
				'nach' => $target->getNumber() . ' ' . $target->getName(),
			]);
			return $journal;
		});
	}

	/**
	 * Entscheidet, welche Buchungszeilen umzuhängen sind – ohne Datenbank,
	 * damit die Fallunterscheidung prüfbar bleibt.
	 *
	 * @param array<int, array{id:int, accountId:int}> $lines alle Zeilen des Buchungssatzes
	 * @return int[] IDs der Zeilen, die auf das Zielkonto wechseln
	 * @throws \InvalidArgumentException wenn die Umbuchung fachlich nicht geht
	 */
	public static function reassignPlan(array $lines, int $fromAccountId, int $toAccountId): array {
		if ($fromAccountId === $toAccountId) {
			throw new \InvalidArgumentException('Die Buchung steht bereits auf diesem Konto.');
		}
		$move = [];
		$stays = false;
		foreach ($lines as $line) {
			if ($line['accountId'] === $fromAccountId) {
				$move[] = $line['id'];
			} elseif ($line['accountId'] === $toAccountId) {
				// Das Zielkonto steht schon auf der anderen Seite: die Buchung
				// hätte danach dasselbe Konto im Soll und im Haben und wäre
				// inhaltlich leer.
				throw new \InvalidArgumentException('Auf diesem Konto steht bereits die Gegenseite der Buchung. Soll- und Habenkonto müssen unterschiedlich sein.');
			} else {
				$stays = true;
			}
		}
		if ($move === []) {
			throw new \InvalidArgumentException('Diese Buchung hat keine Zeile auf dem angegebenen Konto.');
		}
		if ($move !== [] && !$stays) {
			// Alle Zeilen lägen auf demselben Konto – dann gäbe es keine
			// Gegenseite mehr. Kommt nur bei kaputten Altdaten vor.
			throw new \InvalidArgumentException('Diese Buchung hat keine Gegenseite und kann nicht umgebucht werden.');
		}
		return $move;
	}

	public function deleteBooking(int $id, string $userId): void {
		$this->transaction->run(function () use ($id, $userId): void {
			$journal = $this->journalMapper->find($id, $userId);
			$this->yearClose->assertOpen((string)$journal->getDate());
			$year = $journal->getYear();

			$this->attachmentStorage->deleteForJournal($journal->getId());
			$this->lineMapper->deleteByJournal($journal->getId());
			$this->journalMapper->delete($journal);

			// Die frei gewordene Nummer würde sonst als Lücke im Kassenbericht
			// auftauchen (siehe EntryNumberService).
			$this->entryNumbers->renumberYear($userId, $year);

			$this->audit->log('Buchung gelöscht', 'journal', $id, [
				'entryNo' => $journal->getEntryNo(),
				'date' => $journal->getDate(),
				'description' => $journal->getDescription(),
			]);
		});
	}

	/** Änderungszeitstempel mit Mikrosekunden (Sekundenauflösung reicht dem Konfliktvergleich nicht). */
	private static function now(): string {
		return (new \DateTime())->format('Y-m-d H:i:s.u');
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
