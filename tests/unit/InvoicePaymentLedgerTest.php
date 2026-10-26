<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceLine;
use OCA\Vereinsbuchhaltung\Db\InvoiceLineMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceMapper;
use OCA\Vereinsbuchhaltung\Db\InvoicePayment;
use OCA\Vereinsbuchhaltung\Db\InvoicePaymentMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Service\InvoicePaymentLedger;
use OCA\Vereinsbuchhaltung\Service\InvoiceSettings;
use PHPUnit\Framework\TestCase;

/**
 * Das Zahlungsbuch: Aufteilung von Teilzahlungen auf die Erlöskonten, Status
 * von Posten und Rechnung und das Zurücknehmen einer Zahlung.
 */
class InvoicePaymentLedgerTest extends TestCase {

	/** @var array<int, InvoicePayment> */
	private array $payments = [];
	private Invoice $invoice;
	private OpenItem $item;
	private InvoiceSettings&\PHPUnit\Framework\MockObject\MockObject $settings;

	protected function setUp(): void {
		$this->payments = [];
		$this->invoice = new Invoice();
		$this->invoice->setId(1);
		$this->invoice->setStatus(Invoice::STATUS_SENT);
		$this->invoice->setSentAt('2026-03-06T10:00:00+00:00');
		$this->item = new OpenItem();
		$this->item->setId(10);
		$this->item->setAmountCents(10001);
		$this->item->setStatus('open');
		$this->item->setSettledCents(0);
		$this->settings = $this->createMock(InvoiceSettings::class);
		$this->settings->method('vatAccountId')->willReturnCallback(static fn (int $rate): ?int => match ($rate) {
			1900 => 31,
			700 => 32,
			default => null,
		});
	}

	private function ledger(): InvoicePaymentLedger {
		$payments = $this->createMock(InvoicePaymentMapper::class);
		$payments->method('insert')->willReturnCallback(function (InvoicePayment $p): InvoicePayment {
			$p->setId(count($this->payments) + 1);
			$this->payments[(int)$p->getId()] = $p;
			return $p;
		});
		$payments->method('findByInvoice')->willReturnCallback(fn () => array_values($this->payments));
		$payments->method('findByJournal')->willReturnCallback(fn (int $j) => array_values(array_filter($this->payments, static fn (InvoicePayment $p) => $p->getJournalId() === $j)));
		$payments->method('delete')->willReturnCallback(function (InvoicePayment $p): InvoicePayment {
			unset($this->payments[(int)$p->getId()]);
			return $p;
		});
		$invoices = $this->createMock(InvoiceMapper::class);
		$invoices->method('find')->willReturn($this->invoice);
		$invoices->method('update')->willReturnArgument(0);
		$openItems = $this->createMock(OpenItemMapper::class);
		$openItems->method('find')->willReturn($this->item);
		$openItems->method('update')->willReturnArgument(0);
		$lines = $this->createMock(InvoiceLineMapper::class);
		$lines->method('findByInvoice')->willReturn([
			$this->line(3001, 10),
			$this->line(7000, 11),
		]);
		return new InvoicePaymentLedger($payments, $invoices, $openItems, $lines, $this->settings);
	}

	private function line(int $netCents, int $accountId): InvoiceLine {
		$line = new InvoiceLine();
		$line->setLineNetCents($netCents);
		$line->setAccountId($accountId);
		return $line;
	}

	public function testGewichteFolgenDenPositionenUndDenBisherigenZahlungen(): void {
		$ledger = $this->ledger();
		$this->assertSame([10 => 3001, 11 => 7000], $ledger->remainingWeights($this->invoice));

		$ledger->record($this->invoice, $this->item, 5000, 1, 100, [10 => 1500, 11 => 3500], 'admin');

		$this->assertSame([10 => 1501, 11 => 3500], $ledger->remainingWeights($this->invoice));
	}

