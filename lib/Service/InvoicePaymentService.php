<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IUserSession;

/**
 * Zahlungen zu Rechnungen verbuchen (Epic #168).
 *
 * Die Rechnung selbst löst keine Buchung aus (Ist-Prinzip). Erst die Zahlung
 * wird gebucht: Geldkonto im Soll, die Erlöskonten der Positionen im Haben.
 * Der Weg über den Bankumsatz nutzt {@see BookingService::assignParts()} und
 * damit dieselbe Buchungslogik wie jede andere Zuordnung, einschließlich
 * Festschreibung der Perioden. Barzahlungen ohne Bankumsatz buchen direkt
 * gegen ein Geldkonto.
 *
 * Teilzahlungen sind vorgesehen: Eine Zahlung darf kleiner sein als der offene
 * Rest, nie größer. Wer mehr überweist, teilt den Umsatz selbst auf (Rest auf
 * ein anderes Konto), das ist eine fachliche Entscheidung.
 */
class InvoicePaymentService {

	public function __construct(
		private BookingService $booking,
		private JournalService $journals,
		private InvoicePaymentLedger $ledger,
		private InvoiceMapper $invoices,
		private OpenItemMapper $openItems,
		private JournalMapper $journalMapper,
		private BankTransactionMapper $txMapper,
		private AccountMapper $accounts,
		private TransactionRunner $transaction,
		private AuditService $audit,
		private IUserSession $userSession,
		private ?IL10N $l10n = null,
	) {
	}

	/**
	 * Ordnet einen Zahlungseingang einer Rechnung zu und bucht ihn.
	 *
	 * @throws DoesNotExistException wenn es die Rechnung nicht gibt
	 * @throws \InvalidArgumentException wenn die Zahlung nicht passt (kein Eingang, Rechnung nicht offen, Betrag zu hoch)
	 */
	public function payFromBankTransaction(BankTransaction $tx, int $invoiceId): BankTransaction {
		return $this->transaction->run(function () use ($tx, $invoiceId): BankTransaction {
			$invoice = $this->invoices->find($invoiceId);
			$this->assertPayable($invoice);
			if ($tx->getAmountCents() <= 0) {
				throw new \InvalidArgumentException($this->msg('Einer Rechnung lassen sich nur Zahlungseingänge zuordnen.'));
			}
			if ($tx->getJournalId() !== null) {
				// Neu zuordnen: erst die bisherige Zuordnung lösen, das nimmt auch
				// eine frühere Zahlung zu einer Rechnung zurück.
				$tx = $this->booking->unassign($tx);
			}
			$item = $this->itemOf($invoice);
			$amount = $tx->getAmountCents();
			$this->assertWithinRemaining($item, $amount);

			$parts = $this->ledger->allocate($invoice, $amount);
			$list = [];
			foreach ($parts as $accountId => $cents) {
				$list[] = ['accountId' => $accountId, 'amountCents' => $cents];
			}
			$tx = $this->booking->assignParts($tx, $list);
			$journalId = (int)$tx->getJournalId();
			$this->labelJournal($journalId, $invoice, $tx->getCounterparty());
			$this->ledger->record($invoice, $item, $amount, (int)$tx->getId(), $journalId, $parts, $this->actor());

			$this->audit->log('Rechnung bezahlt (Bankumsatz)', 'invoice', $invoiceId, [
				'number' => $invoice->getNumber(),
				'amount' => $amount / 100,
				'transaction' => $tx->getId(),
			]);
			return $tx;
		});
	}

