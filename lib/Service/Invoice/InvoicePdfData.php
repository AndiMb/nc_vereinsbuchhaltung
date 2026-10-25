<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Invoice;

use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceLine;

/**
 * Alles, was der {@see InvoicePdfRenderer} für eine Rechnung braucht. Der
 * Renderer liest weder Datenbank noch Einstellungen: der Aufrufer stellt die
 * Daten zusammen, so bleibt das Layout isoliert testbar.
 */
final class InvoicePdfData {

	/**
	 * @param list<InvoiceLine> $lines
	 * @param array<string, mixed> $debtor Empfänger (Schnappschuss der Rechnung oder aktueller Stand beim Entwurf)
	 * @param array<string, string> $issuer Angaben des Vereins, siehe InvoiceSettings::issuer()
	 * @param array{iban: string, bic: string, holder: string}|null $bank Bankverbindung für die Überweisung
	 * @param string|null $precedingNumber bei einer Stornorechnung die Nummer der Ursprungsrechnung
	 * @param string|null $precedingDate bei einer Stornorechnung deren Datum (Y-m-d)
	 * @param bool $preview Entwurfsansicht: Wasserzeichen, kein PDF/A
	 * @param string|null $paymentReference strukturierte Zahlungsreferenz (ISO 11649), die gedruckt und in den GiroCode eingetragen wird
	 */
	public function __construct(
		public readonly Invoice $invoice,
		public readonly array $lines,
		public readonly array $debtor,
		public readonly array $issuer,
		public readonly ?array $bank,
		public readonly ?string $precedingNumber = null,
		public readonly ?string $precedingDate = null,
		public readonly bool $preview = false,
		public readonly ?string $paymentReference = null,
	) {
	}
}
