<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\BankTxSepaDetail;
use OCA\Vereinsbuchhaltung\Db\BankTxSepaDetailMapper;
use OCA\Vereinsbuchhaltung\Db\DebitBatch;
use OCA\Vereinsbuchhaltung\Db\DebitBatchMapper;
use OCA\Vereinsbuchhaltung\Db\DebitItem;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\IncomingPaymentRejection;
use OCA\Vereinsbuchhaltung\Db\IncomingPaymentRejectionMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Exception\PeriodClosedException;
use OCA\Vereinsbuchhaltung\Exception\SettlementBlockedException;
use OCA\Vereinsbuchhaltung\Service\AccountService;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Service\Sepa\BankReconciliationService;
use OCA\Vereinsbuchhaltung\Service\Sepa\IncomingPaymentMatchingService;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportConfirmationService;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportSettingsService;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaMatchingService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Die Arbeitsliste und die Buchungsvorschau des Bankabgleichs (Issue #105):
 * welche Umsätze auf ein Urteil warten (und welche nicht), in welchem Zustand,
 * mit welchen Kandidaten – und was „Verbuchen" täte, samt Hindernissen als
 * Daten. Die Rechnung der Buchung selbst steckt in
 * {@see SepaImportConfirmationService::plan()} (gemockt) und ist in
 * SepaImportConfirmationServiceTest geprüft.
 */
class BankReconciliationServiceTest extends TestCase {

	private BankTxSepaDetailMapper&MockObject $details;
	private BankTransactionMapper&MockObject $txMapper;
	private SepaMatchingService&MockObject $matching;
	private IncomingPaymentMatchingService&MockObject $incomingPayments;
	private IncomingPaymentRejectionMapper&MockObject $rejections;
	private SepaImportConfirmationService&MockObject $confirmation;
	private SepaImportSettingsService&MockObject $settings;
	private DebitItemMapper&MockObject $debitItems;
	private DebitBatchMapper&MockObject $batches;
	private OpenItemMapper&MockObject $openItems;
	private MandateMapper&MockObject $mandates;
	private MemberMapper&MockObject $members;
	private AccountMapper&MockObject $accounts;
	private AccountService&MockObject $accountService;
	private PeriodService&MockObject $periods;

	/** @var array<int, BankTransaction> */
	private array $txs = [];
	/** @var array<int, list<array{debitItemId:int, stage:int, reason:string}>> Detail-ID => Kandidaten */
	private array $candidates = [];

	protected function setUp(): void {
		$this->details = $this->createMock(BankTxSepaDetailMapper::class);
		$this->txMapper = $this->createMock(BankTransactionMapper::class);
		$this->txMapper->method('findByIds')->willReturnCallback(fn (string $userId, array $ids): array => array_intersect_key($this->txs, array_flip($ids)));
		$this->txMapper->method('find')->willReturnCallback(fn (int $id): BankTransaction => $this->txs[$id] ?? throw new DoesNotExistException('weg'));
		$this->matching = $this->createMock(SepaMatchingService::class);
		$this->matching->method('candidatesFor')->willReturnCallback(fn (BankTxSepaDetail $d): array => $this->candidates[(int)$d->getId()] ?? []);
		$this->incomingPayments = $this->createMock(IncomingPaymentMatchingService::class);
		$this->rejections = $this->createMock(IncomingPaymentRejectionMapper::class);
		$this->confirmation = $this->createMock(SepaImportConfirmationService::class);
		$this->settings = $this->createMock(SepaImportSettingsService::class);
		$this->debitItems = $this->createMock(DebitItemMapper::class);
		$this->batches = $this->createMock(DebitBatchMapper::class);
		$this->openItems = $this->createMock(OpenItemMapper::class);
		$this->mandates = $this->createMock(MandateMapper::class);
		$this->members = $this->createMock(MemberMapper::class);
		$this->accounts = $this->createMock(AccountMapper::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->periods = $this->createMock(PeriodService::class);
	}

	private function service(): BankReconciliationService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('kassenwart');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-10-10 10:00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new BankReconciliationService(
			$this->details,
			$this->txMapper,
			$this->matching,
			$this->incomingPayments,
			$this->rejections,
			$this->confirmation,
			$this->settings,
			$this->debitItems,
			$this->batches,
			$this->openItems,
			$this->mandates,
			$this->members,
			$this->accounts,
			$this->accountService,
			$this->periods,
			$session,
			$time,
			$l10n,
		);
	}

	// --- Bausteine -----------------------------------------------------------------

	private function tx(int $id, int $amountCents, string $bookingDate = '2026-10-10'): BankTransaction {
		$tx = new BankTransaction();
		$tx->setId($id);
		$tx->setAmountCents($amountCents);
		$tx->setBookingDate($bookingDate);
		$tx->setCounterparty('Testverein Bank');
		$tx->setPurpose('Sammelbuchung');
		$tx->setCounterpartyIban('DE02120300000000202051');
		$this->txs[$id] = $tx;
		return $tx;
	}

	private function detail(int $id, int $txId, int $amountCents, bool $isReturn = false, string $status = BankTxSepaDetail::STATUS_OPEN, ?int $debitItemId = null, ?string $mandateReference = 'MREF-1', ?string $code = null): BankTxSepaDetail {
		$detail = new BankTxSepaDetail();
		$detail->setId($id);
		$detail->setBankTxId($txId);
		$detail->setDetailIndex($id);
		$detail->setEndToEndId('E2E-' . $id);
		$detail->setMandateReference($mandateReference);
		$detail->setAmountCents($amountCents);
		$detail->setIsReturn($isReturn);
		$detail->setStatus($status);
		$detail->setDebitItemId($debitItemId);
		$detail->setReturnReasonCode($code);
		$detail->setReturnReasonText($code === null ? null : 'Freitext der Bank');
		return $detail;
	}

	private function debitItem(int $id, int $openItemId, int $amountCents, int $mandateId = 55): DebitItem {
		$item = new DebitItem();
		$item->setId($id);
		$item->setBatchId(9);
		$item->setOpenItemId($openItemId);
		$item->setMandateId($mandateId);
		$item->setAmountCents($amountCents);
		$item->setEndToEndId('E2E-ITEM-' . $id);
		$item->setMandateReference('MREF-1');
		$item->setRemittanceInfo('Beitrag laut Lauf');
		return $item;
	}

	private function claim(int $id, int $memberId, int $amountCents, ?int $accountId = 42, string $debtor = 'Mitglied Alt'): OpenItem {
		$item = new OpenItem();
		$item->setId($id);
		$item->setMemberId($memberId);
		$item->setType(OpenItem::TYPE_CONTRIBUTION);
		$item->setAmountCents($amountCents);
		$item->setAccountId($accountId);
		$item->setDebtor($debtor);
		$item->setDescription('Beitrag 2026');
		$item->setDueDate('2026-10-01');
		$item->setStatus('open');
		return $item;
	}

	private function member(int $id, string $first, string $last): Member {
		$member = new Member();
		$member->setId($id);
		$member->setMemberType(Member::TYPE_PERSON);
		$member->setFirstName($first);
		$member->setLastName($last);
		return $member;
	}

	private function account(int $id, string $number, string $name): Account {
		$account = new Account();
		$account->setId($id);
		$account->setNumber($number);
		$account->setName($name);
		return $account;
	}

	/** @param list<BankTxSepaDetail> $details */
	private function givenDetails(array $details): void {
		$this->details->method('findOnUnassignedTransactions')->willReturn($details);
		$this->details->method('findByBankTx')->willReturnCallback(static fn (int $txId): array => array_values(array_filter($details, static fn (BankTxSepaDetail $d): bool => $d->getBankTxId() === $txId)));
	}

	private function givenItems(DebitItem ...$items): void {
		$byId = [];
		foreach ($items as $item) {
			$byId[(int)$item->getId()] = $item;
		}
		$this->debitItems->method('findByIds')->willReturn($byId);
	}

	/** @param array<int, OpenItem> $byId */
	private function givenClaims(array $byId): void {
		$this->openItems->method('findByIds')->willReturnCallback(static fn (array $ids): array => array_intersect_key($byId, array_flip($ids)));
		$this->openItems->method('find')->willReturnCallback(static fn (int $id): OpenItem => $byId[$id] ?? throw new DoesNotExistException('weg'));
	}

	private function givenBatch(string $status = DebitBatch::STATUS_SUBMITTED): void {
		$batch = new DebitBatch();
		$batch->setId(9);
		$batch->setDueDate('2026-10-05');
		$batch->setStatus($status);
		$this->batches->method('findAll')->willReturn([$batch]);
	}

	private function givenMembers(Member ...$members): void {
		$this->members->method('findAll')->willReturn($members);
	}

	// --- Arbeitsliste: Zustand, Art, Fortschritt -------------------------------------

	public function testSammlerMitUnbeurteilterZeileWartetAufUrteil(): void {
		$this->tx(1, 9000);
		$this->givenDetails([
			$this->detail(1, 1, 4500, status: BankTxSepaDetail::STATUS_ASSIGNED, debitItemId: 10),
			$this->detail(2, 1, 4500),
		]);
		$this->candidates[1] = [['debitItemId' => 10, 'stage' => 1, 'reason' => 'x']];
		$this->candidates[2] = [['debitItemId' => 11, 'stage' => 1, 'reason' => 'x']];
		$this->givenItems($this->debitItem(10, 100, 4500), $this->debitItem(11, 101, 4500));
		$this->givenClaims([100 => $this->claim(100, 7, 4500), 101 => $this->claim(101, 8, 4500)]);
		$this->givenBatch();
		$this->givenMembers($this->member(7, 'Max', 'Muster'), $this->member(8, 'Erika', 'Beispiel'));

		$list = $this->service()->worklist(true)['items'];

		$this->assertCount(1, $list);
		$this->assertSame(BankReconciliationService::KIND_COLLECTION, $list[0]['kind']);
		$this->assertSame(BankReconciliationService::STATE_OPEN, $list[0]['state'], 'eine Zeile ohne Urteil: der Umsatz wartet');
		$this->assertCount(2, $list[0]['details']);
	}

	/** Ist die letzte Zeile beurteilt, bleibt der Umsatz in der Liste – bereit zum Verbuchen (genau das kann `pending()` nicht). */
	public function testVollstaendigBeurteilterSammlerBleibtAlsBereitInDerListe(): void {
		$this->tx(1, 4500);
		$this->givenDetails([$this->detail(1, 1, 4500, status: BankTxSepaDetail::STATUS_ASSIGNED, debitItemId: 10)]);
		$this->givenItems($this->debitItem(10, 100, 4500));
		$this->givenClaims([100 => $this->claim(100, 7, 4500)]);
		$this->givenBatch();
		$this->givenMembers($this->member(7, 'Max', 'Muster'));

		$list = $this->service()->worklist(true)['items'];

		$this->assertSame(BankReconciliationService::STATE_READY, $list[0]['state']);
	}

	public function testNurAbgelehnteZeilenSindOhneZuordnungUndRutschenANsEnde(): void {
		$this->tx(1, 4500, '2026-10-01');
		$this->tx(2, 4500, '2026-10-09');
		$this->givenDetails([
			$this->detail(1, 1, 4500, status: BankTxSepaDetail::STATUS_REJECTED),
			$this->detail(2, 2, 4500),
		]);
		$this->givenItems();
		$this->givenClaims([]);
		$this->givenBatch();
		$this->givenMembers();

		$list = $this->service()->worklist(true)['items'];

		$this->assertSame([2, 1], array_map(static fn (array $i): int => $i['bankTx']['id'], $list), 'offen zuerst, ohne Zuordnung zuletzt');
		$this->assertSame(BankReconciliationService::STATE_NO_MATCH, $list[1]['state']);
	}

	public function testRuecklastschriftIstEineRueckgabeUndTragtKlasseUndFolgen(): void {
		$this->tx(1, -4500);
		$this->givenDetails([$this->detail(1, 1, -4500, isReturn: true, code: 'AC04')]);
		$this->candidates[1] = [['debitItemId' => 10, 'stage' => 1, 'reason' => 'x']];
		$this->givenItems($this->debitItem(10, 100, 4500));
		$this->givenClaims([100 => $this->claim(100, 7, 4500)]);
		$this->givenBatch();
		$this->givenMembers($this->member(7, 'Max', 'Muster'));
		$this->settings->method('isReturnFeeRechargeEnabled')->willReturn(false);

		$entry = $this->service()->worklist(true)['items'][0];

		$this->assertSame(BankReconciliationService::KIND_RETURN, $entry['kind']);
		$return = $entry['details'][0]['return'];
		$this->assertSame('account_unusable', $return['reasonClass']);
		$this->assertTrue($return['suspendsMandate']);
		$this->assertTrue($return['paymentRequest']);
		$this->assertNull($return['feeClaimCents']);
	}

	/** Spec §3.6 „Codes bleiben admin-only": für Leser fehlen Code und Bank-Freitext ganz, die Klasse bleibt. */
	public function testRueckgabecodeNurFuerDieBuchhaltung(): void {
		$this->tx(1, -4500);
		$this->givenDetails([$this->detail(1, 1, -4500, isReturn: true, code: 'AM04')]);
		$this->givenItems();
		$this->givenClaims([]);
		$this->givenBatch();
		$this->givenMembers();

		$service = $this->service();
		$forWriter = $service->worklist(true)['items'][0]['details'][0]['return'];
		$forReader = $service->worklist(false)['items'][0]['details'][0]['return'];

		$this->assertSame('AM04', $forWriter['reasonCode']);
		$this->assertSame('Freitext der Bank', $forWriter['reasonText']);
		$this->assertArrayNotHasKey('reasonCode', $forReader);
		$this->assertArrayNotHasKey('reasonText', $forReader);
		$this->assertSame('insufficient_funds', $forReader['reasonClass']);
	}

	public function testGebuehrenForderungNurBeiOptInKlasseUndGebuehrenkonto(): void {
		$this->tx(1, -5000);
		$detail = $this->detail(1, 1, -5000, isReturn: true, code: 'AM04');
		$detail->setChargesCents(500);
		$this->givenDetails([$detail]);
		$this->givenItems();
		$this->givenClaims([]);
		$this->givenBatch();
		$this->givenMembers();
		$this->settings->method('isReturnFeeRechargeEnabled')->willReturn(true);
		$this->settings->method('returnFeeAccountId')->willReturn(99);

		$return = $this->service()->worklist(true)['items'][0]['details'][0]['return'];

		$this->assertSame(500, $return['feeClaimCents']);
	}

	// --- Arbeitsliste: Kandidaten -----------------------------------------------------

	public function testKandidatenTragenMitgliedForderungBetragUndEinzugstermin(): void {
		$this->tx(1, 4500);
		$this->givenDetails([$this->detail(1, 1, 4500)]);
		$this->candidates[1] = [
			['debitItemId' => 10, 'stage' => 2, 'reason' => 'x'],
			['debitItemId' => 11, 'stage' => 2, 'reason' => 'x'],
		];
		$this->givenItems($this->debitItem(10, 100, 4500), $this->debitItem(11, 101, 4500));
		$this->givenClaims([100 => $this->claim(100, 7, 4500), 101 => $this->claim(101, 8, 4500)]);
		$this->givenBatch();
		$this->givenMembers($this->member(7, 'Max', 'Muster'), $this->member(8, 'Erika', 'Beispiel'));

		$candidates = $this->service()->worklist(true)['items'][0]['details'][0]['candidates'];

		$this->assertCount(2, $candidates, 'Mehrdeutigkeit: alle Kandidaten, keiner vorausgewählt');
		$this->assertSame('Max Muster', $candidates[0]['memberName']);
		$this->assertSame('Beitrag 2026', $candidates[0]['description']);
		$this->assertSame(4500, $candidates[0]['amountCents']);
		$this->assertSame('2026-10-05', $candidates[0]['dueDate']);
		$this->assertSame(2, $candidates[0]['stage']);
		$this->assertArrayNotHasKey('confidence', $candidates[0], 'keine Konfidenz-Zahl (Spec §5)');
		$this->assertArrayNotHasKey('reason', $candidates[0], 'die Begründung schreibt die Oberfläche nach Stufe');
	}

	public function testKandidatOhneForderungFaelltWegStattZuScheitern(): void {
		$this->tx(1, 4500);
		$this->givenDetails([$this->detail(1, 1, 4500)]);
		$this->candidates[1] = [['debitItemId' => 10, 'stage' => 2, 'reason' => 'x']];
		$this->givenItems($this->debitItem(10, 100, 4500));
		$this->givenClaims([]); // zurückgesetzter Bestand: der Posten zeigt auf eine gelöschte Forderung
		$this->givenBatch();
		$this->givenMembers();

		$candidates = $this->service()->worklist(true)['items'][0]['details'][0]['candidates'];

		$this->assertSame([], $candidates);
	}

	// --- Arbeitsliste: was keine SEPA-Zeile ist ------------------------------------------

	/** Eine gewöhnliche Überweisung trägt in camt nur eine EndToEndId („NOTPROVIDED"): das ist kein Einzug und keine Rückgabe. */
	public function testUeberweisungMitNurEndToEndIdIstKeinSepaUmsatz(): void {
		$this->tx(1, 4500);
		$this->givenDetails([$this->detail(1, 1, 4500, mandateReference: null)]);
		$this->givenItems();
		$this->givenClaims([]);
		$this->givenBatch();
		$this->givenMembers();

		$this->assertSame([], $this->service()->worklist(true)['items']);
	}

	public function testDetailsOhneUmsatzWerdenUebergangen(): void {
		// Waise nach einem Zurücksetzen: Detail-Zeile ohne Bankumsatz.
		$this->givenDetails([$this->detail(1, 77, 4500)]);
		$this->givenItems();
		$this->givenClaims([]);
		$this->givenBatch();
		$this->givenMembers();

		$this->assertSame([], $this->service()->worklist(true)['items']);
	}

	// --- Zahlungseingänge ---------------------------------------------------------------

	public function testZahlungseingangMitVorschlagUndErloeskonto(): void {
		$this->givenDetails([]);
		$this->tx(5, 6000);
		$this->txMapper->method('findFiltered')->willReturn([$this->txs[5]]);
		$this->incomingPayments->method('suggestForMany')->willReturn([5 => [['openItemId' => 100, 'memberId' => 7, 'reason' => 'Betrag 60,00 € passt …']]]);
		$this->givenClaims([100 => $this->claim(100, 7, 6000, 42)]);
		$this->givenItems();
		$this->givenBatch();
		$this->givenMembers($this->member(7, 'Max', 'Muster'));
		$this->accounts->method('findAll')->willReturn([$this->account(42, '4000', 'Mitgliedsbeiträge')]);
		$this->confirmation->method('revenueAccountIdOrNull')->willReturn(42);

		$incoming = $this->service()->worklist(true)['incoming'];

		$this->assertCount(1, $incoming);
		$this->assertSame(5, $incoming[0]['bankTx']['id']);
		$suggestion = $incoming[0]['suggestions'][0];
		$this->assertSame('Max Muster', $suggestion['memberName']);
		$this->assertSame('Beitrag 2026', $suggestion['description']);
		$this->assertSame(['accountId' => 42, 'number' => '4000', 'name' => 'Mitgliedsbeiträge'], $suggestion['revenueAccount']);
	}

	public function testUmsatzDerSchonAlsEinzugAnstehtErscheintNichtAlsZahlungseingang(): void {
		$this->tx(1, 4500);
		$this->givenDetails([$this->detail(1, 1, 4500)]);
		$this->candidates[1] = [['debitItemId' => 10, 'stage' => 1, 'reason' => 'x']];
		$this->givenItems($this->debitItem(10, 100, 4500));
		$this->givenClaims([100 => $this->claim(100, 7, 4500)]);
		$this->givenBatch();
		$this->givenMembers($this->member(7, 'Max', 'Muster'));
		$this->txMapper->method('findFiltered')->willReturn([$this->txs[1]]);
		$this->incomingPayments->expects($this->once())->method('suggestForMany')->with([])->willReturn([]);

		$result = $this->service()->worklist(true);

		$this->assertCount(1, $result['items']);
		$this->assertSame([], $result['incoming']);
	}

	public function testAblehnenMerktSichDasPaarMitUrheber(): void {
		$this->tx(5, 6000);
		$this->givenClaims([100 => $this->claim(100, 7, 6000)]);
		$this->rejections->method('exists')->willReturn(false);
		$this->rejections->expects($this->once())->method('insert')->with($this->callback(
			static fn (IncomingPaymentRejection $r): bool => $r->getBankTxId() === 5 && $r->getOpenItemId() === 100 && $r->getRejectedBy() === 'kassenwart' && $r->getRejectedAt() === '2026-10-10 10:00:00',
		));

		$this->service()->rejectIncomingPayment(5, 100);
	}

	public function testErneutesAblehnenIstFolgenlos(): void {
		$this->tx(5, 6000);
		$this->givenClaims([100 => $this->claim(100, 7, 6000)]);
		$this->rejections->method('exists')->willReturn(true);
		$this->rejections->expects($this->never())->method('insert');

		$this->service()->rejectIncomingPayment(5, 100);
	}

	public function testAblehnenMeldetEinenUnbekanntenUmsatz(): void {
		$this->givenClaims([]);

		$this->expectException(DoesNotExistException::class);
		$this->service()->rejectIncomingPayment(404, 100);
	}

	// --- Vorschau der Buchung -----------------------------------------------------------

	/**
	 * @param list<array{detail:BankTxSepaDetail, debitItem:DebitItem, openItem:OpenItem, accountId:int}> $rows
	 * @param list<array{accountId:int, amountCents:int}> $parts
	 */
	private function givenPlan(string $direction, array $rows, array $parts, int $chargesCents = 0): void {
		$this->confirmation->method('plan')->willReturn([
			'direction' => $direction,
			'rows' => $rows,
			'parts' => $parts,
			'chargesCents' => $chargesCents,
			'feeAccountId' => $chargesCents > 0 ? 99 : null,
		]);
		$this->accounts->method('findAll')->willReturn([
			$this->account(1, '1200', 'Bank'),
			$this->account(42, '4000', 'Mitgliedsbeiträge'),
			$this->account(43, '4100', 'Spenden'),
			$this->account(99, '6800', 'Bankgebühren'),
		]);
		$this->accountService->method('resolveBankAccount')->willReturn($this->account(1, '1200', 'Bank'));
		$this->givenMembers($this->member(7, 'Max', 'Muster'));
	}

	/** @return array{detail:BankTxSepaDetail, debitItem:DebitItem, openItem:OpenItem, accountId:int} */
	private function row(BankTxSepaDetail $detail, int $accountId = 42, int $amountCents = 4500): array {
		return ['detail' => $detail, 'debitItem' => $this->debitItem((int)$detail->getDebitItemId(), 100, $amountCents), 'openItem' => $this->claim(100, 7, $amountCents, $accountId), 'accountId' => $accountId];
	}

	public function testVorschauDerSammelbuchungBankImSollErloeskontenImHaben(): void {
		$this->tx(1, 9000);
		$this->givenPlan(
			SepaImportConfirmationService::DIRECTION_COLLECTION,
			[$this->row($this->detail(1, 1, 4500, debitItemId: 10)), $this->row($this->detail(2, 1, 4500, debitItemId: 11), 43)],
			[['accountId' => 42, 'amountCents' => 4500], ['accountId' => 43, 'amountCents' => 4500]],
		);

		$preview = $this->service()->settlementPreview(1, true);

		$this->assertSame([], $preview['blockers']);
		$this->assertSame('collection', $preview['direction']);
		$this->assertSame('2026-10-10', $preview['bookingDate'], 'Buchungsdatum ist das des Bankumsatzes');
		$this->assertSame('1200', $preview['bank']['number']);
		$this->assertSame(9000, $preview['amountCents']);
		$this->assertCount(2, $preview['lines']);
		$this->assertSame(['haben', 'revenue', 4500, '4000'], [$preview['lines'][0]['side'], $preview['lines'][0]['role'], $preview['lines'][0]['amountCents'], $preview['lines'][0]['number']]);
		$this->assertSame('4100', $preview['lines'][1]['number']);
		$this->assertCount(2, $preview['rows']);
		$this->assertNull($preview['rows'][0]['return']);
	}

	public function testVorschauDerRuecklastschriftHatZweiGegenkontoZeilenMitGebuehr(): void {
		$this->tx(1, -5000);
		$withFee = $this->detail(1, 1, -5000, isReturn: true, status: BankTxSepaDetail::STATUS_ASSIGNED, debitItemId: 10, code: 'AM04');
		$withFee->setChargesCents(500);
		$this->givenPlan(
			SepaImportConfirmationService::DIRECTION_RETURN,
			[$this->row($withFee)],
			[['accountId' => 42, 'amountCents' => 4500], ['accountId' => 99, 'amountCents' => 500]],
			500,
		);
		$this->settings->method('isReturnFeeRechargeEnabled')->willReturn(true);
		$this->settings->method('returnFeeAccountId')->willReturn(99);
		$mandate = new Mandate();
		$mandate->setStatus(Mandate::STATUS_ACTIVE);
		$this->mandates->method('findOrNull')->willReturn($mandate);

		$preview = $this->service()->settlementPreview(1, true);

		$this->assertSame([], $preview['blockers']);
		$this->assertSame('return', $preview['direction']);
		$this->assertCount(2, $preview['lines']);
		$this->assertSame(['soll', 'revenue_back', '4000', 4500], [$preview['lines'][0]['side'], $preview['lines'][0]['role'], $preview['lines'][0]['number'], $preview['lines'][0]['amountCents']]);
		$this->assertSame(['soll', 'fee', '6800', 500], [$preview['lines'][1]['side'], $preview['lines'][1]['role'], $preview['lines'][1]['number'], $preview['lines'][1]['amountCents']]);
		$return = $preview['rows'][0]['return'];
		$this->assertSame('insufficient_funds', $return['reasonClass']);
		$this->assertFalse($return['suspendsMandate'], 'Deckung fehlt sperrt das Mandat nicht');
		$this->assertTrue($return['paymentRequest']);
		$this->assertSame(500, $return['feeClaimCents']);
		$this->assertSame('AM04', $return['reasonCode']);
	}

	/** Die Verbuchung lässt ein schon gesperrtes Mandat unangetastet – die Vorschau verspricht dann auch keine Sperre. */
	public function testVorschauVersprichtKeineSperreFuerEinBereitsGesperrtesMandat(): void {
		$this->tx(1, -4500);
		$this->givenPlan(
			SepaImportConfirmationService::DIRECTION_RETURN,
			[$this->row($this->detail(1, 1, -4500, isReturn: true, status: BankTxSepaDetail::STATUS_ASSIGNED, debitItemId: 10, code: 'AC04'))],
			[['accountId' => 42, 'amountCents' => 4500]],
		);
		$this->settings->method('isReturnFeeRechargeEnabled')->willReturn(false);
		$mandate = new Mandate();
		$mandate->setStatus(Mandate::STATUS_SUSPENDED);
		$this->mandates->method('findOrNull')->willReturn($mandate);

		$return = $this->service()->settlementPreview(1, true)['rows'][0]['return'];

		$this->assertFalse($return['suspendsMandate']);
		$this->assertTrue($return['paymentRequest']);
	}

	public function testVorschauOhneRueckgabecodeFuerLeser(): void {
		$this->tx(1, -4500);
		$this->givenPlan(
			SepaImportConfirmationService::DIRECTION_RETURN,
			[$this->row($this->detail(1, 1, -4500, isReturn: true, status: BankTxSepaDetail::STATUS_ASSIGNED, debitItemId: 10, code: 'AM04'))],
			[['accountId' => 42, 'amountCents' => 4500]],
		);
		$this->settings->method('isReturnFeeRechargeEnabled')->willReturn(false);
		$this->mandates->method('findOrNull')->willReturn(null);

		$return = $this->service()->settlementPreview(1, false)['rows'][0]['return'];

		$this->assertArrayNotHasKey('reasonCode', $return);
		$this->assertArrayNotHasKey('reasonText', $return);
	}

	public function testGeschlossenePeriodeIstEinHindernisMitText(): void {
		$this->tx(1, 4500, '2025-03-01');
		$this->givenPlan(
			SepaImportConfirmationService::DIRECTION_COLLECTION,
			[$this->row($this->detail(1, 1, 4500, debitItemId: 10))],
			[['accountId' => 42, 'amountCents' => 4500]],
		);
		$this->periods->method('assertOpen')->with($this->anything(), '2025-03-01')->willThrowException(new PeriodClosedException('Das Geschäftsjahr 2025 ist abgeschlossen.'));

		$preview = $this->service()->settlementPreview(1, true);

		$this->assertSame([BankReconciliationService::BLOCKER_PERIOD_CLOSED], array_column($preview['blockers'], 'code'));
		$this->assertSame('Das Geschäftsjahr 2025 ist abgeschlossen.', $preview['blockers'][0]['message']);
	}

	public function testNichtAufgehendeAufteilungErklaertDenFehlbetrag(): void {
		$this->tx(1, 9000);
		$this->givenPlan(
			SepaImportConfirmationService::DIRECTION_COLLECTION,
			[$this->row($this->detail(1, 1, 4500, debitItemId: 10))],
			[['accountId' => 42, 'amountCents' => 4500]],
		);

		$blockers = $this->service()->settlementPreview(1, true)['blockers'];

		$this->assertSame([BankReconciliationService::BLOCKER_SUM_MISMATCH], array_column($blockers, 'code'));
		$this->assertStringContainsString('45,00 €', $blockers[0]['message']);
		$this->assertStringContainsString('90,00 €', $blockers[0]['message']);
	}

	public function testGebuehrenkontoGleichErloeskontoIstEinHindernis(): void {
		$this->tx(1, -5000);
		$this->givenPlan(
			SepaImportConfirmationService::DIRECTION_RETURN,
			[$this->row($this->detail(1, 1, -5000, isReturn: true, status: BankTxSepaDetail::STATUS_ASSIGNED, debitItemId: 10, code: 'AM04'), 42, 4500)],
			[['accountId' => 42, 'amountCents' => 4500], ['accountId' => 42, 'amountCents' => 500]],
			500,
		);
		$this->settings->method('isReturnFeeRechargeEnabled')->willReturn(false);
		$this->mandates->method('findOrNull')->willReturn(null);

		$blockers = $this->service()->settlementPreview(1, true)['blockers'];

		$this->assertSame([BankReconciliationService::BLOCKER_FEE_ACCOUNT_CONFLICT], array_column($blockers, 'code'));
	}

	public function testPlanHindernisseKommenAlsDatenMitCode(): void {
		$this->tx(1, 4500);
		$this->confirmation->method('plan')->willThrowException(new SettlementBlockedException('Es sind noch nicht alle Detail-Vorschläge dieses Bankumsatzes beurteilt.', SettlementBlockedException::REASON_UNDECIDED));

		$preview = $this->service()->settlementPreview(1, true);

		$this->assertSame([SettlementBlockedException::REASON_UNDECIDED], array_column($preview['blockers'], 'code'));
		$this->assertNull($preview['direction']);
		$this->assertSame([], $preview['lines']);
	}

	public function testBereitsAlsRuecklastschriftVerbuchteZeilenSindEinHinweisUndNichtsMehrZuBuchenEinHindernis(): void {
		$this->tx(1, -4500);
		$assigned = $this->detail(1, 1, -4500, isReturn: true, status: BankTxSepaDetail::STATUS_ASSIGNED, debitItemId: 10, code: 'AM04');
		$this->givenDetails([$assigned]);
		$this->givenPlan(SepaImportConfirmationService::DIRECTION_RETURN, [], []);

		$preview = $this->service()->settlementPreview(1, true);

		$this->assertSame([BankReconciliationService::BLOCKER_NOTHING_TO_BOOK], array_column($preview['blockers'], 'code'));
	}

	public function testVorschauMeldetEinenUnbekanntenUmsatz(): void {
		$this->expectException(DoesNotExistException::class);
		$this->service()->settlementPreview(404, true);
	}
}
