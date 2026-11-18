<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\DonationReceipt;
use OCA\Vereinsbuchhaltung\Db\DonationReceiptMapper;
use OCA\Vereinsbuchhaltung\Db\Journal;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\NumberRange;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\DonationReceipt\DonationReceiptPdfRenderer;
use OCA\Vereinsbuchhaltung\Service\DonationReceipt\DonationReceiptService;
use OCA\Vereinsbuchhaltung\Service\DonationReceipt\DonationReceiptSettings;
use OCA\Vereinsbuchhaltung\Service\DonationReceipt\DonationReceiptStorage;
use OCA\Vereinsbuchhaltung\Service\InvoiceSettings;
use OCA\Vereinsbuchhaltung\Service\NumberRangeService;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\AppConfigStub;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\ClubClockStub;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Zuwendungsbestätigungen über Mitgliedsbeiträge (Issue #10): welche Zahlungen
 * in welches Jahr fallen, wann ausgestellt werden darf, Stornieren.
 */
class DonationReceiptServiceTest extends TestCase {

	private const COMPLETE = [
		DonationReceiptSettings::SETTING_ENABLED => '1',
		DonationReceiptSettings::SETTING_FEES_DEDUCTIBLE => '1',
		DonationReceiptSettings::SETTING_BASIS => 'freistellung',
		DonationReceiptSettings::SETTING_TAX_OFFICE => 'Musterstadt',
		DonationReceiptSettings::SETTING_TAX_NUMBER => '12/345/67890',
		DonationReceiptSettings::SETTING_NOTICE_DATE => '2024-03-01',
		DonationReceiptSettings::SETTING_ASSESSMENT_PERIOD => '2022',
		DonationReceiptSettings::SETTING_PURPOSES => 'der Bildung und Erziehung',
	];

	/** @var list<OpenItem> */
	private array $items = [];
	/** @var array<int, Journal> */
	private array $journals = [];
	/** @var list<DonationReceipt> */
	private array $stored = [];
	private ?string $renderedFor = null;
	/** @var list<array{0: string, 1: array<string, mixed>}> */
	private array $audit = [];

	private function service(array $config = self::COMPLETE, string $today = '2026-11-18 10:00:00', ?Member $member = null): DonationReceiptService {
		$member ??= $this->member();
		$members = $this->createMock(MemberMapper::class);
		$members->method('find')->willReturn($member);
		$openItems = $this->createMock(OpenItemMapper::class);
		$openItems->method('findByMember')->willReturnCallback(fn (): array => $this->items);
		$journals = $this->createMock(JournalMapper::class);
		$journals->method('findByIds')->willReturnCallback(fn (string $user, array $ids): array => array_intersect_key($this->journals, array_flip($ids)));

		$receipts = $this->createMock(DonationReceiptMapper::class);
		$receipts->method('findByMember')->willReturnCallback(fn (): array => array_reverse($this->stored));
		$receipts->method('find')->willReturnCallback(fn (int $id): DonationReceipt => $this->stored[$id - 1]);
		$receipts->method('update')->willReturnArgument(0);

		$storage = $this->createMock(DonationReceiptStorage::class);
		$storage->method('store')->willReturnCallback(function (DonationReceipt $r, string $content): DonationReceipt {
			$r->setId(count($this->stored) + 1);
			$r->setSha256(hash('sha256', $content));
			$r->setFileId(0);
			$this->stored[] = $r;
			return $r;
		});
		$storage->method('verify')->willReturn('ok');

		$renderer = $this->createMock(DonationReceiptPdfRenderer::class);
		$renderer->method('render')->willReturnCallback(function (array $receipt): string {
			$this->renderedFor = $receipt['number'] . '|' . implode(',', array_column($receipt['items'], 'date')) . '|' . $receipt['amountCents'];
			return '%PDF-test';
		});

		$numbers = $this->createMock(NumberRangeService::class);
		$numbers->method('issue')->willReturnCallback(fn (string $kind): array => [
			'number' => ($kind === NumberRange::KIND_DONATION_RECEIPT ? 'ZB-2026-' : 'X-') . sprintf('%04d', count($this->stored) + 1),
			'rangeId' => 1,
		]);

		$transaction = $this->createMock(TransactionRunner::class);
		$transaction->method('run')->willReturnCallback(static fn (callable $fn) => $fn());
		$transaction->method('afterCommit')->willReturnCallback(static function (callable $fn): void {
			$fn();
		});

		$audit = $this->createMock(AuditService::class);
		$audit->method('log')->willReturnCallback(function (string $action, ?string $type = null, ?int $id = null, array $details = []): void {
			$this->audit[] = [$action, $details];
		});

		$invoiceSettings = $this->createMock(InvoiceSettings::class);
		$invoiceSettings->method('issuer')->willReturn([
			'name' => 'Förderverein Musterschule e. V.', 'street' => 'Schulweg 1', 'postalCode' => '12345', 'city' => 'Musterstadt',
		]);

		return new DonationReceiptService(
			$receipts,
			$storage,
			new DonationReceiptSettings(AppConfigStub::create($this, $config), $invoiceSettings, $this->l10n()),
			$renderer,
			$members,
			$openItems,
			$journals,
			$numbers,
			$transaction,
			$audit,
			ClubClockStub::at($today),
			$this->l10n(),
		);
	}

	private function l10n(): IL10N&MockObject {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []): string => vsprintf($text, $p));
		return $l10n;
	}

	private function member(bool $address = true): Member {
		$m = new Member();
		$m->setId(7);
		$m->setMemberType(Member::TYPE_PERSON);
		$m->setFirstName('Erika');
		$m->setLastName('Mustermann');
		if ($address) {
			$m->setStreet('Hauptstraße 5');
			$m->setPostalCode('12345');
			$m->setCity('Musterstadt');
		}
		return $m;
	}

	private function paid(int $id, int $cents, ?int $journalId = null, ?string $settledAt = null, string $type = OpenItem::TYPE_CONTRIBUTION, string $status = 'paid'): OpenItem {
		$item = new OpenItem();
		$item->setId($id);
		$item->setMemberId(7);
		$item->setType($type);
		$item->setStatus($status);
		$item->setAmountCents($cents);
		$item->setPaidJournalId($journalId);
		$item->setSettledAt($settledAt);
		$item->setDueDate('2025-01-15');
		$item->setDescription('Beitrag ' . $id);
		return $item;
	}

	private function journal(int $id, string $date): void {
		$j = new Journal();
		$j->setId($id);
		$j->setDate($date);
		$this->journals[$id] = $j;
	}

	public function testZahltagIstDasBuchungsdatumSonstDerTagDerMarkierung(): void {
		$this->journal(100, '2025-12-30');
		$this->items = [
			$this->paid(1, 6000, 100),
			// Ohne Buchung: Tag der Markierung, in der Zeitzone des Vereins (UTC 23:30 = 1. Januar).
			$this->paid(2, 6000, null, '2025-12-31T23:30:00+00:00'),
			$this->paid(3, 500, null, '2025-06-01T10:00:00+00:00', OpenItem::TYPE_FEE),
			$this->paid(4, 6000, null, '2025-06-01T10:00:00+00:00', OpenItem::TYPE_CONTRIBUTION, 'waived'),
		];
		$payments = $this->service()->payments(7);
		$this->assertSame([[1, '2025-12-30'], [2, '2026-01-01']], array_map(static fn (array $p): array => [$p['openItemId'], $p['date']], $payments));
	}

	public function testUebersichtNenntNurAbgeschlosseneJahre(): void {
		$this->journal(100, '2025-03-01');
		$this->journal(101, '2025-09-01');
		$this->journal(102, '2026-03-01');
		$this->items = [$this->paid(1, 6000, 100), $this->paid(2, 4000, 101), $this->paid(3, 6000, 102)];
		$overview = $this->service()->overview(7);
		$this->assertTrue($overview['enabled']);
		$this->assertSame([], $overview['problems']);
		$this->assertSame([['year' => 2025, 'amountCents' => 10000, 'count' => 2, 'receiptId' => null]], $overview['years']);
	}

	public function testAusgeschaltetZeigtNichts(): void {
		$overview = $this->service([DonationReceiptSettings::SETTING_ENABLED => '0'])->overview(7);
		$this->assertFalse($overview['enabled']);
		$this->assertSame([], $overview['years']);
	}

	public function testStelltDieSammelbestaetigungEinesJahresAus(): void {
		$this->journal(100, '2025-03-01');
		$this->journal(101, '2025-09-01');
		$this->journal(102, '2026-03-01');
		$this->items = [$this->paid(2, 4000, 101), $this->paid(1, 6000, 100), $this->paid(3, 6000, 102)];

		$receipt = $this->service()->issue(7, 2025, 'kassenwart');

		$this->assertSame('ZB-2026-0001', $receipt->getNumber());
		$this->assertSame(10000, $receipt->getAmountCents());
		$this->assertSame(['2025-01-01', '2025-12-31'], [$receipt->getPeriodFrom(), $receipt->getPeriodTo()]);
		$this->assertSame([1, 2], array_column($receipt->itemList(), 'openItemId'));
		$this->assertSame('ZB-2026-0001|2025-03-01,2025-09-01|10000', $this->renderedFor);
		$this->assertSame('kassenwart', $receipt->getIssuedBy());
		$this->assertStringStartsWith('ZB-2026-0001', $receipt->getFileName());
		$this->assertSame('Zuwendungsbestätigung ausgestellt', $this->audit[0][0]);
		$this->assertSame(hash('sha256', '%PDF-test'), $this->audit[0][1]['sha256']);
	}

	public function testLaufendesJahrErstNachSeinemEnde(): void {
		$this->expectExceptionMessage('erst nach Ablauf des Kalenderjahres');
		$this->service()->issue(7, 2026, 'kassenwart');
	}

	public function testOhneBestaetigungDerAbziehbarkeitWirdNichtsAusgestellt(): void {
		$config = self::COMPLETE;
		unset($config[DonationReceiptSettings::SETTING_FEES_DEDUCTIBLE]);
		$this->expectExceptionMessage('§ 10b Abs. 1 Satz 8 EStG');
		$this->service($config)->issue(7, 2025, 'kassenwart');
	}

	public function testAbgelaufenerFreistellungsbescheidSperrt(): void {
		$config = [DonationReceiptSettings::SETTING_NOTICE_DATE => '2021-11-17'] + self::COMPLETE;
		$this->expectExceptionMessage('älter als 5 Jahre');
		$this->service($config)->issue(7, 2025, 'kassenwart');
	}

	public function testGueltigkeitNachParagraph63Abs5AO(): void {
		$this->assertFalse(DonationReceiptSettings::isExpired('freistellung', '2021-11-18', '2026-11-18'));
		$this->assertTrue(DonationReceiptSettings::isExpired('freistellung', '2021-11-17', '2026-11-18'));
		$this->assertFalse(DonationReceiptSettings::isExpired('feststellung', '2023-11-18', '2026-11-18'));
		$this->assertTrue(DonationReceiptSettings::isExpired('feststellung', '2023-11-17', '2026-11-18'));
	}

	public function testOhneAnschriftKeineBestaetigung(): void {
		$this->journal(100, '2025-03-01');
		$this->items = [$this->paid(1, 6000, 100)];
		$this->expectExceptionMessage('fehlt die Anschrift');
		$this->service(member: $this->member(false))->issue(7, 2025, 'kassenwart');
	}

	public function testJeJahrNurEineGueltigeBestaetigung(): void {
		$this->journal(100, '2025-03-01');
		$this->items = [$this->paid(1, 6000, 100)];
		$service = $this->service();
		$first = $service->issue(7, 2025, 'kassenwart');
		try {
			$service->issue(7, 2025, 'kassenwart');
			$this->fail('Zweite Bestätigung für dasselbe Jahr');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString($first->getNumber(), $e->getMessage());
		}

		// Nach dem Stornieren geht es wieder; die stornierte bleibt erhalten.
		$service->void((int)$first->getId(), 'Anschrift falsch', 'kassenwart');
		$second = $service->issue(7, 2025, 'kassenwart');
		$this->assertSame('ZB-2026-0002', $second->getNumber());
		$this->assertTrue($first->isVoided());
		$this->assertSame('Anschrift falsch', $first->getVoidReason());
	}

	public function testStornierenBrauchtEinenGrund(): void {
		$this->journal(100, '2025-03-01');
		$this->items = [$this->paid(1, 6000, 100)];
		$service = $this->service();
		$receipt = $service->issue(7, 2025, 'kassenwart');
		$this->expectException(\InvalidArgumentException::class);
		$service->void((int)$receipt->getId(), '  ', 'kassenwart');
	}

	public function testRuecklastschriftMachtDieBestaetigungUeberholt(): void {
		$this->journal(100, '2025-03-01');
		$this->items = [$this->paid(1, 6000, 100)];
		$service = $this->service();
		$service->issue(7, 2025, 'kassenwart');
		$this->assertFalse($service->overview(7)['receipts'][0]['outdated']);

		$this->items[0]->setStatus('open');
		$this->assertTrue($service->overview(7)['receipts'][0]['outdated']);
	}

	public function testMitgliedSiehtNurEigeneGueltigeBestaetigungen(): void {
		$this->journal(100, '2025-03-01');
		$this->items = [$this->paid(1, 6000, 100)];
		$service = $this->service();
		$receipt = $service->issue(7, 2025, 'kassenwart');

		$service->assertBelongsTo($receipt, 7);
		$own = $service->forMember(7);
		$this->assertCount(1, $own);
		$this->assertArrayNotHasKey('issuedBy', $own[0]);

		$this->expectException(UserFacingException::class);
		$service->assertBelongsTo($receipt, 8);
	}

	public function testStornierteGibtEsImSelfServiceNicht(): void {
		$this->journal(100, '2025-03-01');
		$this->items = [$this->paid(1, 6000, 100)];
		$service = $this->service();
		$receipt = $service->issue(7, 2025, 'kassenwart');
		$service->void((int)$receipt->getId(), 'falsch', 'kassenwart');
		$this->assertSame([], $service->forMember(7));
		$this->expectException(UserFacingException::class);
		$service->assertBelongsTo($receipt, 7);
	}
}
