<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\Debtor;
use OCA\Vereinsbuchhaltung\Db\DebtorMapper;
use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceDocumentMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceLine;
use OCA\Vereinsbuchhaltung\Db\InvoiceLineMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceMapper;
use OCA\Vereinsbuchhaltung\Db\InvoicePaymentMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Exception\ConflictException;
use OCA\Vereinsbuchhaltung\Exception\InvoiceNotIssuableException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IUserSession;

/**
 * Lebenszyklus einer Rechnung (Epic #168, Design: docs/rechnungsmodul-design.md).
 *
 * Eine Rechnung ist ein Dokument plus offener Posten, keine Buchung: das
 * Festschreiben legt den offenen Posten an, gebucht wird erst, wenn der
 * Bankumsatz der Zahlung zugeordnet wird.
 *
 * Entwurf: frei änderbar, ohne Nummer. Festschreiben ({@see issue()}): prüft
 * die Pflichtangaben nach § 14 Abs. 4 UStG, vergibt die Nummer, speichert
 * einen Schnappschuss der Empfängeranschrift und legt den offenen Posten an;
 * danach ändert sich der Inhalt nicht mehr (GoBD). Alles in einer
 * Transaktion: scheitert ein Schritt, bleibt auch der Zähler des Nummernkreises
 * unverändert und es entsteht keine Lücke.
 */
class InvoiceService {

	/** Einheitencodes nach UN/ECE Recommendation 20, die die Oberfläche anbietet. */
	public const UNIT_CODES = ['C62', 'HUR', 'DAY', 'MON', 'ANN', 'LS', 'KMT', 'MTR', 'MTK', 'LTR', 'KGM', 'P1'];

	public function __construct(
		private InvoiceMapper $invoices,
		private InvoiceLineMapper $lines,
		private InvoiceDocumentMapper $documents,
		private InvoicePaymentMapper $payments,
		private DebtorMapper $debtors,
		private OpenItemMapper $openItems,
		private AccountMapper $accounts,
		private NumberRangeService $numbers,
		private InvoiceSettings $settings,
		private TransactionRunner $transaction,
		private AuditService $audit,
		private IUserSession $userSession,
		private ?IL10N $l10n = null,
	) {
	}

	// ------------------------------------------------------------------
	// Lesen
	// ------------------------------------------------------------------

	/**
	 * Rechnungsliste mit Empfängername und Zahlungsstand.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function list(?string $status = null, ?int $year = null, ?int $debtorId = null): array {
		$invoices = $this->invoices->findFiltered($status, $year, $debtorId);
		$items = $this->openItems->findByIds(array_values(array_filter(array_map(static fn (Invoice $i) => $i->getOpenItemId(), $invoices))));
		$debtors = $this->debtors->findByIds(array_values(array_filter(array_map(static fn (Invoice $i) => $i->getDebtorId(), $invoices))));
		$out = [];
		foreach ($invoices as $invoice) {
			$out[] = $this->decorate($invoice, $items[$invoice->getOpenItemId() ?? 0] ?? null, $debtors[$invoice->getDebtorId() ?? 0] ?? null);
		}
		return $out;
	}

	/**
	 * Rechnung mit Positionen, Dokumenten, offenem Posten und – für Entwürfe –
	 * der Prüfliste zum Festschreiben.
	 *
	 * @return array<string, mixed>
	 * @throws DoesNotExistException
	 */
	public function detail(int $id): array {
		$invoice = $this->invoices->find($id);
		$lines = $this->lines->findByInvoice($id);
		$item = $invoice->getOpenItemId() !== null ? ($this->openItems->findByIds([$invoice->getOpenItemId()])[$invoice->getOpenItemId()] ?? null) : null;
		$debtor = $this->liveDebtor($invoice);
		$out = $this->decorate($invoice, $item, $debtor);
		$out['lines'] = $lines;
		$out['taxBreakdown'] = InvoiceCalculator::totals(array_map(static fn (InvoiceLine $l): array => [
			'lineNetCents' => $l->getLineNetCents(),
			'taxRateBp' => $l->getTaxRateBp(),
			'taxCategory' => $l->getTaxCategory(),
		], $lines), $invoice->getTaxMode())['taxBreakdown'];
		$out['documents'] = $this->documents->findByInvoice($id);
		$out['payments'] = $this->payments->findByInvoice($id);
		$credit = $invoice->isCredit() ? null : $this->invoices->findCreditFor($id);
		$out['creditInvoiceId'] = $credit?->getId();
		if ($invoice->isCredit() && $invoice->getPrecedingInvoiceId() !== null) {
			try {
				$original = $this->invoices->find($invoice->getPrecedingInvoiceId());
				$originalItem = $original->getOpenItemId() !== null ? ($this->openItems->findByIds([$original->getOpenItemId()])[$original->getOpenItemId()] ?? null) : null;
				$out['precedingNumber'] = $original->getNumber();
				$out['precedingIssueDate'] = $original->getIssueDate();
				// Was der Verein schon erhalten hat und nun zurückzahlen muss.
				$out['refundDueCents'] = $originalItem?->getSettledCents() ?? 0;
			} catch (DoesNotExistException) {
			}
		}
		if ($invoice->isDraft()) {
			$out['checklist'] = $this->checklist($invoice, $lines, $debtor, new \DateTimeImmutable($invoice->getIssueDate() ?? 'today'));
		}
		return $out;
	}

