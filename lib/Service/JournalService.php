<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\Journal;
use OCA\Vereinsbuchhaltung\Db\JournalLine;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Exception\ConflictException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Erstellt und pflegt allgemeine Buchungssätze (Soll an Haben).
 *
 * Alle Schreibpfade laufen in einer Transaktion: Kopf und Buchungszeilen
 * entstehen und verschwinden ausschließlich gemeinsam, sonst bliebe eine
 * halbe – und damit unausgeglichene – Buchung zurück.
 *
 * Intern ist eine Buchung immer eine Liste von Zeilen (siehe
 * {@see self::createBookingLines()}); der häufige Fall "ein Konto gegen ein
 * anderes" ist die zweizeilige Sonderform und behält mit
 * {@see self::createBooking()} seinen bequemen Einstieg. Eine Splittbuchung
 * (ein Betrag auf mehrere Gegenkonten) unterscheidet sich davon nur in der
 * Zahl der Zeilen – Nummernvergabe, Festschreibung, Locking und Protokoll
 * sind für beide dieselben.
 */
class JournalService {

	/**
	 * Obergrenze für die Zeilen einer Buchung. Fachlich gibt es keine – die
	 * Schranke hält nur unsinnige Eingaben aus der Schnittstelle heraus, die
	 * sonst als tausendzeilige Buchung in der Datenbank landen würden.
	 */
	public const MAX_LINES = 50;

	public function __construct(
		private JournalMapper $journalMapper,
		private JournalLineMapper $lineMapper,
		private AccountMapper $accountMapper,
		private BankTransactionMapper $txMapper,
		private AttachmentStorageService $attachmentStorage,
		private YearCloseService $yearClose,
		private AuditService $audit,
		private EntryNumberService $entryNumbers,
		private TransactionRunner $transaction,
		private IL10N $l10n,
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
		return $this->createBookingLines(
			$userId,
			$date,
			$description,
			$docRef,
			self::simpleLines($debitAccountId, $creditAccountId, $amountCents),
			$entryNo,
			$audit,
		);
	}

	/**
	 * Legt einen Buchungssatz aus beliebig vielen Zeilen an – der allgemeine
	 * Fall hinter {@see self::createBooking()}, den eine Splittbuchung braucht.
	 *
	 * @param array<int, array{accountId:int, debitCents:int, creditCents:int}> $lines
	 *        geprüft mit {@see self::validateLines()}
	 * @param int|null $entryNo vorgegebene Buchungsnummer (z.B. beim Massenimport)
	 * @param bool $audit Einzeleintrag im Änderungsprotokoll
	 * @throws \InvalidArgumentException wenn die Zeilen nicht ausgeglichen sind
	 */
	public function createBookingLines(
		string $userId,
		string $date,
		string $description,
		?string $docRef,
		array $lines,
		?int $entryNo = null,
		bool $audit = true,
	): Journal {
		$this->assertLines($lines);
		$insert = fn (): Journal => $this->transaction->run(
			fn (): Journal => $this->insertBooking($userId, $date, $description, $docRef, $lines, $entryNo, $audit),
		);

		// Nummer vorgegeben (Import vergibt sie selbst) oder wir stecken schon in
		// einer größeren Transaktion: dann kein Wiederholungsversuch, siehe
		// TransactionRunner::runWithRetry().
		if ($entryNo !== null || $this->transaction->isActive()) {
			return $insert();
		}
		return $this->transaction->runWithRetry($insert);
	}

