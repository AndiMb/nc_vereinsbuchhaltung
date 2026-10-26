<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\EInvoice;

use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceLine;

/**
 * Alles, was der {@see EInvoiceWriter} für eine Rechnung braucht. Wie bei
 * {@see \OCA\Vereinsbuchhaltung\Service\Invoice\InvoicePdfData} liest der Writer
 * weder Datenbank noch Einstellungen.
 */
final class EInvoiceData {

	/**
	 * @param list<InvoiceLine> $lines
	 * @param array<string, mixed> $debtor Empfänger (Schnappschuss der festgeschriebenen Rechnung)
	 * @param array<string, string> $issuer Angaben des Vereins, siehe InvoiceSettings::issuer()
	 * @param array{iban: string, bic: string, holder: string}|null $bank
	 * @param string|null $precedingNumber bei einer Stornorechnung die Nummer der Ursprungsrechnung
	 * @param string|null $precedingDate bei einer Stornorechnung deren Datum (Y-m-d)
	 */
	public function __construct(
		public readonly Invoice $invoice,
		public readonly array $lines,
		public readonly array $debtor,
		public readonly array $issuer,
		public readonly ?array $bank,
		public readonly ?string $precedingNumber = null,
		public readonly ?string $precedingDate = null,
	) {
	}
}