	/**
	 * Prüfliste für das Festschreiben. Jeder Punkt mit `required` muss erfüllt
	 * sein; Hinweise ohne `required` sind Empfehlungen.
	 *
	 * @param InvoiceLine[] $lines
	 * @return list<array{key: string, label: string, ok: bool, required: bool}>
	 */
	public function checklist(Invoice $invoice, array $lines, ?Debtor $debtor, \DateTimeInterface $issueDate): array {
		$missing = $this->settings->missingIssuerFields();
		$items = [];
		$add = function (string $key, string $label, bool $ok, bool $required = true) use (&$items): void {
			$items[] = ['key' => $key, 'label' => $label, 'ok' => $ok, 'required' => $required];
		};

		$add('issuer', $this->msg('Name und Anschrift des Vereins'), array_diff_key($missing, ['club_tax_number' => true]) === []);
		$add('taxId', $this->msg('Steuernummer oder USt-IdNr. des Vereins'), !isset($missing['club_tax_number']));
		$add('debtor', $this->msg('Empfänger mit Name und vollständiger Anschrift'), $debtor !== null
			&& trim($debtor->getName()) !== ''
			&& trim((string)$debtor->getStreet()) !== ''
			&& trim((string)$debtor->getPostalCode()) !== ''
			&& trim((string)$debtor->getCity()) !== '');
		$add('delivery', $this->msg('Leistungsdatum oder Leistungszeitraum'), $invoice->getDeliveryDate() !== null
			|| ($invoice->getPeriodStart() !== null && $invoice->getPeriodEnd() !== null));

		$linesOk = $lines !== [];
		foreach ($lines as $line) {
			if (trim($line->getName()) === '' || $line->getQuantityE4() === 0 || $line->getAccountId() === null) {
				$linesOk = false;
			}
		}
		$add('lines', $this->msg('Mindestens eine Position mit Bezeichnung, Menge und Erlöskonto'), $linesOk);

		$amountOk = $invoice->isCredit() ? $invoice->getGrossCents() < 0 : $invoice->getGrossCents() > 0;
		$add('amount', $invoice->isCredit() ? $this->msg('Betrag der Stornorechnung ist negativ') : $this->msg('Rechnungsbetrag muss größer als 0 € sein'), $amountOk);

		if ($invoice->getTaxMode() === Invoice::TAX_EXEMPT) {
			$add('exemption', $this->msg('Hinweis auf die Steuerbefreiung (z. B. § 4 Nr. 22 UStG)'), trim((string)$invoice->getExemptionText()) !== '');
		}
		if ($invoice->getTaxMode() === Invoice::TAX_REGULAR) {
			$rates = [];
			$hasExemptLine = false;
			foreach ($lines as $line) {
				if ($line->getTaxCategory() === InvoiceLine::TAX_STANDARD) {
					$rates[$line->getTaxRateBp()] = true;
				}
				if ($line->getTaxCategory() === InvoiceLine::TAX_EXEMPT) {
					$hasExemptLine = true;
				}
			}
			$missingVat = array_filter(array_keys($rates), fn (int $rate): bool => $this->settings->vatAccountId($rate) === null);
			$add('vatAccounts', $this->msg('Umsatzsteuerkonto für jeden verwendeten Steuersatz (Einstellungen)'), $missingVat === []);
			if ($hasExemptLine) {
				$add('exemption', $this->msg('Hinweis auf die Steuerbefreiung (z. B. § 4 Nr. 22 UStG)'), trim((string)$invoice->getExemptionText()) !== '');
			}
		}
		$add('bank', $this->msg('Bankverbindung für die Überweisung'), $this->settings->accountId() !== null, false);
		return $items;
	}

	// ------------------------------------------------------------------
	// Entwurf
	// ------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $data Kopffelder und `lines`
	 * @throws \InvalidArgumentException bei ungültigen Angaben
	 */
	public function createDraft(array $data): Invoice {
		return $this->transaction->run(function () use ($data): Invoice {
			$now = $this->now();
			$invoice = new Invoice();
			$invoice->setKind(Invoice::KIND_INVOICE);
			$invoice->setStatus(Invoice::STATUS_DRAFT);
			$invoice->setTaxMode($this->settings->taxMode());
			$invoice->setCurrency('EUR');
			$invoice->setCreatedBy($this->actor());
			$invoice->setCreatedAt($now);
			$invoice->setUpdatedAt($now);
			$all = $this->settings->all();
			$invoice->setPaymentTerms($this->blankToNull((string)$all[InvoiceSettings::SETTING_PAYMENT_TERMS]));
			$invoice->setNoteHead($this->blankToNull((string)$all[InvoiceSettings::SETTING_NOTE_HEAD]));
			$invoice->setNoteFoot($this->blankToNull((string)$all[InvoiceSettings::SETTING_NOTE_FOOT]));
			$invoice->setExemptionText($this->blankToNull((string)$all[InvoiceSettings::SETTING_EXEMPTION_TEXT]));
			$this->applyHeader($invoice, $data);
			$invoice->setNetCents(0);
			$invoice->setTaxCents(0);
			$invoice->setGrossCents(0);
			$invoice = $this->invoices->insert($invoice);
			$this->saveLines($invoice, (array)($data['lines'] ?? []));
			$this->audit->log('Rechnungsentwurf angelegt', 'invoice', $invoice->getId());
			return $invoice;
		});
	}

