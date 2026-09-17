<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\BankTxSepaDetail;
use OCA\Vereinsbuchhaltung\Db\BankTxSepaDetailMapper;
use OCA\Vereinsbuchhaltung\Db\DebitItem;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebit;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebitMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\BookingService;
use OCA\Vereinsbuchhaltung\Service\ClaimService;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportConfirmationService;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportSettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Verbuchung nach dem Bestätigungsvorgang (Spec §3.10/§5, Issue #72) -
 * Schwerpunkt: die Rücklastschrift-Buchung mit zwei Gegenkonto-Zeilen und die
 * Sammelbuchung des erfolgreichen Einzugs, beide über den wiederverwendeten
 * `BookingService::assignParts()`-Pfad (hier gemockt, siehe Klassendoc dort).
 */
class SepaImportConfirmationServiceTest extends TestCase {

	private BankTxSepaDetailMapper&MockObject $details;
	private BankTransactionMapper&MockObject $txMapper;
	private DebitItemMapper&MockObject $debitItems;
	private OpenItemMapper&MockObject $openItems;
	private ReturnedDebitMapper&MockObject $returnedDebits;
	private ClaimService&MockObject $claims;
	private BookingService&MockObject $bookingService;
	private SepaImportSettingsService&MockObject $settings;
	private AuditService&MockObject $audit;

	/**
	 * @var array<int, ReturnedDebit> DebitItem-Id => bereits vorhandene
	 *                                Rücklastschrift. Callback statt mehrerer with()-Stubs auf derselben
	 *                                Methode, siehe DebitBatchServiceTest/SepaMatchingServiceTest für
	 *                                dieselbe Begründung (Registrierungsreihenfolge verdeckt sonst
	 *                                spätere, spezifischere Stubs).
	 */
	private array $existingReturnedDebits = [];

	protected function setUp(): void {
		$this->details = $this->createMock(BankTxSepaDetailMapper::class);
		$this->txMapper = $this->createMock(BankTransactionMapper::class);
		$this->debitItems = $this->createMock(DebitItemMapper::class);
		$this->openItems = $this->createMock(OpenItemMapper::class);
		$this->returnedDebits = $this->createMock(ReturnedDebitMapper::class);
		$this->returnedDebits->method('findByDebitItem')->willReturnCallback(
			fn (int $debitItemId) => $this->existingReturnedDebits[$debitItemId] ?? null,
		);
		$this->claims = $this->createMock(ClaimService::class);
		$this->bookingService = $this->createMock(BookingService::class);
		$this->settings = $this->createMock(SepaImportSettingsService::class);
		$this->audit = $this->createMock(AuditService::class);

		$this->details->method('update')->willReturnArgument(0);
		$this->openItems->method('update')->willReturnArgument(0);
		$this->returnedDebits->method('insert')->willReturnCallback(static function (ReturnedDebit $r): ReturnedDebit {
			$r->setId(random_int(1000, 9999));
			return $r;
		});
	}

	private function service(): SepaImportConfirmationService {
		$transaction = $this->createMock(TransactionRunner::class);
		$transaction->method('run')->willReturnCallback(static fn (callable $fn) => $fn());

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('kassenwart');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-10-10 10:00:00'));

		return new SepaImportConfirmationService(
			$this->details,
			$this->txMapper,
			$this->debitItems,
			$this->openItems,
			$this->returnedDebits,
			$this->claims,
			$this->bookingService,
			$this->settings,
			$transaction,
			$this->audit,
			$userSession,
			$time,
			$l10n,
		);
	}

	private function tx(int $id, int $amountCents, ?int $journalId = null): BankTransaction {
		$tx = new BankTransaction();
		$tx->setId($id);
		$tx->setAmountCents($amountCents);
		$tx->setBookingDate('2026-10-10');
		$tx->setJournalId($journalId);
		return $tx;
	}

	private function detail(int $id, int $bankTxId, int $debitItemId, int $amountCents, bool $isReturn, string $status = BankTxSepaDetail::STATUS_ASSIGNED, ?int $chargesCents = null): BankTxSepaDetail {
		$d = new BankTxSepaDetail();
		$d->setId($id);
		$d->setBankTxId($bankTxId);
		$d->setDebitItemId($debitItemId);
		$d->setAmountCents($amountCents);
		$d->setIsReturn($isReturn);
		$d->setChargesCents($chargesCents);
		$d->setStatus($status);
		if ($status !== BankTxSepaDetail::STATUS_OPEN) {
			$d->setDecidedAt('2026-10-10 09:00:00');
		}
		return $d;
	}

	private function debitItem(int $id, int $openItemId, int $amountCents, string $iban = 'DE02120300000000202051'): DebitItem {
		$item = new DebitItem();
		$item->setId($id);
		$item->setOpenItemId($openItemId);
		$item->setAmountCents($amountCents);
		$item->setIban($iban);
		return $item;
	}

	private function openItem(int $id, int $memberId, int $amountCents, ?int $accountId): OpenItem {
		$item = new OpenItem();
		$item->setId($id);
		$item->setMemberId($memberId);
		$item->setType(OpenItem::TYPE_CONTRIBUTION);
		$item->setAmountCents($amountCents);
		$item->setAccountId($accountId);
		$item->setStatus('open');
		return $item;
	}

	// --- Guard: alle Detail-Zeilen muessen beurteilt sein ------------------------------

	public function testSettleWirftWennNichtAlleDetailsBeurteiltSind(): void {
		$this->txMapper->method('find')->willReturn($this->tx(1, -5000));
		$this->details->method('findByBankTx')->with(1)->willReturn([
			$this->detail(1, 1, 10, -5000, true, BankTxSepaDetail::STATUS_OPEN),
		]);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->settle(1);
	}

	// --- Rücklastschrift-Buchung: zwei Gegenkonto-Zeilen (Spec §3.10) ------------------

	public function testRuecklastschriftBuchtZweiGegenkontoZeilen(): void {
		$tx = $this->tx(1, -5000);
		$this->txMapper->method('find')->willReturn($tx);
		$this->details->method('findByBankTx')->with(1)->willReturn([
			$this->detail(1, 1, 10, -5000, true, BankTxSepaDetail::STATUS_ASSIGNED, chargesCents: 500),
		]);
		$this->debitItems->method('find')->with(10)->willReturn($this->debitItem(10, 100, 4500));
		$this->openItems->method('find')->with(100)->willReturn($this->openItem(100, 7, 4500, 42));
		$this->settings->method('returnFeeAccountId')->willReturn(99);
		$this->settings->method('isReturnFeeRechargeEnabled')->willReturn(false);

		$capturedParts = null;
		$this->bookingService->method('assignParts')->willReturnCallback(function (BankTransaction $t, array $parts) use (&$capturedParts): BankTransaction {
			$capturedParts = $parts;
			$t->setJournalId(555);
			return $t;
		});

		$result = $this->service()->settle(1);

		$this->assertSame(['settled' => 0, 'returned' => 1], $result);
		$this->assertCount(2, $capturedParts, 'Erwartet: OAMT-Zeile aufs Erlöskonto + COAM-Zeile aufs Gebührenkonto');
		$this->assertSame(['accountId' => 42, 'amountCents' => 4500], $capturedParts[0]);
		$this->assertSame(['accountId' => 99, 'amountCents' => 500], $capturedParts[1]);
	}

	/** Ohne bekannte Bankgebühr entfällt die zweite Zeile - eine Zeile reicht dann aus. */
	public function testRuecklastschriftOhneGebuehrBuchtNurEineZeile(): void {
		$tx = $this->tx(1, -4500);
		$this->txMapper->method('find')->willReturn($tx);
		$this->details->method('findByBankTx')->with(1)->willReturn([
			$this->detail(1, 1, 10, -4500, true, BankTxSepaDetail::STATUS_ASSIGNED, chargesCents: null),
		]);
		$this->debitItems->method('find')->with(10)->willReturn($this->debitItem(10, 100, 4500));
		$this->openItems->method('find')->with(100)->willReturn($this->openItem(100, 7, 4500, 42));
		$this->settings->method('returnFeeAccountId')->willReturn(null);
		$this->settings->method('isReturnFeeRechargeEnabled')->willReturn(false);

		$capturedParts = null;
		$this->bookingService->method('assignParts')->willReturnCallback(function (BankTransaction $t, array $parts) use (&$capturedParts): BankTransaction {
			$capturedParts = $parts;
			$t->setJournalId(556);
			return $t;
		});

		$this->service()->settle(1);

		$this->assertCount(1, $capturedParts);
		$this->assertSame(['accountId' => 42, 'amountCents' => 4500], $capturedParts[0]);
	}

	public function testRuecklastschriftMitGebuehrOhneEingestelltesKontoWirft(): void {
		$this->txMapper->method('find')->willReturn($this->tx(1, -5000));
		$this->details->method('findByBankTx')->with(1)->willReturn([
			$this->detail(1, 1, 10, -5000, true, BankTxSepaDetail::STATUS_ASSIGNED, chargesCents: 500),
		]);
		$this->debitItems->method('find')->with(10)->willReturn($this->debitItem(10, 100, 4500));
		$this->openItems->method('find')->with(100)->willReturn($this->openItem(100, 7, 4500, 42));
		$this->settings->method('returnFeeAccountId')->willReturn(null);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->settle(1);
	}

	/** Öffnet die Forderung wieder ("zurückgegeben -> wieder offen", Spec §2.2). */
	public function testRuecklastschriftOeffnetForderungWiederUndLegtReturnedDebitAn(): void {
		$tx = $this->tx(1, -5000);
		$this->txMapper->method('find')->willReturn($tx);
		$this->details->method('findByBankTx')->with(1)->willReturn([
			$this->detail(1, 1, 10, -5000, true, BankTxSepaDetail::STATUS_ASSIGNED, chargesCents: 500),
		]);
		$this->debitItems->method('find')->with(10)->willReturn($this->debitItem(10, 100, 4500));
		$openItem = $this->openItem(100, 7, 4500, 42);
		$openItem->setStatus('paid');
		$openItem->setPaidJournalId(321);
		$openItem->setSettledAt('2026-10-05 00:00:00');
		$this->openItems->method('find')->with(100)->willReturn($openItem);
		$this->settings->method('returnFeeAccountId')->willReturn(99);
		$this->settings->method('isReturnFeeRechargeEnabled')->willReturn(false);
		$this->bookingService->method('assignParts')->willReturnCallback(static function (BankTransaction $t) {
			$t->setJournalId(555);
			return $t;
		});

		$updatedOpenItem = null;
		$this->openItems->method('update')->willReturnCallback(function (OpenItem $o) use (&$updatedOpenItem): OpenItem {
			$updatedOpenItem = $o;
			return $o;
		});
		$createdReturn = null;
		$this->returnedDebits->method('insert')->willReturnCallback(function (ReturnedDebit $r) use (&$createdReturn): ReturnedDebit {
			$r->setId(1);
			$createdReturn = $r;
			return $r;
		});

		$this->service()->settle(1);

		$this->assertSame('open', $updatedOpenItem->getStatus());
		$this->assertNull($updatedOpenItem->getPaidJournalId());
		$this->assertNull($updatedOpenItem->getSettledAt());
		$this->assertSame(10, $createdReturn->getDebitItemId());
		$this->assertSame(500, $createdReturn->getChargesCents());
		$this->assertSame(555, $createdReturn->getJournalId());
	}

	/** Posten-Guard (Spec §3.6/§5): eine Dublette verpufft still statt zu buchen. */
	public function testDoppelteRuecklastschriftDesselbenPostensVerpufftStill(): void {
		$tx = $this->tx(1, -4500);
		$this->txMapper->method('find')->willReturn($tx);
		$this->details->method('findByBankTx')->with(1)->willReturn([
			$this->detail(1, 1, 10, -4500, true, BankTxSepaDetail::STATUS_ASSIGNED),
		]);
		$this->debitItems->method('find')->with(10)->willReturn($this->debitItem(10, 100, 4500));
		$this->existingReturnedDebits[10] = new ReturnedDebit();
		$this->bookingService->expects($this->never())->method('assignParts');

		$result = $this->service()->settle(1);

		$this->assertSame(['settled' => 0, 'returned' => 0], $result);
	}

	// --- Gebühren-Weiterbelastung (Opt-in) ----------------------------------------------

	public function testGebuehrenWeiterbelastungLegtNeueForderungAn(): void {
		$tx = $this->tx(1, -5000);
		$this->txMapper->method('find')->willReturn($tx);
		$this->details->method('findByBankTx')->with(1)->willReturn([
			$this->detail(1, 1, 10, -5000, true, BankTxSepaDetail::STATUS_ASSIGNED, chargesCents: 500),
		]);
		$this->debitItems->method('find')->with(10)->willReturn($this->debitItem(10, 100, 4500));
		$this->openItems->method('find')->with(100)->willReturn($this->openItem(100, 7, 4500, 42));
		$this->settings->method('returnFeeAccountId')->willReturn(99);
		$this->settings->method('isReturnFeeRechargeEnabled')->willReturn(true);
		$this->bookingService->method('assignParts')->willReturnCallback(static function (BankTransaction $t) {
			$t->setJournalId(555);
			return $t;
		});

		$feeClaim = new OpenItem();
		$feeClaim->setId(200);
		$this->claims->expects($this->once())->method('createManual')
			->with(7, OpenItem::TYPE_FEE, 500, $this->anything(), '2026-10-10', 99)
			->willReturn($feeClaim);

		$createdReturn = null;
		$this->returnedDebits->method('insert')->willReturnCallback(function (ReturnedDebit $r) use (&$createdReturn): ReturnedDebit {
			$r->setId(1);
			$createdReturn = $r;
			return $r;
		});

		$this->service()->settle(1);

		$this->assertTrue($createdReturn->getFeeRechargeTriggered());
		$this->assertSame(200, $createdReturn->getFeeOpenItemId());
	}

	public function testOhneOptInWirdKeineGebuehrenforderungAngelegt(): void {
		$tx = $this->tx(1, -5000);
		$this->txMapper->method('find')->willReturn($tx);
		$this->details->method('findByBankTx')->with(1)->willReturn([
			$this->detail(1, 1, 10, -5000, true, BankTxSepaDetail::STATUS_ASSIGNED, chargesCents: 500),
		]);
		$this->debitItems->method('find')->with(10)->willReturn($this->debitItem(10, 100, 4500));
		$this->openItems->method('find')->with(100)->willReturn($this->openItem(100, 7, 4500, 42));
		$this->settings->method('returnFeeAccountId')->willReturn(99);
		$this->settings->method('isReturnFeeRechargeEnabled')->willReturn(false);
		$this->bookingService->method('assignParts')->willReturnCallback(static function (BankTransaction $t) {
			$t->setJournalId(555);
			return $t;
		});
		$this->claims->expects($this->never())->method('createManual');

		$this->service()->settle(1);
	}

	// --- Sammelbuchung des erfolgreichen Einzugs (Spec §3.10) --------------------------

	public function testSammelbuchungGruppiertNachErloeskontoUndSchliesstForderungenAb(): void {
		$tx = $this->tx(1, 9500);
		$this->txMapper->method('find')->willReturn($tx);
		$this->details->method('findByBankTx')->with(1)->willReturn([
			$this->detail(1, 1, 10, 4500, false, BankTxSepaDetail::STATUS_ASSIGNED),
			$this->detail(2, 1, 11, 5000, false, BankTxSepaDetail::STATUS_ASSIGNED),
		]);
		$this->debitItems->method('find')->willReturnMap([
			[10, $this->debitItem(10, 100, 4500)],
			[11, $this->debitItem(11, 101, 5000)],
		]);
		$this->openItems->method('find')->willReturnMap([
			[100, $this->openItem(100, 7, 4500, 42)],
			[101, $this->openItem(101, 8, 5000, 42)], // dasselbe Erlöskonto - muss summiert werden
		]);

		$capturedParts = null;
		$this->bookingService->method('assignParts')->willReturnCallback(function (BankTransaction $t, array $parts) use (&$capturedParts): BankTransaction {
			$capturedParts = $parts;
			$t->setJournalId(777);
			return $t;
		});

		$updated = [];
		$this->openItems->method('update')->willReturnCallback(function (OpenItem $o) use (&$updated): OpenItem {
			$updated[(int)$o->getId()] = $o;
			return $o;
		});

		$result = $this->service()->settle(1);

		$this->assertSame(['settled' => 2, 'returned' => 0], $result);
		$this->assertCount(1, $capturedParts, 'Beide Posten teilen sich dasselbe Erlöskonto - eine gemeinsame Zeile');
		$this->assertSame(['accountId' => 42, 'amountCents' => 9500], $capturedParts[0]);
		$this->assertSame('paid', $updated[100]->getStatus());
		$this->assertSame(777, $updated[100]->getPaidJournalId());
		$this->assertSame('paid', $updated[101]->getStatus());
	}

	public function testSammelbuchungOhneErloeskontoWirft(): void {
		$tx = $this->tx(1, 4500);
		$this->txMapper->method('find')->willReturn($tx);
		$this->details->method('findByBankTx')->with(1)->willReturn([
			$this->detail(1, 1, 10, 4500, false, BankTxSepaDetail::STATUS_ASSIGNED),
		]);
		$this->debitItems->method('find')->with(10)->willReturn($this->debitItem(10, 100, 4500));
		$this->openItems->method('find')->with(100)->willReturn($this->openItem(100, 7, 4500, null));
		$this->settings->method('contributionDefaultAccountId')->willReturn(null);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->settle(1);
	}

	public function testSammelbuchungFaelltAufStandardErloeskontoZurueck(): void {
		$tx = $this->tx(1, 4500);
		$this->txMapper->method('find')->willReturn($tx);
		$this->details->method('findByBankTx')->with(1)->willReturn([
			$this->detail(1, 1, 10, 4500, false, BankTxSepaDetail::STATUS_ASSIGNED),
		]);
		$this->debitItems->method('find')->with(10)->willReturn($this->debitItem(10, 100, 4500));
		$this->openItems->method('find')->with(100)->willReturn($this->openItem(100, 7, 4500, null));
		$this->settings->method('contributionDefaultAccountId')->willReturn(77);

		$capturedParts = null;
		$this->bookingService->method('assignParts')->willReturnCallback(function (BankTransaction $t, array $parts) use (&$capturedParts): BankTransaction {
			$capturedParts = $parts;
			$t->setJournalId(778);
			return $t;
		});

		$this->service()->settle(1);

		$this->assertSame(['accountId' => 77, 'amountCents' => 4500], $capturedParts[0]);
	}
}
