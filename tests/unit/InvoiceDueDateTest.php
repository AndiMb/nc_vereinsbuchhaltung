<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceDueDate;
use PHPUnit\Framework\TestCase;

/**
 * Vorgegebenes Zahlungsziel einer Rechnung (Issue #391): ab Rechnungsdatum
 * oder ab Leistungszeitpunkt, dort auch „Tage vor der Leistung".
 * Dieselben Fälle prüft src/lib/invoices.test.js für den Editor.
 */
class InvoiceDueDateTest extends TestCase {

	/** @dataProvider faelle */
	public function testStandardziel(string $expected, string $issue, ?string $delivery, ?string $start, ?string $end, int $days, string $basis): void {
		$this->assertSame($expected, InvoiceDueDate::default($issue, $delivery, $start, $end, $days, $basis));
	}

	/** @return array<string, array{string, string, ?string, ?string, ?string, int, string}> */
	public static function faelle(): array {
		return [
			'ab Rechnungsdatum' => ['2026-11-15', '2026-11-01', '2027-01-10', null, null, 14, 'issue'],
			'ab Rechnungsdatum über den Jahreswechsel' => ['2027-01-03', '2026-12-20', null, null, null, 14, 'issue'],
			'nach der Leistung' => ['2027-01-13', '2026-11-01', '2027-01-10', null, null, 3, 'delivery'],
			'vor der Leistung' => ['2027-01-07', '2026-11-01', '2027-01-10', null, null, -3, 'delivery'],
			'am Tag der Leistung' => ['2027-01-10', '2026-11-01', '2027-01-10', null, null, 0, 'delivery'],
			'Zeitraum: Frist danach zählt ab dem Ende' => ['2027-02-07', '2026-11-01', null, '2027-01-01', '2027-01-31', 7, 'delivery'],
			'Zeitraum: Frist davor zählt ab dem Beginn' => ['2026-12-25', '2026-11-01', null, '2027-01-01', '2027-01-31', -7, 'delivery'],
			'nie vor dem Rechnungsdatum' => ['2027-01-20', '2027-01-20', '2027-01-10', null, null, 3, 'delivery'],
			'vor der Leistung, aber Rechnung später' => ['2027-01-09', '2027-01-09', '2027-01-10', null, null, -3, 'delivery'],
			'ohne Leistungszeitpunkt bleibt das Rechnungsdatum' => ['2026-11-04', '2026-11-01', null, null, null, 3, 'delivery'],
			'ohne Leistungszeitpunkt keine Frist davor' => ['2026-11-01', '2026-11-01', '', '', '', -3, 'delivery'],
			'negative Frist ohne Leistungsbezug gilt als null' => ['2026-11-01', '2026-11-01', '2027-01-10', null, null, -3, 'issue'],
			'unbekannter Bezug zählt ab Rechnungsdatum' => ['2026-11-15', '2026-11-01', '2027-01-10', null, null, 14, ''],
			'Datum mit Uhrzeit' => ['2027-01-13', '2026-11-01 00:00:00', '2027-01-10 00:00:00', null, null, 3, 'delivery'],
		];
	}

	public function testStornorechnungIstAmRechnungsdatumFaellig(): void {
		$credit = new Invoice();
		$credit->setKind(Invoice::KIND_CREDIT);
		$credit->setDeliveryDate('2027-01-10');

		$this->assertSame('2026-11-01', InvoiceDueDate::forInvoice($credit, '2026-11-01', 14, 'delivery'));
	}

	public function testRechnungNimmtIhrenLeistungszeitpunkt(): void {
		$invoice = new Invoice();
		$invoice->setKind(Invoice::KIND_INVOICE);
		$invoice->setDeliveryDate('2027-01-10');

		$this->assertSame('2027-01-07', InvoiceDueDate::forInvoice($invoice, '2026-11-01', -3, 'delivery'));
		$this->assertSame('2026-11-15', InvoiceDueDate::forInvoice($invoice, '2026-11-01', 14, 'issue'));
	}
}