	/**
	 * @param array<string, mixed> $data nur die gesendeten Kopffelder werden geändert; `lines` ersetzt alle Positionen
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException wenn die Rechnung kein Entwurf mehr ist oder Angaben ungültig sind
	 */
	public function updateDraft(int $id, array $data): Invoice {
		return $this->transaction->run(function () use ($id, $data): Invoice {
			$invoice = $this->invoices->find($id);
			$this->assertDraft($invoice);
			$this->applyHeader($invoice, $data);
			$invoice->setUpdatedAt($this->now());
			if (array_key_exists('lines', $data)) {
				$this->saveLines($invoice, (array)$data['lines']);
			} else {
				$this->recalculate($invoice, $this->lines->findByInvoice($id));
				$this->invoices->update($invoice);
			}
			return $this->invoices->find($id);
		});
	}

	/**
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException wenn die Rechnung kein Entwurf mehr ist
	 */
	public function deleteDraft(int $id): void {
		$this->transaction->run(function () use ($id): void {
			$invoice = $this->invoices->find($id);
			$this->assertDraft($invoice);
			$this->lines->deleteByInvoice($id);
			$this->invoices->delete($invoice);
			$this->audit->log('Rechnungsentwurf gelöscht', 'invoice', $id);
		});
	}

	/**
	 * Kopiert eine Rechnung (auch eine festgeschriebene) als neuen Entwurf:
	 * ohne Nummer und Datum, mit denselben Positionen und Texten.
	 *
	 * @throws DoesNotExistException
	 */
	public function duplicate(int $id): Invoice {
		return $this->transaction->run(function () use ($id): Invoice {
			$source = $this->invoices->find($id);
			$lines = [];
			foreach ($this->lines->findByInvoice($id) as $line) {
				$lines[] = [
					'name' => $line->getName(),
					'description' => $line->getDescription(),
					'quantityE4' => $source->isCredit() ? -$line->getQuantityE4() : $line->getQuantityE4(),
					'unitCode' => $line->getUnitCode(),
					'unitPriceCents' => $line->getUnitPriceCents(),
					'discountBp' => $line->getDiscountBp(),
					'taxCategory' => $line->getTaxCategory(),
					'taxRateBp' => $line->getTaxRateBp(),
					'taxExemptionText' => $line->getTaxExemptionText(),
					'accountId' => $line->getAccountId(),
					'costCenterId' => $line->getCostCenterId(),
				];
			}
			$draft = $this->createDraft([
				'debtorId' => $source->getDebtorId(),
				'taxMode' => in_array($source->getTaxMode(), InvoiceSettings::AVAILABLE_TAX_MODES, true) ? $source->getTaxMode() : null,
				'exemptionText' => $source->getExemptionText(),
				'buyerReference' => $source->getBuyerReference(),
				'orderReference' => $source->getOrderReference(),
				'paymentTerms' => $source->getPaymentTerms(),
				'noteHead' => $source->getNoteHead(),
				'noteFoot' => $source->getNoteFoot(),
				'lines' => $lines,
			]);
			$this->audit->log('Rechnung dupliziert', 'invoice', $draft->getId(), ['source' => $id]);
			return $draft;
		});
	}

	// ------------------------------------------------------------------
	// Festschreiben
	// ------------------------------------------------------------------