	/**
	 * Erfasst eine Zahlung ohne Bankumsatz (Barzahlung): bucht Geldkonto an
	 * Erlöskonten.
	 *
	 * @param int|null $amountCents null = der volle offene Rest
	 * @throws DoesNotExistException wenn es die Rechnung nicht gibt
	 * @throws \InvalidArgumentException bei ungültigem Datum, Konto oder Betrag
	 */
	public function payManually(int $invoiceId, string $date, int $cashAccountId, ?int $amountCents = null): Invoice {
		return $this->transaction->run(function () use ($invoiceId, $date, $cashAccountId, $amountCents): Invoice {
			$invoice = $this->invoices->find($invoiceId);
			$this->assertPayable($invoice);
			if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
				throw new \InvalidArgumentException($this->msg('Ungültiges Datum: %s', [$date]));
			}
			try {
				$cash = $this->accounts->find($cashAccountId, Application::BOOK);
			} catch (DoesNotExistException) {
				throw new \InvalidArgumentException($this->msg('Das Geldkonto existiert nicht.'));
			}
			if (!$cash->getIsBank() || !$cash->getActive()) {
				throw new \InvalidArgumentException($this->msg('Gebucht wird auf ein aktives Geldkonto (Bank oder Kasse).'));
			}
			$item = $this->itemOf($invoice);
			$amount = $amountCents ?? $item->remainingCents();
			$this->assertWithinRemaining($item, $amount);

			$parts = $this->ledger->allocate($invoice, $amount);
			$lines = [['accountId' => $cashAccountId, 'debitCents' => $amount, 'creditCents' => 0]];
			foreach ($parts as $accountId => $cents) {
				$lines[] = ['accountId' => $accountId, 'debitCents' => 0, 'creditCents' => $cents];
			}
			$journal = $this->journals->createBookingLines(
				Application::BOOK,
				$date,
				$this->description($invoice, null),
				$invoice->getNumber(),
				$lines,
			);
			$this->ledger->record($invoice, $item, $amount, null, (int)$journal->getId(), $parts, $this->actor());
			$this->audit->log('Rechnung bezahlt (Barzahlung)', 'invoice', $invoiceId, [
				'number' => $invoice->getNumber(),
				'amount' => $amount / 100,
				'date' => $date,
			]);
			return $this->invoices->find($invoiceId);
		});
	}

	/**
	 * Nimmt eine Zahlung zurück: löst die Zuordnung des Bankumsatzes bzw.
	 * löscht den Buchungssatz der Barzahlung. Das Zahlungsbuch zieht dabei
	 * über {@see InvoicePaymentLedger::revertForJournal()} nach.
	 *
	 * @throws DoesNotExistException
	 */
	public function revertPayment(int $invoiceId, int $paymentId): void {
		$this->transaction->run(function () use ($invoiceId, $paymentId): void {
			$payments = array_filter($this->ledger->forInvoice($invoiceId), static fn ($p) => (int)$p->getId() === $paymentId);
			$payment = reset($payments);
			if ($payment === false) {
				throw new DoesNotExistException('Zahlung nicht gefunden');
			}
			if ($payment->getBankTxId() !== null) {
				$this->booking->unassign($this->txMapper->find($payment->getBankTxId(), Application::BOOK));
			} elseif ($payment->getJournalId() !== null) {
				$this->journals->deleteBooking($payment->getJournalId(), Application::BOOK);
			} else {
				$this->ledger->revert($payment);
			}
			$this->audit->log('Rechnungszahlung zurückgenommen', 'invoice', $invoiceId, ['amount' => $payment->getAmountCents() / 100]);
		});
	}

	private function assertPayable(Invoice $invoice): void {
		if ($invoice->isCredit() || !in_array($invoice->getStatus(), [Invoice::STATUS_ISSUED, Invoice::STATUS_SENT, Invoice::STATUS_PAID], true)) {
			throw new \InvalidArgumentException($this->msg('Zu dieser Rechnung lässt sich keine Zahlung erfassen.'));
		}
	}

	private function itemOf(Invoice $invoice): OpenItem {
		if ($invoice->getOpenItemId() === null) {
			throw new \InvalidArgumentException($this->msg('Zu dieser Rechnung gibt es keinen offenen Posten.'));
		}
		return $this->openItems->find($invoice->getOpenItemId());
	}

	private function assertWithinRemaining(OpenItem $item, int $amount): void {
		$remaining = $item->remainingCents();
		if ($remaining <= 0) {
			throw new \InvalidArgumentException($this->msg('Die Rechnung ist bereits vollständig bezahlt.'));
		}
		if ($amount <= 0) {
			throw new \InvalidArgumentException($this->msg('Der Betrag muss größer als 0 sein.'));
		}
		if ($amount > $remaining) {
			throw new \InvalidArgumentException($this->msg(
				'Der Betrag (%1$s €) übersteigt den offenen Rest der Rechnung (%2$s €). Bitte den Umsatz aufteilen und den Rest einem anderen Konto zuordnen.',
				[number_format($amount / 100, 2, ',', '.'), number_format($remaining / 100, 2, ',', '.')],
			));
		}
	}

	/** Buchungstext und Belegnummer des Buchungssatzes zeigen auf die Rechnung. */
	private function labelJournal(int $journalId, Invoice $invoice, ?string $counterparty): void {
		$journal = $this->journalMapper->find($journalId, Application::BOOK);
		$journal->setDescription($this->description($invoice, $counterparty));
		$journal->setDocumentRef($invoice->getNumber() !== null ? mb_substr($invoice->getNumber(), 0, 64) : null);
		$this->journalMapper->update($journal);
	}

	private function description(Invoice $invoice, ?string $counterparty): string {
		$name = $counterparty;
		if ($name === null || trim($name) === '') {
			$name = (string)($invoice->debtorSnapshotData()['name'] ?? '');
		}
		$text = $this->msg('Rechnung %s', [(string)$invoice->getNumber()]);
		return mb_substr($name !== '' ? $text . ' – ' . $name : $text, 0, 255);
	}

	private function actor(): string {
		return $this->userSession->getUser()?->getUID() ?? '?';
	}

	/** @param list<int|string> $params */
	private function msg(string $text, array $params = []): string {
		return $this->l10n !== null ? $this->l10n->t($text, $params) : vsprintf($text, $params);
	}
}
