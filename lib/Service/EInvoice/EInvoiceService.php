<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\EInvoice;

use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceDocument;
use OCA\Vereinsbuchhaltung\Db\InvoiceDocumentMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceMapper;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceDocumentService;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceDocumentStorage;
use OCA\Vereinsbuchhaltung\Service\InvoiceSettings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Erzeugt die E-Rechnung zu einer festgeschriebenen Rechnung und legt sie
 * wie das PDF unveränderlich ab (Epic #168, Stufe 2).
 *
 * Das XML entsteht aus den festgeschriebenen Daten der Rechnung und wird vor
 * dem Speichern gegen das Schema des Formats geprüft: ein ungültiges
 * Dokument gelangt nie in die Ablage. Je Rechnung und Format gibt es genau ein
 * Dokument; ein zweiter Aufruf liefert das vorhandene (GoBD).
 */
class EInvoiceService {

	public function __construct(
		private InvoiceMapper $invoices,
		private InvoiceDocumentMapper $documents,
		private InvoiceDocumentService $documentService,
		private InvoiceDocumentStorage $storage,
		private InvoiceSettings $settings,
		private EInvoiceWriter $writer,
		private AuditService $audit,
		private IL10N $l10n,
	) {
	}

	/**
	 * Das Dokument im gewünschten Format; erzeugt es, wenn es noch fehlt.
	 *
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException bei unbekanntem Format oder einem Entwurf
	 * @throws EInvoiceException wenn Angaben fehlen oder das XML das Schema verletzt
	 * @throws \RuntimeException wenn die Ablage scheitert
	 */
	public function generate(int $invoiceId, string $format): InvoiceDocument {
		if (!EInvoiceFormat::isValid($format)) {
			throw new \InvalidArgumentException($this->l10n->t('Unbekanntes E-Rechnungs-Format.'));
		}
		$invoice = $this->invoices->find($invoiceId);
		if (!$invoice->isIssued()) {
			throw new \InvalidArgumentException($this->l10n->t('Eine E-Rechnung entsteht erst beim Festschreiben.'));
		}
		$kind = EInvoiceFormat::documentKind($format);
		$existing = $this->documents->findByInvoiceAndKind($invoiceId, $kind);
		if ($existing !== null && $this->storage->readOrNull($existing) !== null) {
			return $existing;
		}

		$xml = $this->writer->buildXml($this->documentService->eInvoiceData($invoice), $format);
		$problems = $this->writer->validate($xml, $format);
		if ($problems !== []) {
			throw new EInvoiceException($this->l10n->t('Die erzeugte E-Rechnung entspricht nicht dem Schema.'), $problems);
		}

		if (EInvoiceFormat::isHybridPdf($format)) {
			$pdf = $this->documentService->pdfContent($invoiceId)['content'];
			$content = $this->writer->embedIntoPdf($xml, $pdf, $format);
			if ($this->writer->extractXml($content) !== $xml) {
				throw new EInvoiceException($this->l10n->t('Das eingebettete XML stimmt nicht mit dem erzeugten überein.'));
			}
		} else {
			$content = $xml;
		}

		$doc = $this->storage->store($invoice, $kind, $content, EInvoiceFormat::fileExtension($format), EInvoiceFormat::profile($format), EInvoiceFormat::fileNameSuffix($format));
		$this->audit->log('E-Rechnung erzeugt', 'invoice', $invoiceId, ['number' => $invoice->getNumber(), 'format' => $format, 'sha256' => $doc->getSha256()]);
		return $doc;
	}

	/**
	 * Beim Festschreiben: erzeugt das Format, das zum Empfänger passt, wenn die
	 * Einstellung es vorsieht. Fehlende Angaben sind hier kein Fehler; die
	 * Oberfläche zeigt sie, sobald jemand die E-Rechnung ausdrücklich erzeugt.
	 */
	public function generateSuggested(int $invoiceId): ?InvoiceDocument {
		if (!$this->settings->eInvoiceAuto()) {
			return null;
		}
		$format = $this->suggestedFormat($this->invoices->find($invoiceId));
		if ($format === null) {
			return null;
		}
		try {
			return $this->generate($invoiceId, $format);
		} catch (EInvoiceException) {
			return null;
		}
	}

	public function suggestedFormat(Invoice $invoice): ?string {
		$kind = $invoice->debtorSnapshotData()['kind'] ?? null;
		return EInvoiceFormat::suggestFor(is_string($kind) ? $kind : null);
	}

	/**
	 * Was für die Formate noch fehlt, damit die Oberfläche es vor dem Erzeugen
	 * zeigen kann.
	 *
	 * @return array<string, list<string>> Format => fehlende Angaben
	 */
	public function missing(int $invoiceId): array {
		$invoice = $this->invoices->find($invoiceId);
		$out = [];
		if (!$invoice->isIssued()) {
			return $out;
		}
		$data = $this->documentService->eInvoiceData($invoice);
		foreach (EInvoiceFormat::ALL as $format) {
			$out[$format] = $this->writer->missing($data, $format);
		}
		return $out;
	}
}