	/**
	 * Schreibt einen Entwurf fest.
	 *
	 * @param string|null $issueDate Rechnungsdatum (Y-m-d); ohne Angabe das im Entwurf gesetzte, sonst heute
	 * @throws DoesNotExistException
	 * @throws InvoiceNotIssuableException wenn Pflichtangaben fehlen
	 * @throws ConflictException wenn die Rechnung gerade von jemand anderem festgeschrieben wird
	 * @throws \InvalidArgumentException wenn die Rechnung kein Entwurf ist
	 */
	public function issue(int $id, ?string $issueDate = null): Invoice {
		return $this->transaction->run(function () use ($id, $issueDate): Invoice {
			$invoice = $this->invoices->find($id);
			$this->assertDraft($invoice);
			$lines = $this->lines->findByInvoice($id);

			$date = $this->normalizeDate($issueDate) ?? $invoice->getIssueDate() ?? (new \DateTimeImmutable())->format('Y-m-d');
			// Eine Stornorechnung trägt den Schnappschuss der Ursprungsrechnung: sie
			// storniert genau die Anschrift, an die diese ging, auch wenn der
			// Empfänger inzwischen geändert oder gelöscht wurde.
			$snapshot = $invoice->debtorSnapshotData();
			$debtor = $snapshot !== null ? $this->debtorFromSnapshot($snapshot) : $this->liveDebtor($invoice);
			$checklist = $this->checklist($invoice, $lines, $debtor, new \DateTimeImmutable($date));
			$problems = [];
			foreach ($checklist as $item) {
				if ($item['required'] && !$item['ok']) {
					$problems[] = $item['label'];
				}
			}
			if ($problems !== []) {
				throw new InvoiceNotIssuableException($this->msg('Die Rechnung ist noch nicht vollständig.'), $problems);
			}
			if ($debtor === null) {
				throw new InvoiceNotIssuableException($this->msg('Die Rechnung hat keinen Empfänger.'), [$this->msg('Empfänger')]);
			}

			// Ab hier gehört der Entwurf diesem Aufruf; ein gleichzeitiger zweiter
			// kommt nicht durch und verbraucht keine Nummer.
			$now = $this->now();
			if (!$this->invoices->claimIssue($id, $now)) {
				throw new ConflictException($this->msg('Die Rechnung wurde gerade von jemand anderem festgeschrieben.'));
			}

			$issued = $this->numbers->issue($invoice->getKind(), new \DateTimeImmutable($date));
			$dueDate = $invoice->getDueDate();
			if ($dueDate === null) {
				// Eine Stornorechnung wird nicht bezahlt, ihr Ziel ist ihr Datum.
				$days = $invoice->isCredit() ? 0 : $this->settings->defaultDueDays();
				$dueDate = (new \DateTimeImmutable($date))->modify('+' . $days . ' days')->format('Y-m-d');
			}
			if ($dueDate < $date) {
				throw new InvoiceNotIssuableException($this->msg('Das Zahlungsziel liegt vor dem Rechnungsdatum.'), [$this->msg('Zahlungsziel')]);
			}
			if ($invoice->getTaxMode() === Invoice::TAX_SMALL_BUSINESS && trim((string)$invoice->getExemptionText()) === '') {
				$invoice->setExemptionText(InvoiceSettings::SMALL_BUSINESS_NOTICE);
			}

			$invoice->setNumber($issued['number']);
			$invoice->setNumberRangeId($issued['rangeId']);
			$invoice->setIssueDate($date);
			$invoice->setDueDate($dueDate);
			if ($snapshot === null) {
				$invoice->setDebtorSnapshot(json_encode($debtor->toSnapshot(), JSON_UNESCAPED_UNICODE));
			}
			$invoice->setStatus(Invoice::STATUS_ISSUED);
			$invoice->setIssuedAt($now);
			$invoice->setIssuedBy($this->actor());
			$invoice->setUpdatedAt($now);

			// Die Stornorechnung gleicht die Ursprungsrechnung aus und wird selbst
			// nicht bezahlt: sie hat keinen offenen Posten.
			if (!$invoice->isCredit()) {
				$item = $this->createOpenItem($invoice, $debtor, $lines);
				$invoice->setOpenItemId((int)$item->getId());
			}
			$this->invoices->update($invoice);

			$this->audit->log('Rechnung festgeschrieben', 'invoice', $id, [
				'number' => $issued['number'],
				'gross' => $invoice->getGrossCents() / 100,
			]);
			return $this->invoices->find($id);
		});
	}