	/**
	 * @param array<int, array{accountId:int, debitCents:int, creditCents:int}> $lines
	 */
	private function insertBooking(
		string $userId,
		string $date,
		string $description,
		?string $docRef,
		array $lines,
		?int $entryNo,
		bool $audit,
	): Journal {
		$this->yearClose->assertOpen($date);

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

		$this->writeLines($journal->getId(), $lines);

		if ($audit) {
			$this->audit->log('Buchung angelegt', 'journal', $journal->getId(), [
				'entryNo' => $journal->getEntryNo(),
				'date' => $date,
				'description' => $journal->getDescription(),
				'amount' => self::totalCents($lines) / 100,
				// Nur bei einer Splittbuchung erwähnen – sonst stünde in jedem
				// Protokolleintrag ein "Zeilen: 2", das nichts sagt.
				...(count($lines) > 2 ? ['zeilen' => count($lines)] : []),
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
		return $this->updateBookingLines(
			$id,
			$userId,
			$date,
			$description,
			$docRef,
			self::simpleLines($debitAccountId, $creditAccountId, $amountCents),
			$expectedUpdatedAt,
		);
	}

	/**
	 * Ersetzt die Zeilen einer Buchung vollständig – der allgemeine Fall hinter
	 * {@see self::updateBooking()}. Aus einer zweizeiligen Buchung kann dabei
	 * eine Splittbuchung werden und umgekehrt.
	 *
	 * @param array<int, array{accountId:int, debitCents:int, creditCents:int}> $lines
	 * @param string|null $expectedUpdatedAt siehe {@see self::updateBooking()}
	 * @throws ConflictException bei zwischenzeitlicher Fremdänderung
	 * @throws \InvalidArgumentException wenn die Zeilen nicht ausgeglichen sind
	 */
	public function updateBookingLines(
		int $id,
		string $userId,
		string $date,
		string $description,
		?string $docRef,
		array $lines,
		?string $expectedUpdatedAt = null,
	): Journal {
		$this->assertLines($lines);
		$update = fn (): Journal => $this->transaction->run(
			fn (): Journal => $this->applyUpdate($id, $userId, $date, $description, $docRef, $lines, $expectedUpdatedAt),
		);
		if ($this->transaction->isActive()) {
			return $update();
		}
		// Ein Jahreswechsel vergibt eine neue Nummer – dabei ist derselbe
		// Wettlauf möglich wie beim Anlegen.
		return $this->transaction->runWithRetry($update);
	}

	/**
	 * @param array<int, array{accountId:int, debitCents:int, creditCents:int}> $lines
	 */
	private function applyUpdate(
		int $id,
		string $userId,
		string $date,
		string $description,
		?string $docRef,
		array $lines,
		?string $expectedUpdatedAt,
	): Journal {
		$journal = $this->journalMapper->find($id, $userId);
		// Sowohl das bisherige als auch das neue Jahr müssen offen sein
		// (sonst ließe sich eine Buchung aus einem abgeschlossenen Jahr
		// herausziehen oder in eines hineinschieben).
		$this->yearClose->assertOpen((string)$journal->getDate());
		$this->yearClose->assertOpen($date);
		if (($journal->getUpdatedAt() ?? '') !== ($expectedUpdatedAt ?? '')) {
			throw new ConflictException($this->l10n->t('Die Buchung wurde zwischenzeitlich von einer anderen Person geändert.'));
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
		$this->writeLines($journal->getId(), $lines);

		if ($newYear !== $oldYear) {
			// Das alte Jahr hat jetzt eine Lücke – schließen.
			$this->entryNumbers->renumberYear($userId, $oldYear);
		}

		$this->audit->log('Buchung geändert', 'journal', $journal->getId(), [
			'entryNo' => $journal->getEntryNo(),
			'date' => $date,
			'description' => $journal->getDescription(),
			'amount' => self::totalCents($lines) / 100,
			...(count($lines) > 2 ? ['zeilen' => count($lines)] : []),
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
				throw new ConflictException($this->l10n->t('Die Buchung wurde zwischenzeitlich von einer anderen Person geändert.'));
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
		if (!$stays) {
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

			$released = $this->releaseBankTransaction($journal);
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
				// Nur erwähnen, wenn tatsächlich ein Umsatz betroffen war.
				...($released ? ['umsatz' => 'wieder unter „Zuzuordnen"'] : []),
			]);
		});
	}

	/**
	 * Gibt den Bankumsatz wieder frei, aus dem dieser Buchungssatz entstanden ist.
	 *
	 * Ohne diesen Schritt bliebe der Umsatz nach dem Löschen seines Buchungssatzes
	 * auf status='assigned' mit einer journal_id stehen, hinter der nichts mehr
	 * liegt. Er tauchte dann nirgends mehr auf: „Zuzuordnen" filtert auf
	 * 'unassigned', und in den Salden ist sein Betrag mit dem Buchungssatz
	 * verschwunden. Auch die Bank-Abstimmung (JournalController::balances())
	 * zählte ihn weder als gebucht noch als offen – der Kontostand wiche vom
	 * Bankauszug ab, ohne dass irgendetwas darauf hinweist.
	 *
	 * Zurückgesetzt wird auf denselben Stand wie bei
	 * {@see BookingService::unassign()}: der Umsatz ist wieder offen und lässt
	 * sich neu zuordnen.
	 *
	 * @return bool ob ein Umsatz freigegeben wurde
	 */
	private function releaseBankTransaction(Journal $journal): bool {
		$bankTxId = $journal->getBankTxId();
		if ($bankTxId === null) {
			return false;
		}
		try {
			$tx = $this->txMapper->find($bankTxId, (string)$journal->getUserId());
		} catch (DoesNotExistException) {
			return false; // Umsatz gibt es nicht mehr – nichts freizugeben.
		}
		// Zeigt der Umsatz inzwischen auf einen anderen Buchungssatz (Neu-
		// zuordnung), gehört er nicht mehr zu diesem hier und bleibt unberührt.
		if ($tx->getJournalId() !== $journal->getId()) {
			return false;
		}
		$tx->setContraAccountId(null);
		$tx->setJournalId(null);
		$tx->setStatus('unassigned');
		$this->txMapper->update($tx);
		return true;
	}

	/**
	 * Prüft eine Zeilenliste, ohne die Datenbank zu berühren – nach dem Vorbild
	 * von {@see self::reassignPlan()}, damit die Fallunterscheidung prüfbar
	 * bleibt.
	 *
	 * Die Regel „kein Konto doppelt" ist dabei kein Schönheitswunsch: Auf ihr
	 * ruhen die Annahmen von {@see self::reassignPlan()} – dass es eine
	 * Gegenseite gibt und dass das Zielkonto nicht schon auf der anderen Seite
	 * steht. Ohne sie lieferte das Umbuchen aus dem Kontoauszug bei
	 * Splittbuchungen falsche Ergebnisse.
	 *
	 * @param array<int, array{accountId:int, debitCents:int, creditCents:int}> $lines
	 * @return string|null Fehlermeldung oder null, wenn die Zeilen stimmig sind
	 */
	public static function validateLines(array $lines): ?string {
		if (count($lines) < 2) {
			return 'Eine Buchung braucht mindestens zwei Zeilen (Soll und Haben).';
		}
		if (count($lines) > self::MAX_LINES) {
			return sprintf('Eine Buchung darf höchstens %d Zeilen haben.', self::MAX_LINES);
		}
		$debitSum = 0;
		$creditSum = 0;
		$seen = [];
		foreach ($lines as $line) {
			$debit = $line['debitCents'] ?? 0;
			$credit = $line['creditCents'] ?? 0;
			if ($debit < 0 || $credit < 0) {
				return 'Beträge müssen positiv sein.';
			}
			if ($debit > 0 && $credit > 0) {
				return 'Eine Buchungszeile steht entweder im Soll oder im Haben, nicht in beidem.';
			}
			if ($debit === 0 && $credit === 0) {
				return 'Jede Buchungszeile braucht einen Betrag größer als 0.';
			}
			$accountId = $line['accountId'] ?? 0;
			if ($accountId <= 0) {
				return 'Jede Buchungszeile braucht ein Konto.';
			}
			if (isset($seen[$accountId])) {
				// Zweimal dasselbe Konto ließe sich zwar aufsummieren, wäre aber
				// entweder ein Bedienfehler (zwei Zeilen auf derselben Kategorie)
				// oder eine in sich leere Buchung (dasselbe Konto in Soll und
				// Haben). Beides lieber ablehnen als still zusammenfassen.
				return 'Jedes Konto darf in einer Buchung nur einmal vorkommen.';
			}
			$seen[$accountId] = true;
			$debitSum += $debit;
			$creditSum += $credit;
		}
		if ($debitSum !== $creditSum) {
			return sprintf(
				'Soll und Haben sind nicht ausgeglichen (%s € gegen %s €).',
				number_format($debitSum / 100, 2, ',', '.'),
				number_format($creditSum / 100, 2, ',', '.'),
			);
		}
		if ($debitSum <= 0) {
			return 'Betrag muss größer als 0 sein';
		}
		return null;
	}

	/**
	 * Zerlegt die Zeilen einer Buchung in Soll-Haben-Paare.
	 *
	 * Gedacht für Ausgaben, die je Zeile ein Soll- und ein Habenkonto zeigen –
	 * allen voran der Journal-Export. Eine Splittbuchung passt dort nicht in
	 * eine Zeile; sie wird auf mehrere mit derselben Buchungsnummer verteilt,
	 * so wie ein Journal Splittbuchungen üblicherweise ausweist. Die Summe der
	 * Paare ergibt immer den Buchungsbetrag.
	 *
	 * Für die zweizeilige Buchung – den Normalfall – kommt genau ein Paar
	 * heraus; dort ändert sich also nichts.
	 *
	 * @param array<int, array{accountId:int, debitCents:int, creditCents:int}> $lines
	 * @return array<int, array{debitAccountId:int, creditAccountId:int, amountCents:int}>
	 */
	public static function pairLines(array $lines): array {
		$debits = [];
		$credits = [];
		foreach ($lines as $line) {
			if (($line['debitCents'] ?? 0) > 0) {
				$debits[] = ['accountId' => $line['accountId'], 'rest' => $line['debitCents']];
			}
			if (($line['creditCents'] ?? 0) > 0) {
				$credits[] = ['accountId' => $line['accountId'], 'rest' => $line['creditCents']];
			}
		}

		$pairs = [];
		$i = 0;
		$j = 0;
		while ($i < count($debits) && $j < count($credits)) {
			// Immer den kleineren der beiden Reste verbuchen; die größere Seite
			// bleibt mit ihrem Rest für das nächste Paar stehen. So geht die
			// Aufteilung auch bei mehrzeiligen Seiten restlos auf.
			$amount = min($debits[$i]['rest'], $credits[$j]['rest']);
			$pairs[] = [
				'debitAccountId' => $debits[$i]['accountId'],
				'creditAccountId' => $credits[$j]['accountId'],
				'amountCents' => $amount,
			];
			$debits[$i]['rest'] -= $amount;
			$credits[$j]['rest'] -= $amount;
			if ($debits[$i]['rest'] === 0) {
				$i++;
			}
			if ($credits[$j]['rest'] === 0) {
				$j++;
			}
		}
		return $pairs;
	}

	/**
	 * Die zweizeilige Sonderform "Soll an Haben".
	 *
	 * @return array<int, array{accountId:int, debitCents:int, creditCents:int}>
	 */
	public static function simpleLines(int $debitAccountId, int $creditAccountId, int $amountCents): array {
		$amount = abs($amountCents);
		return [
			['accountId' => $debitAccountId, 'debitCents' => $amount, 'creditCents' => 0],
			['accountId' => $creditAccountId, 'debitCents' => 0, 'creditCents' => $amount],
		];
	}

	/** Buchungsbetrag = Summe der Sollseite (= Summe der Habenseite). */
	private static function totalCents(array $lines): int {
		$sum = 0;
		foreach ($lines as $line) {
			$sum += $line['debitCents'] ?? 0;
		}
		return $sum;
	}

	/** @throws \InvalidArgumentException */
	private function assertLines(array $lines): void {
		$error = self::validateLines($lines);
		if ($error !== null) {
			throw new \InvalidArgumentException($error);
		}
	}

	/** @param array<int, array{accountId:int, debitCents:int, creditCents:int}> $lines */
	private function writeLines(int $journalId, array $lines): void {
		foreach ($lines as $line) {
			$this->addLine($journalId, $line['accountId'], $line['debitCents'], $line['creditCents']);
		}
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
