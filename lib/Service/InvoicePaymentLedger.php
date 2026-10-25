<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceLine;
use OCA\Vereinsbuchhaltung\Db\InvoiceLineMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceMapper;
use OCA\Vereinsbuchhaltung\Db\InvoicePayment;
use OCA\Vereinsbuchhaltung\Db\InvoicePaymentMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Das Zahlungsbuch der Rechnungen: hält fest, welche Zahlung welchen Teil
 * einer Rechnung ausgeglichen hat, und führt den offenen Posten und den Status
 * der Rechnung mit.
 *
 * Bewusst ohne Abhängigkeit zu BookingService und JournalService: beide rufen
 * {@see revertForJournal()} auf, wenn ein Buchungssatz verschwindet, und der
 * Weg zurück wäre ein Zirkelschluss. Gebucht wird anderswo
 * ({@see InvoicePaymentService}); hier steht nur die Buchführung über die
 * Zahlungen.
 *
 * Aufteilung auf die Erlöskonten: Jede Zahlung verteilt sich im Verhältnis des
 * noch nicht ausgeglichenen Anteils der Konten. Bei der letzten Zahlung bleibt
 * damit genau der Rest übrig, und die Summe je Konto entspricht am Ende exakt
 * den Rechnungspositionen.
 */
class InvoicePaymentLedger {

	public function __construct(
		private InvoicePaymentMapper $payments,
		private InvoiceMapper $invoices,
		private OpenItemMapper $openItems,
		private InvoiceLineMapper $lines,
		private InvoiceSettings $settings,
		private ?IL10N $l10n = null,
	) {
	}

	/**
	 * Noch nicht ausgeglichener Betrag je Erlöskonto.
	 *
	 * @return array<int, int> Konto-ID => Cent
	 */
	public function remainingWeights(Invoice $invoice): array {
		$weights = [];
		$lines = $this->lines->findByInvoice((int)$invoice->getId());
		foreach ($lines as $line) {
			$accountId = $line->getAccountId();
			if ($accountId === null) {
				continue;
			}
			$weights[$accountId] = ($weights[$accountId] ?? 0) + $line->getLineNetCents();
		}
		// Regelbesteuerung: die Umsatzsteuer je Satz gehört auf das Umsatzsteuerkonto.
		if ($invoice->getTaxMode() === Invoice::TAX_REGULAR) {
			$totals = InvoiceCalculator::totals(array_map(static fn (InvoiceLine $l): array => [
				'lineNetCents' => $l->getLineNetCents(),
				'taxRateBp' => $l->getTaxRateBp(),
				'taxCategory' => $l->getTaxCategory(),
			], $lines), Invoice::TAX_REGULAR);
			foreach ($totals['taxBreakdown'] as $group) {
				if ($group['taxCents'] === 0) {
					continue;
				}
				$vatAccount = $this->settings->vatAccountId($group['rateBp']);
				if ($vatAccount === null) {
					throw new \InvalidArgumentException($this->msg('Für den Steuersatz %s Prozent ist kein Umsatzsteuerkonto eingestellt.', [rtrim(rtrim(number_format($group['rateBp'] / 100, 2, ',', ''), '0'), ',')]));
				}
				$weights[$vatAccount] = ($weights[$vatAccount] ?? 0) + $group['taxCents'];
			}
		}
		foreach ($this->payments->findByInvoice((int)$invoice->getId()) as $payment) {
			foreach ($payment->partsMap() as $accountId => $cents) {
				if (isset($weights[$accountId])) {
					$weights[$accountId] -= $cents;
				}
			}
		}
		return array_map(static fn (int $w): int => max(0, $w), $weights);
	}