	/**
	 * Storniert eine festgeschriebene Rechnung mit einer Stornorechnung.
	 *
	 * Die Stornorechnung (eigener Nummernkreis, Verweis auf die Ursprungsrechnung)
	 * kehrt alle Positionen um, trägt dieselbe Anschrift wie das Original und
	 * wird sofort festgeschrieben. Das Original gilt danach als storniert, sein
	 * offener Posten ebenso. Ist die Rechnung schon ganz oder teilweise bezahlt,
	 * bleibt die Zahlung bestehen: Die Rückzahlung ordnen Sie wie jede andere
	 * Auszahlung dem Erlöskonto zu, der Betrag steht im Detail der
	 * Stornorechnung.
	 *
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException wenn die Rechnung nicht storniert werden kann oder eine Begründung fehlt
	 */
	public function cancel(int $id, string $reason): Invoice {
		return $this->transaction->run(function () use ($id, $reason): Invoice {
			$original = $this->invoices->find($id);
			$reason = trim($reason);
			if ($original->isCredit() || !$original->isIssued() || $original->getStatus() === Invoice::STATUS_CANCELLED) {
				throw new \InvalidArgumentException($this->msg('Nur eine festgeschriebene, noch nicht stornierte Rechnung lässt sich stornieren.'));
			}
			if (mb_strlen($reason) < 3) {
				throw new \InvalidArgumentException($this->msg('Bitte geben Sie eine Begründung für die Stornierung an.'));
			}
			if ($this->invoices->findCreditFor($id) !== null) {
				throw new \InvalidArgumentException($this->msg('Zu dieser Rechnung gibt es bereits eine Stornorechnung.'));
			}

			$now = $this->now();
			$credit = new Invoice();
			$credit->setKind(Invoice::KIND_CREDIT);
			$credit->setStatus(Invoice::STATUS_DRAFT);
			$credit->setPrecedingInvoiceId($id);
			$credit->setDebtorId($original->getDebtorId());
			$credit->setDebtorSnapshot($original->getDebtorSnapshot());
			$credit->setTaxMode($original->getTaxMode());
			$credit->setExemptionText($original->getExemptionText());
			$credit->setBuyerReference($original->getBuyerReference());
			$credit->setOrderReference($original->getOrderReference());
			$credit->setDeliveryDate($original->getDeliveryDate());
			$credit->setPeriodStart($original->getPeriodStart());
			$credit->setPeriodEnd($original->getPeriodEnd());
			$credit->setNoteHead($this->msg('Storno zu Rechnung %s vom %s.', [(string)$original->getNumber(), (string)$original->getIssueDate()]));
			$credit->setNoteFoot($reason);
			$credit->setCurrency($original->getCurrency());
			$credit->setNetCents(0);
			$credit->setTaxCents(0);
			$credit->setGrossCents(0);
			$credit->setCreatedBy($this->actor());
			$credit->setCreatedAt($now);
			$credit->setUpdatedAt($now);
			$credit = $this->invoices->insert($credit);

			$reversed = [];
			foreach ($this->lines->findByInvoice($id) as $line) {
				$copy = new InvoiceLine();
				$copy->setInvoiceId((int)$credit->getId());
				$copy->setPosition($line->getPosition());
				$copy->setName($line->getName());
				$copy->setDescription($line->getDescription());
				$copy->setQuantityE4(-$line->getQuantityE4());
				$copy->setUnitCode($line->getUnitCode());
				$copy->setUnitPriceCents($line->getUnitPriceCents());
				$copy->setDiscountBp($line->getDiscountBp());
				$copy->setTaxCategory($line->getTaxCategory());
				$copy->setTaxRateBp($line->getTaxRateBp());
				$copy->setTaxExemptionText($line->getTaxExemptionText());
				$copy->setLineNetCents(-$line->getLineNetCents());
				$copy->setAccountId($line->getAccountId());
				$copy->setCostCenterId($line->getCostCenterId());
				$copy->setDeliveryDate($line->getDeliveryDate());
				$this->lines->insert($copy);
				$reversed[] = $copy;
			}
			$this->recalculate($credit, $reversed);
			$this->invoices->update($credit);

			$credit = $this->issue((int)$credit->getId());

			$original->setStatus(Invoice::STATUS_CANCELLED);
			$original->setCancelledAt($now);
			$original->setCancelReason($reason);
			$original->setUpdatedAt($now);
			$this->invoices->update($original);
			if ($original->getOpenItemId() !== null) {
				$items = $this->openItems->findByIds([$original->getOpenItemId()]);
				$item = $items[$original->getOpenItemId()] ?? null;
				if ($item !== null) {
					$item->setStatus('cancelled');
					$item->setCancelledAt($now);
					$item->setCancelledReason($reason);
					$this->openItems->update($item);
				}
			}

			$this->audit->log('Rechnung storniert', 'invoice', $id, [
				'number' => $original->getNumber(),
				'credit' => $credit->getNumber(),
				'reason' => $reason,
			]);
			return $credit;
		});
	}

	/**
	 * Vermerkt den Versand per Mail. Eine bezahlte Rechnung bleibt bezahlt.
	 *
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException wenn die Rechnung noch ein Entwurf oder storniert ist
	 */
	public function markSent(int $id): Invoice {
		$invoice = $this->invoices->find($id);
		if (!$invoice->isIssued() || $invoice->getStatus() === Invoice::STATUS_CANCELLED) {
			throw new \InvalidArgumentException($this->msg('Nur festgeschriebene Rechnungen lassen sich versenden.'));
		}
		$invoice->setSentAt($this->now());
		if ($invoice->getStatus() === Invoice::STATUS_ISSUED) {
			$invoice->setStatus(Invoice::STATUS_SENT);
		}
		$this->invoices->update($invoice);
		$this->audit->log('Rechnung versendet', 'invoice', $id, ['number' => $invoice->getNumber()]);
		return $invoice;
	}

	// ------------------------------------------------------------------
	// Intern
	// ------------------------------------------------------------------

	/**
	 * Der offene Posten zur Rechnung: Betrag in Höhe des Rechnungsbetrags, mit
	 * der Rechnungsnummer als Referenz für den Bankabgleich.
	 *
	 * @param InvoiceLine[] $lines
	 */
	private function createOpenItem(Invoice $invoice, Debtor $debtor, array $lines): OpenItem {
		$accountIds = array_values(array_unique(array_filter(array_map(static fn (InvoiceLine $l) => $l->getAccountId(), $lines))));
		$item = new OpenItem();
		$item->setDebtor($debtor->getName());
		$item->setDescription($this->msg('Rechnung %s', [(string)$invoice->getNumber()]));
		$item->setAmountCents($invoice->getGrossCents());
		$item->setDueDate($invoice->getDueDate());
		$item->setStatus('open');
		$item->setAccountId(count($accountIds) === 1 ? $accountIds[0] : null);
		$item->setCreatedAt($this->now());
		$item->setInvoiceId((int)$invoice->getId());
		$item->setReference($invoice->getNumber());
		$item->setSettledCents(0);
		return $this->openItems->insert($item);
	}

