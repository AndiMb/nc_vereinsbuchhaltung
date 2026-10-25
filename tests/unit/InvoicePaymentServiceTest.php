<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceMapper;
use OCA\Vereinsbuchhaltung\Db\Journal;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\BookingService;
use OCA\Vereinsbuchhaltung\Service\InvoicePaymentLedger;
use OCA\Vereinsbuchhaltung\Service\InvoicePaymentService;
use OCA\Vereinsbuchhaltung\Service\JournalService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Zahlungen zu Rechnungen: Weg über den Bankumsatz und die Barzahlung.
 */
class InvoicePaymentServiceTest extends TestCase {

	private BookingService&MockObject $booking;
	private JournalService&MockObject $journals;
	private InvoicePaymentLedger&MockObject $ledger;
	private Invoice $invoice;
	private OpenItem $item;
	private Journal $journal;

	protected function setUp(): void {
		$this->invoice = new Invoice();
		$this->invoice->setId(1);
		$this->invoice->setKind(Invoice::KIND_INVOICE);
		$this->invoice->setStatus(Invoice::STATUS_SENT);
		$this->invoice->setNumber('RE-2026-0001');
		$this->invoice->setOpenItemId(10);
		$this->invoice->setDebtorSnapshot('{"name":"Muster GmbH"}');
		$this->item = new OpenItem();
		$this->item->setId(10);
		$this->item->setAmountCents(10000);
		$this->item->setSettledCents(0);
		$this->journal = new Journal();
		$this->journal->setId(55);
	}

	private function service(bool $cashAccountIsBank = true): InvoicePaymentService {
		$this->booking = $this->createMock(BookingService::class);
		$this->journals = $this->createMock(JournalService::class);
		$this->ledger = $this->createMock(InvoicePaymentLedger::class);
		$invoices = $this->createMock(InvoiceMapper::class);
		$invoices->method('find')->willReturn($this->invoice);
		$openItems = $this->createMock(OpenItemMapper::class);
		$openItems->method('find')->willReturn($this->item);
		$journalMapper = $this->createMock(JournalMapper::class);
		$journalMapper->method('find')->willReturn($this->journal);
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('find')->willReturnCallback(function (int $id) use ($cashAccountIsBank): Account {
			if ($id === 404) {
				throw new DoesNotExistException('');
			}
			$a = new Account();
			$a->setId($id);
			$a->setIsBank($cashAccountIsBank);
			$a->setActive(true);
			return $a;
		});
		return new InvoicePaymentService(
			$this->booking,
			$this->journals,
			$this->ledger,
			$invoices,
			$openItems,
			$journalMapper,
			$this->createMock(BankTransactionMapper::class),
			$accounts,
			new TransactionRunner($this->createMock(IDBConnection::class)),
			$this->createMock(AuditService::class),
			$this->createMock(IUserSession::class),
		);
	}

	private function tx(int $amount, ?int $journalId = null): BankTransaction {
		$tx = new BankTransaction();
		$tx->setId(7);
		$tx->setAmountCents($amount);
		$tx->setCounterparty('Max Zahler');
		$tx->setJournalId($journalId);
		return $tx;
	}

	public function testZahlungEingangWirdMitAufteilungGebuchtUndImZahlungsbuchVermerkt(): void {
		$s = $this->service();
		$tx = $this->tx(10000);
		$this->ledger->method('allocate')->willReturn([10 => 6000, 11 => 4000]);
		$this->booking->expects($this->once())->method('assignParts')
			->with($tx, [['accountId' => 10, 'amountCents' => 6000], ['accountId' => 11, 'amountCents' => 4000]])
			->willReturnCallback(function (BankTransaction $t): BankTransaction {
				$t->setJournalId(55);
				return $t;
			});
		$this->ledger->expects($this->once())->method('record')
			->with($this->invoice, $this->item, 10000, 7, 55, [10 => 6000, 11 => 4000], $this->anything());

		$s->payFromBankTransaction($tx, 1);

		$this->assertSame('RE-2026-0001', $this->journal->getDocumentRef());
		$this->assertSame('Rechnung RE-2026-0001 – Max Zahler', $this->journal->getDescription());
	}

	public function testTeilzahlungIstErlaubt(): void {
		$s = $this->service();
		$this->ledger->method('allocate')->willReturn([10 => 2500]);
		$this->booking->method('assignParts')->willReturnCallback(function (BankTransaction $t): BankTransaction {
			$t->setJournalId(55);
			return $t;
		});
		$this->ledger->expects($this->once())->method('record');

		$s->payFromBankTransaction($this->tx(2500), 1);
	}

