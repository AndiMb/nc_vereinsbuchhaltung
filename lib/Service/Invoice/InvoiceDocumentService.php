<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Invoice;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\DebtorMapper;
use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceDocument;
use OCA\Vereinsbuchhaltung\Db\InvoiceDocumentMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceLineMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceMapper;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\InvoiceSettings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Erzeugt die Dokumente zu einer Rechnung und legt sie ab.
 *
 * Eine festgeschriebene Rechnung bekommt genau ein PDF, das beim ersten
 * Aufruf entsteht und danach unverändert bleibt (GoBD): später geänderte
 * Einstellungen oder Empfängerdaten berühren es nicht. Entwürfe lassen sich
 * als Vorschau mit Wasserzeichen rendern, ohne etwas zu speichern.
 */
class InvoiceDocumentService {

	public function __construct(
		private InvoiceMapper $invoices,
		private InvoiceLineMapper $lines,
		private InvoiceDocumentMapper $documents,
		private DebtorMapper $debtors,
		private AccountMapper $accounts,
		private InvoiceSettings $settings,
		private InvoicePdfRenderer $renderer,
		private InvoiceDocumentStorage $storage,
		private AuditService $audit,
		private ?IL10N $l10n = null,
	) {
	}

	/**
	 * Das PDF einer festgeschriebenen Rechnung; erzeugt es, wenn es noch fehlt.
	 *
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException wenn die Rechnung noch ein Entwurf ist
	 * @throws \RuntimeException wenn PDF-Erzeugung oder Ablage scheitern
	 */
	public function ensurePdf(int $invoiceId): InvoiceDocument {
		$invoice = $this->invoices->find($invoiceId);
		if (!$invoice->isIssued()) {
			throw new \InvalidArgumentException($this->msg('Ein PDF entsteht erst beim Festschreiben.'));
		}
		$existing = $this->documents->findByInvoiceAndKind($invoiceId, InvoiceDocument::KIND_PDF);
		if ($existing !== null && $this->storage->readOrNull($existing) !== null) {
			return $existing;
		}
		$bytes = $this->renderer->render($this->pdfData($invoice, false));
		$doc = $this->storage->store($invoice, InvoiceDocument::KIND_PDF, $bytes, 'pdf', 'PDF/A-3b');
		$this->audit->log('Rechnungs-PDF erzeugt', 'invoice', $invoiceId, ['number' => $invoice->getNumber(), 'sha256' => $doc->getSha256()]);
		return $doc;
	}

	/**
	 * PDF-Bytes zur Anzeige oder zum Download: das gespeicherte bei einer
	 * festgeschriebenen Rechnung, die Vorschau bei einem Entwurf.
	 *
	 * @return array{content: string, fileName: string}
	 * @throws DoesNotExistException
	 * @throws \RuntimeException
	 */
	public function pdfContent(int $invoiceId): array {
		$invoice = $this->invoices->find($invoiceId);
		if (!$invoice->isIssued()) {
			return [
				'content' => $this->renderer->render($this->pdfData($invoice, true)),
				'fileName' => $this->msg('Rechnung-Entwurf') . '.pdf',
			];
		}
		$doc = $this->ensurePdf($invoiceId);
		return ['content' => $this->storage->read($doc), 'fileName' => $doc->getFileName()];
	}

	/**
	 * Inhalt eines gespeicherten Dokuments.
	 *
	 * @return array{content: string, fileName: string, kind: string}
	 * @throws DoesNotExistException
	 * @throws \RuntimeException
	 */
	public function documentContent(int $invoiceId, int $documentId): array {
		$doc = $this->documents->find($documentId);
		if ($doc->getInvoiceId() !== $invoiceId) {
			throw new DoesNotExistException('Dokument gehört nicht zu dieser Rechnung');
		}
		return ['content' => $this->storage->read($doc), 'fileName' => $doc->getFileName(), 'kind' => $doc->getKind()];
	}

	/**
	 * Dokumente der Rechnung mit Prüfstand der Dateien.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function listWithIntegrity(int $invoiceId): array {
		$out = [];
		foreach ($this->documents->findByInvoice($invoiceId) as $doc) {
			$out[] = $doc->jsonSerialize() + ['integrity' => $this->storage->verify($doc)];
		}
		return $out;
	}

	private function pdfData(Invoice $invoice, bool $preview): InvoicePdfData {
		$lines = $this->lines->findByInvoice((int)$invoice->getId());
		$snapshot = $invoice->debtorSnapshotData();
		if ($snapshot === null && $invoice->getDebtorId() !== null) {
			try {
				$snapshot = $this->debtors->find($invoice->getDebtorId())->toSnapshot();
			} catch (DoesNotExistException) {
			}
		}
		$precedingNumber = null;
		$precedingDate = null;
		if ($invoice->isCredit() && $invoice->getPrecedingInvoiceId() !== null) {
			try {
				$original = $this->invoices->find($invoice->getPrecedingInvoiceId());
				$precedingNumber = $original->getNumber();
				$precedingDate = $original->getIssueDate();
			} catch (DoesNotExistException) {
			}
		}
		return new InvoicePdfData(
			$invoice,
			$lines,
			$snapshot ?? ['name' => $this->msg('(Empfänger fehlt)')],
			$this->settings->issuer(),
			$this->bank(),
			$precedingNumber,
			$precedingDate,
			$preview,
		);
	}

	/** @return array{iban: string, bic: string, holder: string}|null */
	private function bank(): ?array {
		$accountId = $this->settings->accountId();
		if ($accountId === null) {
			return null;
		}
		try {
			$account = $this->accounts->find($accountId, Application::BOOK);
		} catch (DoesNotExistException) {
			return null;
		}
		$iban = (string)$account->getIban();
		if ($iban === '') {
			return null;
		}
		return ['iban' => $iban, 'bic' => $this->settings->bic(), 'holder' => $this->settings->issuer()['name']];
	}

	/** @param list<int|string> $params */
	private function msg(string $text, array $params = []): string {
		return $this->l10n !== null ? $this->l10n->t($text, $params) : vsprintf($text, $params);
	}
}