	private function assertDraft(Invoice $invoice): void {
		if (!$invoice->isDraft() || $invoice->isIssued()) {
			throw new \InvalidArgumentException($this->msg('Festgeschriebene Rechnungen lassen sich nicht mehr ändern. Bitte stornieren oder duplizieren.'));
		}
	}

	/**
	 * Baut aus dem Schnappschuss einer festgeschriebenen Rechnung einen
	 * (nicht gespeicherten) Empfänger.
	 *
	 * @param array<string, mixed> $snapshot
	 */
	private function debtorFromSnapshot(array $snapshot): Debtor {
		$debtor = new Debtor();
		$debtor->setKind((string)($snapshot['kind'] ?? Debtor::KIND_ORGANISATION));
		$debtor->setName((string)($snapshot['name'] ?? ''));
		$debtor->setName2($snapshot['name2'] ?? null);
		$debtor->setStreet($snapshot['street'] ?? null);
		$debtor->setPostalCode($snapshot['postalCode'] ?? null);
		$debtor->setCity($snapshot['city'] ?? null);
		$debtor->setCountry((string)($snapshot['country'] ?? 'DE'));
		$debtor->setEmail($snapshot['email'] ?? null);
		$debtor->setVatId($snapshot['vatId'] ?? null);
		$debtor->setLeitwegId($snapshot['leitwegId'] ?? null);
		$debtor->setCustomerNo($snapshot['customerNo'] ?? null);
		return $debtor;
	}