	public function testTeilzahlungenSummierenSichExaktAufDieErloeskonten(): void {
		$ledger = $this->ledger();
		$total = [10 => 0, 11 => 0];

		foreach ([3333, 3333, 3335] as $i => $amount) {
			$parts = $ledger->allocate($this->invoice, $amount);
			$this->assertSame($amount, array_sum($parts));
			$ledger->record($this->invoice, $this->item, $amount, null, 100 + $i, $parts, 'admin');
			foreach ($parts as $account => $cents) {
				$total[$account] += $cents;
			}
		}
		$rest = $this->item->remainingCents();
		$parts = $ledger->allocate($this->invoice, $rest);
		$ledger->record($this->invoice, $this->item, $rest, null, 200, $parts, 'admin');
		foreach ($parts as $account => $cents) {
			$total[$account] += $cents;
		}

		$this->assertSame([10 => 3001, 11 => 7000], $total, 'Am Ende entspricht die Summe je Konto genau den Positionen.');
		$this->assertSame(Invoice::STATUS_PAID, $this->invoice->getStatus());
		$this->assertSame('paid', $this->item->getStatus());
	}

	public function testVolleZahlungSchliesstPostenUndRechnung(): void {
		$ledger = $this->ledger();
		$parts = $ledger->allocate($this->invoice, 10001);

		$ledger->record($this->invoice, $this->item, 10001, 7, 100, $parts, 'admin');

		$this->assertSame(10001, $this->item->getSettledCents());
		$this->assertSame('paid', $this->item->getStatus());
		$this->assertSame(100, $this->item->getPaidJournalId());
		$this->assertSame(Invoice::STATUS_PAID, $this->invoice->getStatus());
	}

	public function testTeilzahlungLaesstRechnungOffen(): void {
		$ledger = $this->ledger();

		$ledger->record($this->invoice, $this->item, 4000, 7, 100, $ledger->allocate($this->invoice, 4000), 'admin');

		$this->assertSame('open', $this->item->getStatus());
		$this->assertSame(6001, $this->item->remainingCents());
		$this->assertSame(Invoice::STATUS_SENT, $this->invoice->getStatus());
	}

