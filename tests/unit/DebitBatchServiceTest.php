<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\DebitBatch;
use OCA\Vereinsbuchhaltung\Db\DebitBatchMapper;
use OCA\Vereinsbuchhaltung\Db\DebitItem;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateAmendment;
use OCA\Vereinsbuchhaltung\Db\MandateAmendmentMapper;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\DebitBatchService;
use OCA\Vereinsbuchhaltung\Service\DebitBatchStateMachine;
use OCA\Vereinsbuchhaltung\Service\DebitBatchXmlStorageService;
use OCA\Vereinsbuchhaltung\Service\DebitRunQueryService;
use OCA\Vereinsbuchhaltung\Service\MandateService;
use OCA\Vereinsbuchhaltung\Service\Sepa\PainXmlBuilder;
use OCA\Vereinsbuchhaltung\Service\SepaDebtorAccountService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Freigabe-Gate des Einzugszyklus (Spec §2.2/§3.5, Issue #71): Snapshot +
 * pain.008 entstehen bei {@see DebitBatchService::release()} gemeinsam,
 * Einreichung ist ein zweiter Schritt. Schwerpunkt dieses Tests: die
 * Statusmaschine im Zusammenspiel mit echten Daten, byte-identisches
 * Nachrendern und XSD-Validierung der erzeugten pain.008-Datei.
 *
 * {@see DebitRunQueryService}/{@see MandateService} sind gemockt (deren
 * eigenes Verhalten prüfen {@see \OCA\Vereinsbuchhaltung\Tests\Unit\MandateServiceTest}
 * und die künftigen DebitRunQueryService-Tests aus Issue #70) –
 * DebitBatchService orchestriert nur, siehe Klassendoc dort.
 */
class DebitBatchServiceTest extends TestCase {

	private const SCHEMA = __DIR__ . '/../schema/pain.008.001.02.xsd';

	private DebitBatchMapper&MockObject $batchMapper;
	private DebitItemMapper&MockObject $itemMapper;
	private OpenItemMapper&MockObject $openItems;
	private DebitRunQueryService&MockObject $runQuery;
	private MandateMapper&MockObject $mandateMapper;
	private MandateAmendmentMapper&MockObject $amendmentMapper;
	private MandateService&MockObject $mandateService;
	private MemberMapper&MockObject $memberMapper;
	private AccountMapper&MockObject $accountMapper;
	private SepaDebtorAccountService&MockObject $debtorAccount;
	private DebitBatchXmlStorageService&MockObject $xmlStorage;
	private TransactionRunner&MockObject $transaction;
	private AuditService&MockObject $audit;
	private IConfig&MockObject $config;

	/** @var array<int, MandateAmendment[]> siehe amendmentMapper-Stub in setUp() */
	private array $openAmendmentsByMandate = [];

	protected function setUp(): void {
		$this->batchMapper = $this->createMock(DebitBatchMapper::class);
		$this->itemMapper = $this->createMock(DebitItemMapper::class);
		$this->openItems = $this->createMock(OpenItemMapper::class);
		$this->runQuery = $this->createMock(DebitRunQueryService::class);
		$this->mandateMapper = $this->createMock(MandateMapper::class);
		$this->amendmentMapper = $this->createMock(MandateAmendmentMapper::class);
		$this->mandateService = $this->createMock(MandateService::class);
		$this->memberMapper = $this->createMock(MemberMapper::class);
		$this->accountMapper = $this->createMock(AccountMapper::class);
		$this->debtorAccount = $this->createMock(SepaDebtorAccountService::class);
		$this->xmlStorage = $this->createMock(DebitBatchXmlStorageService::class);
		$this->xmlStorage->method('isEnabled')->willReturn(false);
		$this->transaction = $this->createMock(TransactionRunner::class);
		$this->transaction->method('run')->willReturnCallback(static fn (callable $fn) => $fn());
		$this->audit = $this->createMock(AuditService::class);
		$this->config = $this->createMock(IConfig::class);

		// Callback statt zweier method()->with()-Stubs: mehrere with()-Varianten
		// auf demselben Methodennamen werden von PHPUnit in Registrierungs-
		// reihenfolge geprueft, ein spaeter im Testfall registriertes,
		// spezifischeres with(3) wuerde vom hier zuerst registrierten
		// "matcht immer"-Stub verdeckt (siehe openAmendmentsByMandate unten).
		$this->amendmentMapper->method('findOpenByMandate')->willReturnCallback(
			fn (int $mandateId) => $this->openAmendmentsByMandate[$mandateId] ?? [],
		);
		$this->batchMapper->method('insert')->willReturnCallback(static function (DebitBatch $b): DebitBatch {
			if ($b->getId() === null) {
				$b->setId(random_int(1000, 9999));
			}
			return $b;
		});
		$this->batchMapper->method('update')->willReturnArgument(0);
		$this->itemMapper->method('insert')->willReturnCallback(static function (DebitItem $i): DebitItem {
			if ($i->getId() === null) {
				$i->setId(random_int(1000, 9999));
			}
			return $i;
		});
	}

	private function service(): DebitBatchService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('kassenwart');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$l10n->method('n')->willReturnCallback(static function (string $singular, string $plural, int $count, array $parameters = []): string {
			$text = str_replace('%n', (string)$count, $count === 1 ? $singular : $plural);
			return vsprintf($text, $parameters);
		});

		return new DebitBatchService(
			$this->batchMapper,
			$this->itemMapper,
			$this->openItems,
			$this->runQuery,
			$this->mandateMapper,
			$this->amendmentMapper,
			$this->mandateService,
			$this->memberMapper,
			new DebitBatchStateMachine($this->createMock(IL10N::class)),
			$this->accountMapper,
			$this->debtorAccount,
			new PainXmlBuilder(),
			$this->xmlStorage,
			$this->transaction,
			$this->audit,
			$userSession,
			$this->config,
			$l10n,
		);
	}

	/** Grundeinstellungen, die release() vor jeder Freigabe prüft. */
	private function configureBaseSettings(string $iban = 'DE12500105170648489890'): void {
		$this->config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, string $default = ''): string {
			return match ($key) {
				'sepa_creditor_id' => 'DE98ZZZ09999999999',
				'club_name' => 'Testverein e. V.',
				default => $default,
			};
		});
		$this->debtorAccount->method('getAccountId')->willReturn(5);
		$account = new Account();
		$account->setId(5);
		$account->setIban($iban);
		$this->accountMapper->method('find')->with(5, \OCA\Vereinsbuchhaltung\AppInfo\Application::BOOK)->willReturn($account);
	}

	private function claim(int $id, int $memberId, int $amountCents, string $dueDate = '2026-10-01', string $description = 'Vereinsbeitrag'): OpenItem {
		$item = new OpenItem();
		$item->setId($id);
		$item->setMemberId($memberId);
		$item->setType(OpenItem::TYPE_CONTRIBUTION);
		$item->setAmountCents($amountCents);
		$item->setDueDate($dueDate);
		$item->setDescription($description);
		$item->setStatus('open');
		$item->setDebtor('Katrin Brunner');
		$item->setCreatedAt('2026-01-01T00:00:00+00:00');
		return $item;
	}

	private function mandate(int $id, int $memberId, string $iban = 'DE02120300000000202051', ?string $bic = 'BYLADEM1001', string $holder = 'Katrin Brunner', string $reference = 'M20260101-ABCDEF', string $signedAt = '2024-01-01'): Mandate {
		$m = new Mandate();
		$m->setId($id);
		$m->setMemberId($memberId);
		$m->setIban($iban);
		$m->setBic($bic);
		$m->setAccountHolder($holder);
		$m->setMandateReference($reference);
		$m->setSignedAt($signedAt);
		$m->setStatus(Mandate::STATUS_ACTIVE);
		$m->setCreatedAt('2024-01-01T00:00:00+00:00');
		return $m;
	}

	// --- Freigabe (Schritt 1) ------------------------------------------------------

	public function testReleaseErzeugtBatchUndItemMitSnapshot(): void {
		$this->configureBaseSettings();
		$claim = $this->claim(10, 1, 4250, '2026-10-01', 'Vereinsbeitrag');
		$this->runQuery->method('preview')->with('2026-10-01')->willReturn([$claim]);
		$mandate = $this->mandate(3, 1);
		$this->mandateMapper->method('findLiveByMember')->with(1)->willReturn([$mandate]);

		/** @var DebitItem|null $createdItem */
		$createdItem = null;
		$this->itemMapper->method('insert')->willReturnCallback(function (DebitItem $item) use (&$createdItem): DebitItem {
			$item->setId(99);
			$createdItem = $item;
			return $item;
		});

		$batch = $this->service()->release('2026-10-01');

		$this->assertSame(DebitBatch::STATUS_RELEASED, $batch->getStatus());
		$this->assertSame('2026-10-01', $batch->getDueDate());
		$this->assertSame('kassenwart', $batch->getReleasedBy());
		$this->assertNotNull($batch->getReleasedAt());
		$this->assertNotNull($batch->getMsgId());
		$this->assertNotNull($batch->getCreationDateTime());
		$this->assertSame('DE98ZZZ09999999999', $batch->getCreditorId());
		$this->assertSame('Testverein e. V.', $batch->getCreditorName());
		$this->assertSame('DE12500105170648489890', $batch->getCreditorIban());
		$this->assertNull($batch->getCreditorBic());

		$this->assertNotNull($createdItem);
		$this->assertSame(10, $createdItem->getOpenItemId());
		$this->assertSame(3, $createdItem->getMandateId());
		$this->assertSame(4250, $createdItem->getAmountCents());
		$this->assertSame('DE02120300000000202051', $createdItem->getIban());
		$this->assertSame('BYLADEM1001', $createdItem->getBic());
		$this->assertSame('Katrin Brunner', $createdItem->getAccountHolder());
		$this->assertSame('M20260101-ABCDEF', $createdItem->getMandateReference());
		$this->assertSame('2024-01-01', $createdItem->getSignedDate());
		$this->assertSame('RCUR', $createdItem->getSequenceType());
		$this->assertSame('Vereinsbeitrag', $createdItem->getRemittanceInfo());
		$this->assertNotNull($createdItem->getEndToEndId());
		$this->assertFalse($createdItem->getAmendmentIndicator());
		$this->assertNull($createdItem->getOriginalDebtorAccount());
	}

	public function testReleaseMitOffenemKontowechselAmendmentSetztSmnda(): void {
		$this->configureBaseSettings();
		$claim = $this->claim(10, 1, 1000);
		$this->runQuery->method('preview')->willReturn([$claim]);
		$this->mandateMapper->method('findLiveByMember')->willReturn([$this->mandate(3, 1)]);

		$amendment = new MandateAmendment();
		$amendment->setId(7);
		$amendment->setMandateId(3);
		$amendment->setType(MandateAmendment::TYPE_ACCOUNT);
		$amendment->setStatus(MandateAmendment::STATUS_OPEN);
		$amendment->setCreatedAt('2026-01-01T00:00:00+00:00');
		$this->openAmendmentsByMandate[3] = [$amendment];

		/** @var DebitItem|null $createdItem */
		$createdItem = null;
		$this->itemMapper->method('insert')->willReturnCallback(function (DebitItem $item) use (&$createdItem): DebitItem {
			$item->setId(99);
			$createdItem = $item;
			return $item;
		});

		$this->service()->release('2026-10-01');

		$this->assertTrue($createdItem->getAmendmentIndicator());
		$this->assertSame('SMNDA', $createdItem->getOriginalDebtorAccount());
	}

	public function testReleaseWirftWennNichtsFaelligIst(): void {
		$this->configureBaseSettings();
		$this->runQuery->method('preview')->willReturn([]);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->release('2026-10-01');
	}

	public function testReleaseWirftWennGlaeubigerIdFehlt(): void {
		$this->config->method('getAppValue')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->release('2026-10-01');
	}

	public function testReleaseWirftBeiUngueltigemDatum(): void {
		$this->configureBaseSettings();
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->release('nicht-ein-datum');
	}

	// --- Byte-identisches Nachrendern + XSD-Validierung -----------------------------

	public function testRenderXmlIstByteIdentischUndSchemakonform(): void {
		$this->configureBaseSettings();
		$claim = $this->claim(10, 1, 4250);
		$this->runQuery->method('preview')->willReturn([$claim]);
		$this->mandateMapper->method('findLiveByMember')->willReturn([$this->mandate(3, 1)]);

		$service = $this->service();
		$batch = $service->release('2026-10-01');

		// findByBatch() liefert ab jetzt konsistent denselben (eingefrorenen)
		// Datensatz zurueck - genau das simuliert eine zweite Anfrage an die
		// bereits gespeicherten Zeilen.
		$storedItem = new DebitItem();
		$storedItem->setId(99);
		$storedItem->setBatchId((int)$batch->getId());
		$storedItem->setOpenItemId(10);
		$storedItem->setMandateId(3);
		$storedItem->setAmountCents(4250);
		$storedItem->setIban('DE02120300000000202051');
		$storedItem->setBic('BYLADEM1001');
		$storedItem->setAccountHolder('Katrin Brunner');
		$storedItem->setMandateReference('M20260101-ABCDEF');
		$storedItem->setSignedDate('2024-01-01');
		$storedItem->setSequenceType('RCUR');
		$storedItem->setEndToEndId('E2E-20260101-000000-AABBCCDD');
		$storedItem->setRemittanceInfo('Vereinsbeitrag');
		$storedItem->setAmendmentIndicator(false);
		$storedItem->setCreatedAt('2026-01-01T00:00:00+00:00');

		$this->batchMapper->method('find')->with((int)$batch->getId())->willReturn($batch);
		$this->itemMapper->method('findByBatch')->with((int)$batch->getId())->willReturn([$storedItem]);

		$firstRender = $service->renderXml((int)$batch->getId());
		$secondRender = $service->renderXml((int)$batch->getId());

		$this->assertSame($firstRender, $secondRender, 'Zwei Rendervorgaenge desselben Laufs muessen byte-identisch sein.');

		$doc = new \DOMDocument();
		$this->assertTrue($doc->loadXML($firstRender));
		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$valid = $doc->schemaValidate(self::SCHEMA);
		$errors = array_map(static fn (\LibXMLError $e): string => trim($e->message), libxml_get_errors());
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		$this->assertTrue($valid, "Verstoss gegen pain.008.001.02:\n" . implode("\n", $errors));
	}

	// --- Einreichung (Schritt 2) -----------------------------------------------------

	private function releasedBatch(int $id = 1, string $dueDate = '2026-10-01'): DebitBatch {
		$b = new DebitBatch();
		$b->setId($id);
		$b->setDueDate($dueDate);
		$b->setStatus(DebitBatch::STATUS_RELEASED);
		$b->setReleasedAt('2026-09-01 00:00:00');
		$b->setMsgId('MSG-1');
		$b->setCreationDateTime('2026-09-01T00:00:00');
		$b->setCreditorId('DE98ZZZ09999999999');
		$b->setCreditorName('Testverein e. V.');
		$b->setCreditorIban('DE12500105170648489890');
		return $b;
	}

	private function debitItem(int $id, int $batchId, int $mandateId, bool $amendmentIndicator = false): DebitItem {
		$item = new DebitItem();
		$item->setId($id);
		$item->setBatchId($batchId);
		$item->setOpenItemId($id);
		$item->setMandateId($mandateId);
		$item->setAmountCents(1000);
		$item->setIban('DE02120300000000202051');
		$item->setAccountHolder('Katrin Brunner');
		$item->setMandateReference('M-1');
		$item->setSignedDate('2024-01-01');
		$item->setEndToEndId('E2E-' . $id);
		$item->setRemittanceInfo('Vereinsbeitrag');
		$item->setAmendmentIndicator($amendmentIndicator);
		$item->setCreatedAt('2026-09-01T00:00:00');
		return $item;
	}

	public function testSubmitSetztStatusUndMarkiertJedesBeteiligteMandatNurEinmal(): void {
		$batch = $this->releasedBatch();
		$this->batchMapper->method('find')->with(1)->willReturn($batch);
		// Zwei Posten desselben Mandats (z.B. Beitrag + separate manuelle
		// Forderung) - markPresented() darf trotzdem nur EINMAL laufen.
		$this->itemMapper->method('findByBatch')->with(1)->willReturn([
			$this->debitItem(10, 1, 3),
			$this->debitItem(11, 1, 3),
		]);

		$this->mandateService->expects($this->once())->method('markPresented')->with(3, '2026-10-01');
		$this->mandateService->expects($this->never())->method('markAmendmentTransmitted');

		$submitted = $this->service()->submit(1);

		$this->assertSame(DebitBatch::STATUS_SUBMITTED, $submitted->getStatus());
		$this->assertSame('kassenwart', $submitted->getSubmittedBy());
		$this->assertNotNull($submitted->getSubmittedAt());
	}

	public function testSubmitMitAmendmentMarkiertEsAlsTransmitted(): void {
		$batch = $this->releasedBatch();
		$this->batchMapper->method('find')->with(1)->willReturn($batch);
		$item = $this->debitItem(10, 1, 3, amendmentIndicator: true);
		$this->itemMapper->method('findByBatch')->with(1)->willReturn([$item]);

		$amendment = new MandateAmendment();
		$amendment->setId(7);
		$amendment->setMandateId(3);
		$amendment->setType(MandateAmendment::TYPE_ACCOUNT);
		$amendment->setStatus(MandateAmendment::STATUS_OPEN);
		$amendment->setCreatedAt('2026-01-01T00:00:00+00:00');
		$this->openAmendmentsByMandate[3] = [$amendment];

		$this->mandateService->expects($this->once())->method('markAmendmentTransmitted')->with(7, 10);
		$this->mandateService->expects($this->once())->method('markPresented')->with(3, '2026-10-01');

		$this->service()->submit(1);
	}

	public function testSubmitWirftWennBereitsEingereicht(): void {
		$batch = $this->releasedBatch();
		$batch->setStatus(DebitBatch::STATUS_SUBMITTED);
		$this->batchMapper->method('find')->willReturn($batch);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->submit(1);
	}

	// --- Verwerfen -----------------------------------------------------------------

	public function testDiscardSetztStatusUndBegruendungOhneDieZeilenAnzufassen(): void {
		$batch = $this->releasedBatch();
		$this->batchMapper->method('find')->with(1)->willReturn($batch);
		$this->itemMapper->method('findByBatch')->with(1)->willReturn([$this->debitItem(10, 1, 3)]);
		$this->itemMapper->expects($this->never())->method('update');
		$this->itemMapper->expects($this->never())->method('delete');

		$discarded = $this->service()->discard(1, 'Falsches Faelligkeitsdatum erwischt');

		$this->assertSame(DebitBatch::STATUS_DISCARDED, $discarded->getStatus());
		$this->assertSame('Falsches Faelligkeitsdatum erwischt', $discarded->getDiscardReason());
		$this->assertSame('kassenwart', $discarded->getDiscardedBy());
		$this->assertNotNull($discarded->getDiscardedAt());
	}

	public function testDiscardOhneBegruendungWirftUndFasstNichtsAn(): void {
		$this->batchMapper->expects($this->never())->method('find');

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->discard(1, '   ');
	}

	public function testDiscardEinesBereitsEingereichtenLaufsWirft(): void {
		$batch = $this->releasedBatch();
		$batch->setStatus(DebitBatch::STATUS_SUBMITTED);
		$this->batchMapper->method('find')->willReturn($batch);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->discard(1, 'Versehen');
	}

	// --- Terminverschiebung ----------------------------------------------------------

	public function testRescheduleVerschiebtNurNachHinten(): void {
		$batch = $this->releasedBatch(1, '2026-10-01');
		$this->batchMapper->method('find')->with(1)->willReturn($batch);

		$rescheduled = $this->service()->rescheduleDueDate(1, '2026-10-08');

		$this->assertSame('2026-10-08', $rescheduled->getDueDate());
	}

	public function testRescheduleNachVornWirft(): void {
		$batch = $this->releasedBatch(1, '2026-10-01');
		$this->batchMapper->method('find')->willReturn($batch);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->rescheduleDueDate(1, '2026-09-24');
	}

	// --- Abweichungs-Warnung (Freigabe->Einreichung-Fenster) -------------------------

	public function testFindDriftErkenntAbweichendeIban(): void {
		$item = $this->debitItem(10, 1, 3);
		$this->itemMapper->method('findByBatch')->with(1)->willReturn([$item]);
		// Nur die IBAN weicht ab - bic bleibt wie im Schnappschuss (null, siehe
		// debitItem()-Fixture), sonst zaehlte der Test faelschlich zwei
		// Abweichungen statt einer.
		$currentMandate = $this->mandate(3, 1, iban: 'DE00000000000000000000', bic: null);
		$this->mandateMapper->method('findOrNull')->with(3)->willReturn($currentMandate);

		$drift = $this->service()->findDrift(1);

		$this->assertCount(1, $drift);
		$this->assertSame('iban', $drift[0]['field']);
		$this->assertNotNull($this->service()->driftWarning(1));
	}

	public function testFindDriftOhneAbweichungIstLeer(): void {
		$item = $this->debitItem(10, 1, 3);
		$this->itemMapper->method('findByBatch')->with(1)->willReturn([$item]);
		// Dieselben Werte wie im Schnappschuss (debitItem()-Fixture oben).
		$currentMandate = $this->mandate(3, 1, iban: 'DE02120300000000202051', bic: null, holder: 'Katrin Brunner');
		$this->mandateMapper->method('findOrNull')->with(3)->willReturn($currentMandate);

		$this->assertSame([], $this->service()->findDrift(1));
		$this->assertNull($this->service()->driftWarning(1));
	}
}
