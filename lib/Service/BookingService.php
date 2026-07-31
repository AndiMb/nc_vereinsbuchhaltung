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
 *
 * Ein Umsatz kann auch auf mehrere Gegenkonten aufgeteilt werden
 * ({@see self::assignParts()}); an der Vorzeichenlogik ändert das nichts, es
 * treten dann nur mehrere Gegenkontozeilen an die Stelle der einen.
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
		return $this->assignParts($tx, [['accountId' => $contraAccountId, 'amountCents' => abs($tx->getAmountCents())]]);
	}

	/**
	 * Teilt eine Bankbuchung auf mehrere Gegenkonten auf – der allgemeine Fall
	 * hinter {@see self::assign()}, der Einzelfall ist eine Liste der Länge 1.
	 *
	 * Gedacht für den Umsatz, der mehreres zugleich enthält: eine Überweisung
	 * über Beitrag und Spende, eine Rechnung über zwei Kostenstellen. Die
	 * Geldkontoseite bleibt dabei eine Zeile über den vollen Betrag – nur die
	 * Gegenseite wird aufgeteilt.
	 *
	 * @param array<int, array{accountId:int, amountCents:int}> $parts
	 * @throws \InvalidArgumentException wenn die Teile nicht den Umsatz ergeben
	 */
	public function assignParts(BankTransaction $tx, array $parts): BankTransaction {
		$error = self::validateParts($parts, abs($tx->getAmountCents()));
		if ($error !== null) {
			throw new \InvalidArgumentException($error);
		}
		$run = fn (): BankTransaction => $this->transaction->run(fn (): BankTransaction => $this->doAssign($tx, $parts));
		if ($this->transaction->isActive()) {
			return $run();
		}
		return $this->transaction->runWithRetry($run);
	}

	/**
	 * Prüft die Aufteilung eines Umsatzes, ohne die Datenbank zu berühren –
	 * nach dem Vorbild von {@see JournalService::validateLines()}.
	 *
	 * @param array<int, array{accountId:int, amountCents:int}> $parts
	 * @param int $totalCents Betrag des Umsatzes (ohne Vorzeichen)
	 * @return string|null Fehlermeldung oder null, wenn die Aufteilung aufgeht
	 */
	public static function validateParts(array $parts, int $totalCents): ?string {
		if ($totalCents <= 0) {
			// Ohne diesen Fall liefe der Nutzer in die irreführende Meldung
			// „Teilbetrag muss größer als 0 sein" für einen Umsatz, an dem
			// nichts aufzuteilen ist.
			return 'Ein Umsatz über 0 € lässt sich nicht zuordnen.';
		}
		if ($parts === []) {
			return 'Es fehlt das Gegenkonto.';
		}
		if (count($parts) > JournalService::MAX_LINES - 1) {
			return sprintf('Ein Umsatz lässt sich auf höchstens %d Konten aufteilen.', JournalService::MAX_LINES - 1);
		}
		$sum = 0;
		$seen = [];
		foreach ($parts as $part) {
			$accountId = $part['accountId'] ?? 0;
			$amount = $part['amountCents'] ?? 0;
			if ($accountId <= 0) {
				return 'Jeder Teilbetrag braucht ein Konto.';
			}
			if ($amount <= 0) {
				return 'Jeder Teilbetrag muss größer als 0 sein.';
			}
			if (isset($seen[$accountId])) {
				return 'Jedes Konto darf in der Aufteilung nur einmal vorkommen.';
			}
			$seen[$accountId] = true;
			$sum += $amount;
		}
		if ($sum !== $totalCents) {
			return sprintf(
				$sum < $totalCents
					? 'Die Aufteilung ergibt %1$s € statt %2$s € – es fehlen noch %3$s €.'
					: 'Die Aufteilung ergibt %1$s € statt %2$s € – das sind %3$s € zu viel.',
				number_format($sum / 100, 2, ',', '.'),
				number_format($totalCents / 100, 2, ',', '.'),
				number_format(abs($totalCents - $sum) / 100, 2, ',', '.'),
			);
		}
		return null;
	}

	/**
	 * @param array<int, array{accountId:int, amountCents:int}> $parts bereits geprüft
	 */
	private function doAssign(BankTransaction $tx, array $parts): BankTransaction {
		$userId = $tx->getUserId();
		$this->yearClose->assertOpen((string)$tx->getBookingDate());
		// Gegenkonten validieren (gehören dem Nutzer)
		$contras = [];
		foreach ($parts as $part) {
			$contras[] = [
				'account' => $this->accountService->find($part['accountId'], $userId),
				'amountCents' => $part['amountCents'],
			];
		}
		// Das Geldkonto folgt der IBAN aus dem Auszug, damit bei mehreren
		// Bankkonten auf dem richtigen gebucht wird. Ohne IBAN-Treffer bleibt es
		// beim ersten Bankkonto – siehe AccountService::resolveBankAccount().
		$bank = $this->accountService->resolveBankAccount($userId, $tx->getOwnAccount());

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

		// Geldeingang: Bank im Soll, die Gegenkonten im Haben – bei Ausgang
		// umgekehrt. Das Geldkonto bleibt eine Zeile über den vollen Betrag.
		if ($amount >= 0) {
			$this->addLine($journal->getId(), $bank->getId(), $abs, 0);
			foreach ($contras as $contra) {
				$this->addLine($journal->getId(), $contra['account']->getId(), 0, $contra['amountCents']);
			}
		} else {
			foreach ($contras as $contra) {
				$this->addLine($journal->getId(), $contra['account']->getId(), $contra['amountCents'], 0);
			}
			$this->addLine($journal->getId(), $bank->getId(), 0, $abs);
		}

		// Bei einer Aufteilung bleibt contra_account_id leer: die Spalte fasst
		// nur ein Konto, und ein herausgegriffenes wäre irreführend. Maßgeblich
		// sind dann Status und journalId. Folge: ein aufgeteilter Umsatz liefert
		// keinen Lernwert für Vorschläge und Regeln – ein Vorschlag "Konto X"
		// wäre für ihn ja auch falsch.
		$isSplit = count($contras) > 1;
		$tx->setContraAccountId($isSplit ? null : $contras[0]['account']->getId());
		$tx->setJournalId($journal->getId());
		$tx->setStatus('assigned');
		$tx = $this->txMapper->update($tx);
		$this->audit->log($isSplit ? 'Umsatz aufgeteilt zugeordnet' : 'Umsatz zugeordnet', 'transaction', $tx->getId(), [
			'date' => $tx->getBookingDate(),
			'amount' => $tx->getAmountCents() / 100,
			'contra' => implode(', ', array_map(
				static fn (array $c): string => $c['account']->getNumber() . ' ' . $c['account']->getName()
					. ($isSplit ? ' (' . number_format($c['amountCents'] / 100, 2, ',', '.') . ' €)' : ''),
				$contras,
			)),
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