	/**
	 * Verteilt eine Zahlung auf die Erlöskonten, ohne dass ein Cent verloren geht.
	 *
	 * @return array<int, int> Konto-ID => Cent, nur Konten mit Betrag
	 * @throws \InvalidArgumentException wenn die Zahlung den offenen Rest übersteigt
	 */
	public function allocate(Invoice $invoice, int $amountCents): array {
		$remaining = $this->remainingWeights($invoice);
		if ($amountCents > array_sum($remaining)) {
			throw new \InvalidArgumentException($this->msg('Die Zahlung übersteigt den offenen Rest der Rechnung.'));
		}
		$accounts = array_keys($remaining);
		$shares = InvoiceCalculator::allocate($amountCents, array_values($remaining));
		$parts = [];
		foreach ($accounts as $i => $accountId) {
			if ($shares[$i] > 0) {
				$parts[(int)$accountId] = $shares[$i];
			}
		}
		return $parts;
	}

	/**
	 * Verbucht eine Zahlung im Zahlungsbuch.
	 *
	 * @param array<int, int> $parts Konto-ID => Cent
	 */
	public function record(Invoice $invoice, OpenItem $item, int $amountCents, ?int $bankTxId, ?int $journalId, array $parts, string $actor): InvoicePayment {
		$now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
		$payment = new InvoicePayment();
		$payment->setInvoiceId((int)$invoice->getId());
		$payment->setOpenItemId((int)$item->getId());
		$payment->setBankTxId($bankTxId);
		$payment->setJournalId($journalId);
		$payment->setAmountCents($amountCents);
		$payment->setParts(json_encode($parts) ?: null);
		$payment->setCreatedAt($now);
		$payment->setCreatedBy($actor);
		$payment = $this->payments->insert($payment);

		$item->setSettledCents($item->getSettledCents() + $amountCents);
		if ($item->remainingCents() === 0) {
			$item->setStatus('paid');
			$item->setPaidJournalId($journalId);
			$item->setSettledAt($now);
			$item->setSettledBy($actor);
			if ($invoice->getStatus() !== Invoice::STATUS_CANCELLED) {
				$invoice->setStatus(Invoice::STATUS_PAID);
				$invoice->setUpdatedAt($now);
				$this->invoices->update($invoice);
			}
		}
		$this->openItems->update($item);
		return $payment;
	}

	/**
	 * Nimmt die Zahlungen zurück, die über diesen Buchungssatz liefen. Wird
	 * aufgerufen, wenn ein Buchungssatz verschwindet (Zuordnung gelöst,
	 * Buchung gelöscht, Umsatz neu zugeordnet). Ohne Zahlung zu dem
	 * Buchungssatz passiert nichts.
	 */
	public function revertForJournal(int $journalId): void {
		foreach ($this->payments->findByJournal($journalId) as $payment) {
			$this->revert($payment);
		}
	}

	public function revert(InvoicePayment $payment): void {
		$now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
		try {
			$item = $this->openItems->find($payment->getOpenItemId());
			$item->setSettledCents(max(0, $item->getSettledCents() - $payment->getAmountCents()));
			if ($item->getStatus() === 'paid') {
				$item->setStatus('open');
				$item->setPaidJournalId(null);
				$item->setSettledAt(null);
				$item->setSettledBy(null);
			}
			$this->openItems->update($item);
		} catch (DoesNotExistException) {
		}
		try {
			$invoice = $this->invoices->find($payment->getInvoiceId());
			if ($invoice->getStatus() === Invoice::STATUS_PAID) {
				$invoice->setStatus($invoice->getSentAt() !== null ? Invoice::STATUS_SENT : Invoice::STATUS_ISSUED);
				$invoice->setUpdatedAt($now);
				$this->invoices->update($invoice);
			}
		} catch (DoesNotExistException) {
		}
		$this->payments->delete($payment);
	}

	/** @return InvoicePayment[] */
	public function forInvoice(int $invoiceId): array {
		return $this->payments->findByInvoice($invoiceId);
	}

	/** @param list<int|string> $params */
	private function msg(string $text, array $params = []): string {
		return $this->l10n !== null ? $this->l10n->t($text, $params) : vsprintf($text, $params);
	}
}