	public function testZuvielWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->ledger()->allocate($this->invoice, 10002);
	}

	public function testZahlungZuruecknehmenOeffnetPostenUndRechnungWieder(): void {
		$ledger = $this->ledger();
		$ledger->record($this->invoice, $this->item, 10001, 7, 100, $ledger->allocate($this->invoice, 10001), 'admin');

		$ledger->revertForJournal(100);

		$this->assertSame(0, $this->item->getSettledCents());
		$this->assertSame('open', $this->item->getStatus());
		$this->assertNull($this->item->getPaidJournalId());
		$this->assertSame(Invoice::STATUS_SENT, $this->invoice->getStatus(), 'Verschickt bleibt verschickt.');
		$this->assertSame([], $this->payments);
	}

	public function testRueckgabeOhneVersandZeigtWiederAlsFestgeschrieben(): void {
		$this->invoice->setSentAt(null);
		$this->invoice->setStatus(Invoice::STATUS_ISSUED);
		$ledger = $this->ledger();
		$ledger->record($this->invoice, $this->item, 10001, 7, 100, $ledger->allocate($this->invoice, 10001), 'admin');

		$ledger->revertForJournal(100);

		$this->assertSame(Invoice::STATUS_ISSUED, $this->invoice->getStatus());
	}

	public function testNurDieZahlungDesBuchungssatzesWirdZurueckgenommen(): void {
		$ledger = $this->ledger();
		$ledger->record($this->invoice, $this->item, 3000, 7, 100, $ledger->allocate($this->invoice, 3000), 'admin');
		$ledger->record($this->invoice, $this->item, 2000, 8, 101, $ledger->allocate($this->invoice, 2000), 'admin');

		$ledger->revertForJournal(100);

		$this->assertSame(2000, $this->item->getSettledCents());
		$this->assertCount(1, $this->payments);
	}

	public function testBuchungssatzOhneRechnungszahlungBleibtUnberuehrt(): void {
		$ledger = $this->ledger();
		$ledger->record($this->invoice, $this->item, 3000, 7, 100, $ledger->allocate($this->invoice, 3000), 'admin');

		$ledger->revertForJournal(999);

		$this->assertSame(3000, $this->item->getSettledCents());
	}

	public function testRegelbesteuerungLegtDieUmsatzsteuerAufIhrKontoUndTeiltDieZahlungAnteilig(): void {
		$this->invoice->setTaxMode(Invoice::TAX_REGULAR);
		$ledger = $this->regularLedger([
			[$this->taxedLine(10000, 10, 'S', 1900)],
			[$this->taxedLine(5000, 11, 'S', 700)],
		]);

		// 100,00 + 19 % und 50,00 + 7 %: Steuer 19,00 und 3,50, Brutto 172,50.
		$this->assertSame([10 => 10000, 11 => 5000, 31 => 1900, 32 => 350], $ledger->remainingWeights($this->invoice));

		$parts = $ledger->allocate($this->invoice, 17250);
		$this->assertSame([10 => 10000, 11 => 5000, 31 => 1900, 32 => 350], $parts);
	}

	public function testRegelbesteuerungTeilzahlungVerliertKeinenCent(): void {
		$this->invoice->setTaxMode(Invoice::TAX_REGULAR);
		$ledger = $this->regularLedger([[$this->taxedLine(10000, 10, 'S', 1900)]]);

		$first = $ledger->allocate($this->invoice, 5000);
		$this->assertSame(5000, array_sum($first));
		$ledger->record($this->invoice, $this->item, 5000, null, 100, $first, 'admin');
		$rest = $ledger->allocate($this->invoice, 6900);

		$this->assertSame(6900, array_sum($rest));
		$this->assertSame(10000, ($first[10] ?? 0) + ($rest[10] ?? 0));
		$this->assertSame(1900, ($first[31] ?? 0) + ($rest[31] ?? 0));
	}

	public function testBefreiteUndNullsatzPositionenBrauchenKeinUmsatzsteuerkonto(): void {
		$this->invoice->setTaxMode(Invoice::TAX_REGULAR);
		$ledger = $this->regularLedger([[$this->taxedLine(8000, 10, 'E', 0)], [$this->taxedLine(2000, 10, 'Z', 0)]]);

		$this->assertSame([10 => 10000], $ledger->remainingWeights($this->invoice));
	}

	public function testFehltDasUmsatzsteuerkontoWirdDasAbgelehnt(): void {
		$this->invoice->setTaxMode(Invoice::TAX_REGULAR);
		$ledger = $this->regularLedger([[$this->taxedLine(10000, 10, 'S', 1600)]]);

		$this->expectException(\InvalidArgumentException::class);
		$ledger->remainingWeights($this->invoice);
	}

	private function taxedLine(int $net, int $accountId, string $category, int $rateBp): InvoiceLine {
		$line = $this->line($net, $accountId);
		$line->setTaxCategory($category);
		$line->setTaxRateBp($rateBp);
		return $line;
	}

	/** @param list<list<InvoiceLine>> $groups */
	private function regularLedger(array $groups): InvoicePaymentLedger {
		$flat = array_merge(...$groups);
		$payments = $this->createMock(InvoicePaymentMapper::class);
		$payments->method('insert')->willReturnCallback(function (InvoicePayment $p): InvoicePayment {
			$p->setId(count($this->payments) + 1);
			$this->payments[(int)$p->getId()] = $p;
			return $p;
		});
		$payments->method('findByInvoice')->willReturnCallback(fn () => array_values($this->payments));
		$invoices = $this->createMock(InvoiceMapper::class);
		$invoices->method('update')->willReturnArgument(0);
		$openItems = $this->createMock(OpenItemMapper::class);
		$openItems->method('update')->willReturnArgument(0);
		$lines = $this->createMock(InvoiceLineMapper::class);
		$lines->method('findByInvoice')->willReturn($flat);
		return new InvoicePaymentLedger($payments, $invoices, $openItems, $lines, $this->settings);
	}
}
