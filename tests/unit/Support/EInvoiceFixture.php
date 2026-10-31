<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit\Support;

use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceLine;
use OCA\Vereinsbuchhaltung\Service\EInvoice\EInvoiceData;

/**
 * Beispielrechnung (RE-2026-0042, TSV Beispiel e. V.) für Tests, die eine
 * E-Rechnung bauen oder lesen.
 */
trait EInvoiceFixture {

	/** @param list<array{name: string, qty: int, price: int, discountBp?: int, category?: string, rate?: int}> $rows */
	private function data(string $taxMode = Invoice::TAX_REGULAR, bool $credit = false, ?array $rows = null, bool $complete = true): EInvoiceData {
		$rows ??= [
			['name' => 'Kursgebühr', 'qty' => 10000, 'price' => 10000, 'category' => 'S', 'rate' => 1900],
			['name' => 'Eintritt', 'qty' => 20000, 'price' => 2500, 'discountBp' => 1000, 'category' => 'S', 'rate' => 700],
			['name' => 'Raumnutzung', 'qty' => 10000, 'price' => 3000, 'category' => 'E', 'rate' => 0],
		];
		$lines = [];
		$net = 0;
		foreach ($rows as $i => $row) {
			$l = new InvoiceLine();
			$l->setPosition($i + 1);
			$l->setName($row['name']);
			$l->setQuantityE4($credit ? -$row['qty'] : $row['qty']);
			$l->setUnitCode('LS');
			$l->setUnitPriceCents($row['price']);
			$l->setDiscountBp($row['discountBp'] ?? 0);
			$l->setTaxCategory($taxMode === Invoice::TAX_REGULAR ? ($row['category'] ?? 'S') : 'E');
			$l->setTaxRateBp($taxMode === Invoice::TAX_REGULAR ? ($row['rate'] ?? 1900) : 0);
			$cents = \OCA\Vereinsbuchhaltung\Service\InvoiceCalculator::lineNet($row['qty'], $row['price'], $row['discountBp'] ?? 0);
			$l->setLineNetCents($credit ? -$cents : $cents);
			$net += $l->getLineNetCents();
			$lines[] = $l;
		}
		$totals = \OCA\Vereinsbuchhaltung\Service\InvoiceCalculator::totals(array_map(static fn ($l) => [
			'lineNetCents' => $l->getLineNetCents(), 'taxRateBp' => $l->getTaxRateBp(), 'taxCategory' => $l->getTaxCategory(),
		], $lines), $taxMode);
		$inv = new Invoice();
		$inv->setId(1);
		$inv->setKind($credit ? Invoice::KIND_CREDIT : Invoice::KIND_INVOICE);
		$inv->setNumber($credit ? 'ST-2026-0001' : 'RE-2026-0042');
		$inv->setIssueDate('2026-03-05');
		$inv->setDeliveryDate('2026-03-01');
		$inv->setDueDate('2026-03-19');
		$inv->setCurrency('EUR');
		$inv->setTaxMode($taxMode);
		$inv->setExemptionText($taxMode === Invoice::TAX_REGULAR ? 'Steuerfrei nach § 4 Nr. 12 UStG' : 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.');
		$inv->setNetCents($totals['netCents']);
		$inv->setTaxCents($totals['taxCents']);
		$inv->setGrossCents($totals['grossCents']);
		$inv->setNoteHead('vielen Dank für Ihren Auftrag.');
		$inv->setPaymentTerms('Zahlbar innerhalb von 14 Tagen ohne Abzug.');
		$inv->setBuyerReference('991-01234-44');
		$issuer = ['name' => 'TSV Beispiel e. V.', 'street' => 'Hauptstr. 1', 'postalCode' => '12345', 'city' => 'Beispielstadt', 'country' => 'DE',
			'taxNumber' => '12/345/67890', 'vatId' => '', 'registerCourt' => 'AG Beispielstadt', 'registerNo' => 'VR 1', 'representatives' => 'Erika Muster, 1. Vorsitzende',
			'phone' => '0123 456789', 'email' => 'kasse@tsv-beispiel.example'];
		$debtor = ['name' => 'Stadt Beispielstadt', 'street' => 'Rathausplatz 1', 'postalCode' => '12345', 'city' => 'Beispielstadt', 'country' => 'DE',
			'customerNo' => 'K-17', 'email' => 'rechnung@beispielstadt.example', 'leitwegId' => '991-01234-44', 'vatId' => null];
		if (!$complete) {
			$issuer['phone'] = '';
			$issuer['email'] = '';
			$debtor['email'] = '';
		}
		return new EInvoiceData($inv, $lines, $debtor, $issuer, ['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001', 'holder' => 'TSV Beispiel e. V.'], $credit ? 'RE-2026-0001' : null, $credit ? '2026-02-01' : null);
	}
}