	public function testZuvielWirdAbgelehntOhneZuBuchen(): void {
		$s = $this->service();
		$this->booking->expects($this->never())->method('assignParts');

		$this->expectException(\InvalidArgumentException::class);
		$s->payFromBankTransaction($this->tx(10001), 1);
	}

	public function testAusgangWirdAbgelehnt(): void {
		$s = $this->service();
		$this->booking->expects($this->never())->method('assignParts');

		$this->expectException(\InvalidArgumentException::class);
		$s->payFromBankTransaction($this->tx(-500), 1);
	}

	public function testBereitsBezahlteRechnungNimmtKeineZahlungMehrAn(): void {
		$this->item->setSettledCents(10000);
		$s = $this->service();

		$this->expectException(\InvalidArgumentException::class);
		$s->payFromBankTransaction($this->tx(500), 1);
	}

	/** @dataProvider nichtZahlbar */
	public function testEntwurfStornoUndStornierteRechnungSindNichtZahlbar(string $kind, string $status): void {
		$this->invoice->setKind($kind);
		$this->invoice->setStatus($status);
		$s = $this->service();

		$this->expectException(\InvalidArgumentException::class);
		$s->payFromBankTransaction($this->tx(500), 1);
	}

	/** @return array<string, array{string, string}> */
	public static function nichtZahlbar(): array {
		return [
			'Entwurf' => [Invoice::KIND_INVOICE, Invoice::STATUS_DRAFT],
			'storniert' => [Invoice::KIND_INVOICE, Invoice::STATUS_CANCELLED],
			'Stornorechnung' => [Invoice::KIND_CREDIT, Invoice::STATUS_ISSUED],
		];
	}

	public function testBereitsZugeordneterUmsatzWirdErstGeloest(): void {
		$s = $this->service();
		$assigned = $this->tx(10000, 99);
		$free = $this->tx(10000);
		$this->booking->expects($this->once())->method('unassign')->with($assigned)->willReturn($free);
		$this->ledger->method('allocate')->willReturn([10 => 10000]);
		$this->booking->method('assignParts')->willReturnCallback(function (BankTransaction $t): BankTransaction {
			$t->setJournalId(55);
			return $t;
		});

		$s->payFromBankTransaction($assigned, 1);
	}

	public function testBarzahlungBuchtGeldkontoAnErloeskonten(): void {
		$s = $this->service();
		$this->ledger->method('allocate')->willReturn([10 => 6000, 11 => 4000]);
		$this->journals->expects($this->once())->method('createBookingLines')
			->with(
				'__verein__',
				'2026-03-10',
				'Rechnung RE-2026-0001 – Muster GmbH',
				'RE-2026-0001',
				[
					['accountId' => 3, 'debitCents' => 10000, 'creditCents' => 0],
					['accountId' => 10, 'debitCents' => 0, 'creditCents' => 6000],
					['accountId' => 11, 'debitCents' => 0, 'creditCents' => 4000],
				],
			)
			->willReturn($this->journal);
		$this->ledger->expects($this->once())->method('record')
			->with($this->invoice, $this->item, 10000, null, 55, [10 => 6000, 11 => 4000], $this->anything());

		$s->payManually(1, '2026-03-10', 3);
	}

	public function testBarzahlungNurAufGeldkonto(): void {
		$s = $this->service(false);
		$this->journals->expects($this->never())->method('createBookingLines');

		$this->expectException(\InvalidArgumentException::class);
		$s->payManually(1, '2026-03-10', 3);
	}

	/** @dataProvider ungueltigeBarzahlungen */
	public function testUngueltigeBarzahlungWirdAbgelehnt(string $date, int $account, ?int $amount): void {
		$s = $this->service();

		$this->expectException(\InvalidArgumentException::class);
		$s->payManually(1, $date, $account, $amount);
	}

	/** @return array<string, array{string, int, int|null}> */
	public static function ungueltigeBarzahlungen(): array {
		return [
			'Datum kaputt' => ['10.03.2026', 3, null],
			'Konto unbekannt' => ['2026-03-10', 404, null],
			'Betrag null' => ['2026-03-10', 3, 0],
			'Betrag zu hoch' => ['2026-03-10', 3, 10001],
		];
	}
}