	private function liveDebtor(Invoice $invoice): ?Debtor {
		if ($invoice->getDebtorId() === null) {
			return null;
		}
		try {
			return $this->debtors->find($invoice->getDebtorId());
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decorate(Invoice $invoice, ?OpenItem $item, ?Debtor $debtor): array {
		$data = $invoice->jsonSerialize();
		$snapshot = $invoice->debtorSnapshotData();
		$data['debtor'] = $snapshot ?? $debtor?->toSnapshot();
		$data['debtorName'] = $snapshot['name'] ?? $debtor?->getName();
		$settled = $item?->getSettledCents() ?? 0;
		$remaining = $item !== null ? $item->remainingCents() : 0;
		$data['settledCents'] = $settled;
		$data['remainingCents'] = $invoice->getStatus() === Invoice::STATUS_CANCELLED ? 0 : $remaining;
		$data['overdue'] = $item !== null
			&& in_array($invoice->getStatus(), [Invoice::STATUS_ISSUED, Invoice::STATUS_SENT], true)
			&& $remaining > 0
			&& $invoice->getDueDate() !== null
			&& $invoice->getDueDate() < date('Y-m-d');
		return $data;
	}

	/** @param array<string, mixed> $data */
	private function applyHeader(Invoice $invoice, array $data): void {
		foreach (['issueDate' => 'setIssueDate', 'deliveryDate' => 'setDeliveryDate', 'periodStart' => 'setPeriodStart', 'periodEnd' => 'setPeriodEnd', 'dueDate' => 'setDueDate'] as $key => $setter) {
			if (array_key_exists($key, $data)) {
				$invoice->$setter($this->normalizeDate($data[$key]));
			}
		}
		if ($invoice->getPeriodStart() !== null && $invoice->getPeriodEnd() !== null && $invoice->getPeriodStart() > $invoice->getPeriodEnd()) {
			throw new \InvalidArgumentException($this->msg('Der Leistungszeitraum endet vor seinem Beginn.'));
		}
		if ($invoice->getIssueDate() !== null && $invoice->getDueDate() !== null && $invoice->getDueDate() < $invoice->getIssueDate()) {
			throw new \InvalidArgumentException($this->msg('Das Zahlungsziel liegt vor dem Rechnungsdatum.'));
		}

		if (array_key_exists('debtorId', $data)) {
			$debtorId = $data['debtorId'] !== null && $data['debtorId'] !== '' ? (int)$data['debtorId'] : null;
			if ($debtorId !== null) {
				try {
					$this->debtors->find($debtorId);
				} catch (DoesNotExistException) {
					throw new \InvalidArgumentException($this->msg('Der Empfänger existiert nicht.'));
				}
			}
			$invoice->setDebtorId($debtorId);
		}

		if (array_key_exists('taxMode', $data) && $data['taxMode'] !== null) {
			$mode = (string)$data['taxMode'];
			if (!in_array($mode, InvoiceSettings::AVAILABLE_TAX_MODES, true)) {
				throw new \InvalidArgumentException($this->msg('Dieser Steuer-Modus ist noch nicht verfügbar.'));
			}
			$invoice->setTaxMode($mode);
		}

		foreach ([
			'exemptionText' => [500, 'setExemptionText'],
			'buyerReference' => [255, 'setBuyerReference'],
			'orderReference' => [255, 'setOrderReference'],
			'paymentTerms' => [1000, 'setPaymentTerms'],
			'noteHead' => [2000, 'setNoteHead'],
			'noteFoot' => [2000, 'setNoteFoot'],
		] as $key => [$max, $setter]) {
			if (array_key_exists($key, $data)) {
				$invoice->$setter($this->blankToNull(mb_substr(trim((string)($data[$key] ?? '')), 0, $max)));
			}
		}
	}

	/**
	 * Ersetzt die Positionen eines Entwurfs und rechnet die Summen neu.
	 *
	 * @param array<int|string, mixed> $rows
	 */
	private function saveLines(Invoice $invoice, array $rows): void {
		$entities = $this->buildLines($invoice, $rows);
		$this->lines->deleteByInvoice((int)$invoice->getId());
		foreach ($entities as $line) {
			$this->lines->insert($line);
		}
		$this->recalculate($invoice, $entities);
		$this->invoices->update($invoice);
	}

	/**
	 * Prüft Positionen so, wie der Editor sie beim Speichern prüft, ohne etwas zu speichern.
	 * Gedacht für wiederkehrende Rechnungen, deren Positionen lange vor dem Lauf feststehen.
	 *
	 * @param array<int|string, mixed> $rows
	 * @throws \InvalidArgumentException bei ungültigen Angaben oder wenn keine Position übrig bleibt
	 */
	public function validateLines(array $rows): void {
		$probe = new Invoice();
		$probe->setId(0);
		$probe->setKind(Invoice::KIND_INVOICE);
		$probe->setTaxMode($this->settings->taxMode());
		if ($this->buildLines($probe, $rows) === []) {
			throw new \InvalidArgumentException($this->msg('Mindestens eine Position ist nötig.'));
		}
	}

	/**
	 * @param array<int|string, mixed> $rows
	 * @return list<InvoiceLine>
	 */
	private function buildLines(Invoice $invoice, array $rows): array {
		$lines = [];
		$position = 0;
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$name = mb_substr(trim((string)($row['name'] ?? '')), 0, 255);
			$description = $this->blankToNull(mb_substr(trim((string)($row['description'] ?? '')), 0, 2000));
			$priceCents = $this->parsePrice($row);
			if ($name === '' && $description === null && $priceCents === 0) {
				continue; // leere Zeile des Editors
			}
			$line = new InvoiceLine();
			$line->setInvoiceId((int)$invoice->getId());
			$line->setPosition(++$position);
			$line->setName($name);
			$line->setDescription($description);

			$quantityE4 = $this->parseQuantity($row);
			// Stornorechnungen tragen negative Mengen, die nur der Service selbst setzt.
			if ($quantityE4 === 0 || (!$invoice->isCredit() && $quantityE4 < 0)) {
				throw new \InvalidArgumentException($this->msg('Position %d: Die Menge muss größer als 0 sein.', [$position]));
			}
			if (abs($quantityE4) > InvoiceCalculator::MAX_QUANTITY_E4) {
				throw new \InvalidArgumentException($this->msg('Position %d: Die Menge ist zu groß.', [$position]));
			}
			$line->setQuantityE4($quantityE4);

			$unit = (string)($row['unitCode'] ?? 'C62');
			if (!in_array($unit, self::UNIT_CODES, true)) {
				throw new \InvalidArgumentException($this->msg('Position %d: Unbekannte Einheit.', [$position]));
			}
			$line->setUnitCode($unit);

			if (abs($priceCents) > InvoiceCalculator::MAX_UNIT_PRICE_CENTS) {
				throw new \InvalidArgumentException($this->msg('Position %d: Der Einzelpreis ist zu groß.', [$position]));
			}
			$line->setUnitPriceCents($priceCents);

			$discountBp = $this->parseDiscount($row);
			if ($discountBp < 0 || $discountBp > InvoiceCalculator::MAX_DISCOUNT_BP) {
				throw new \InvalidArgumentException($this->msg('Position %d: Der Rabatt muss zwischen 0 und 100 Prozent liegen.', [$position]));
			}
			$line->setDiscountBp($discountBp);

			$this->applyTax($invoice, $line, $row, $position);

			$accountId = isset($row['accountId']) && $row['accountId'] !== '' ? (int)$row['accountId'] : null;
			if ($accountId !== null) {
				$this->assertRevenueAccount($accountId, $position);
			}
			$line->setAccountId($accountId);
			$line->setCostCenterId(isset($row['costCenterId']) && $row['costCenterId'] !== '' ? (int)$row['costCenterId'] : null);
			$line->setDeliveryDate($this->normalizeDate($row['deliveryDate'] ?? null));
			$line->setLineNetCents(InvoiceCalculator::lineNet($quantityE4, $priceCents, $discountBp));
			$lines[] = $line;
		}
		return $lines;
	}

	/**
	 * Steuer der Position. Ohne Regelbesteuerung gibt es keinen Steuerausweis:
	 * befreit, Satz 0, der Hinweistext steht an der Rechnung. Bei
	 * Regelbesteuerung trägt jede Position ihre Kategorie (S Regelsatz, E
	 * steuerbefreit, Z Nullsatz) und ihren Satz in Basispunkten.
	 *
	 * @param array<string, mixed> $row
	 */
	private function applyTax(Invoice $invoice, InvoiceLine $line, array $row, int $position): void {
		if ($invoice->getTaxMode() !== Invoice::TAX_REGULAR) {
			$line->setTaxCategory(InvoiceLine::TAX_EXEMPT);
			$line->setTaxRateBp(0);
			$line->setTaxExemptionText(null);
			return;
		}
		$category = strtoupper(trim((string)($row['taxCategory'] ?? InvoiceLine::TAX_STANDARD)));
		if (!in_array($category, [InvoiceLine::TAX_STANDARD, InvoiceLine::TAX_EXEMPT, InvoiceLine::TAX_ZERO], true)) {
			throw new \InvalidArgumentException($this->msg('Position %d: Diese Steuerkategorie ist nicht möglich.', [$position]));
		}
		$rateBp = 0;
		if ($category === InvoiceLine::TAX_STANDARD) {
			if (isset($row['taxRateBp']) && $row['taxRateBp'] !== '') {
				$rateBp = (int)$row['taxRateBp'];
			} elseif (isset($row['taxRatePercent']) && $row['taxRatePercent'] !== '') {
				$rateBp = (int)round($this->parseDecimal($row['taxRatePercent']) * 100);
			} else {
				$rateBp = $this->settings->defaultRates()['wirtschaftlich'];
			}
			if ($rateBp < 1 || $rateBp > 2500) {
				throw new \InvalidArgumentException($this->msg('Position %d: Der Steuersatz muss zwischen 0,01 und 25 Prozent liegen.', [$position]));
			}
		}
		$line->setTaxCategory($category);
		$line->setTaxRateBp($rateBp);
		$text = $this->blankToNull(mb_substr(trim((string)($row['taxExemptionText'] ?? '')), 0, 255));
		$line->setTaxExemptionText($category === InvoiceLine::TAX_EXEMPT ? $text : null);
	}

	/**
	 * @param InvoiceLine[] $lines
	 */
	private function recalculate(Invoice $invoice, array $lines): void {
		$totals = InvoiceCalculator::totals(array_map(static fn (InvoiceLine $l): array => [
			'lineNetCents' => $l->getLineNetCents(),
			'taxRateBp' => $l->getTaxRateBp(),
			'taxCategory' => $l->getTaxCategory(),
		], $lines), $invoice->getTaxMode());
		$invoice->setNetCents($totals['netCents']);
		$invoice->setTaxCents($totals['taxCents']);
		$invoice->setGrossCents($totals['grossCents']);
	}

	private function assertRevenueAccount(int $accountId, int $position): void {
		try {
			$account = $this->accounts->find($accountId, Application::BOOK);
		} catch (DoesNotExistException) {
			throw new \InvalidArgumentException($this->msg('Position %d: Das Erlöskonto existiert nicht.', [$position]));
		}
		if ($account->getType() !== 'income' || $account->getIsBank() || !$account->getActive()) {
			throw new \InvalidArgumentException($this->msg('Position %d: Als Erlöskonto ist nur ein aktives Einnahmenkonto möglich.', [$position]));
		}
	}

	/** @param array<string, mixed> $row */
	private function parsePrice(array $row): int {
		if (isset($row['unitPriceCents']) && $row['unitPriceCents'] !== '') {
			return (int)$row['unitPriceCents'];
		}
		if (isset($row['unitPrice']) && $row['unitPrice'] !== '') {
			return (int)round($this->parseDecimal($row['unitPrice']) * 100);
		}
		return 0;
	}

	/** @param array<string, mixed> $row */
	private function parseQuantity(array $row): int {
		if (isset($row['quantityE4']) && $row['quantityE4'] !== '') {
			return (int)$row['quantityE4'];
		}
		if (isset($row['quantity']) && $row['quantity'] !== '') {
			return (int)round($this->parseDecimal($row['quantity']) * 10000);
		}
		return InvoiceLine::QUANTITY_ONE;
	}

	/** @param array<string, mixed> $row */
	private function parseDiscount(array $row): int {
		if (isset($row['discountBp']) && $row['discountBp'] !== '') {
			return (int)$row['discountBp'];
		}
		if (isset($row['discountPercent']) && $row['discountPercent'] !== '') {
			return (int)round($this->parseDecimal($row['discountPercent']) * 100);
		}
		return 0;
	}

	private function parseDecimal(mixed $value): float {
		if (is_int($value) || is_float($value)) {
			return (float)$value;
		}
		$text = str_replace(',', '.', trim((string)$value));
		if (!is_numeric($text)) {
			throw new \InvalidArgumentException($this->msg('„%s“ ist keine Zahl.', [(string)$value]));
		}
		return (float)$text;
	}

	private function normalizeDate(mixed $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		$text = (string)$value;
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) !== 1 || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			throw new \InvalidArgumentException($this->msg('Ungültiges Datum: %s', [$text]));
		}
		return $text;
	}

	private function blankToNull(string $value): ?string {
		return $value === '' ? null : $value;
	}

	private function now(): string {
		return (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
	}

	private function actor(): string {
		return $this->userSession->getUser()?->getUID() ?? 'system';
	}

	/** @param list<int|string> $params */
	private function msg(string $text, array $params = []): string {
		return $this->l10n !== null ? $this->l10n->t($text, $params) : vsprintf($text, $params);
	}
}
