<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Invoice;

use OCA\Vereinsbuchhaltung\Db\Invoice;

/**
 * Das Zahlungsziel, das eine Rechnung ohne eigene Angabe bekommt (Issue #391).
 *
 * Die Frist zählt wahlweise ab dem Rechnungsdatum oder ab dem
 * Leistungszeitpunkt. Ab dem Leistungszeitpunkt darf sie negativ sein: „drei
 * Tage vor dem Auftritt" sind −3 Tage. Bei einem Leistungszeitraum zählt eine
 * Frist danach ab seinem Ende, eine Frist davor ab seinem Beginn.
 *
 * Dieselbe Rechnung steht in src/lib/invoices.js (defaultDueDate), damit der
 * Editor zeigt, was das Festschreiben setzen würde.
 */
final class InvoiceDueDate {

	public const BASIS_ISSUE = 'issue';
	public const BASIS_DELIVERY = 'delivery';
	public const BASES = [self::BASIS_ISSUE, self::BASIS_DELIVERY];

	/**
	 * @param string $issueDate Rechnungsdatum, JJJJ-MM-TT
	 * @param int $days Frist in Tagen; negativ nur mit Bezug auf den Leistungszeitpunkt sinnvoll
	 * @return string JJJJ-MM-TT, nie vor dem Rechnungsdatum
	 */
	public static function default(
		string $issueDate,
		?string $deliveryDate,
		?string $periodStart,
		?string $periodEnd,
		int $days,
		string $basis,
	): string {
		$reference = null;
		if ($basis === self::BASIS_DELIVERY) {
			$reference = self::filled($deliveryDate)
				?? ($days < 0
					? self::filled($periodStart) ?? self::filled($periodEnd)
					: self::filled($periodEnd) ?? self::filled($periodStart));
		}
		if ($reference === null) {
			// Ohne Leistungszeitpunkt bleibt nur das Rechnungsdatum; eine Frist
			// „davor" gibt es dort nicht.
			$reference = $issueDate;
			$days = max(0, $days);
		}
		$due = (new \DateTimeImmutable($reference))->modify(sprintf('%+d days', $days))->format('Y-m-d');
		// Ein Ziel vor dem Rechnungsdatum lehnt das Festschreiben ab; liegt die
		// Leistung schon länger zurück, ist die Rechnung sofort fällig.
		return max($due, substr($issueDate, 0, 10));
	}

	/** Das Standardziel für diese Rechnung, wenn sie am `$issueDate` ausgestellt wird. */
	public static function forInvoice(Invoice $invoice, string $issueDate, int $days, string $basis): string {
		if ($invoice->isCredit()) {
			// Eine Stornorechnung wird nicht bezahlt, ihr Ziel ist ihr Datum.
			return substr($issueDate, 0, 10);
		}
		return self::default($issueDate, $invoice->getDeliveryDate(), $invoice->getPeriodStart(), $invoice->getPeriodEnd(), $days, $basis);
	}

	private static function filled(?string $date): ?string {
		return $date !== null && $date !== '' ? substr($date, 0, 10) : null;
	}
}
