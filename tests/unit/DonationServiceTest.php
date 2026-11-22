<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Debtor;
use OCA\Vereinsbuchhaltung\Db\DebtorMapper;
use OCA\Vereinsbuchhaltung\Db\Donation;
use OCA\Vereinsbuchhaltung\Db\DonationMapper;
use OCA\Vereinsbuchhaltung\Db\DonationReceipt;
use OCA\Vereinsbuchhaltung\Db\DonationReceiptMapper;
use OCA\Vereinsbuchhaltung\Db\Journal;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\DonationReceipt\DonationLedger;
use OCA\Vereinsbuchhaltung\Service\DonationReceipt\DonationReceiptService;
use OCA\Vereinsbuchhaltung\Service\DonationReceipt\DonationReceiptSettings;
use OCA\Vereinsbuchhaltung\Service\DonationReceipt\DonationService;
use OCA\Vereinsbuchhaltung\Service\InvoiceSettings;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\AppConfigStub;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\ClubClockStub;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Spender je Spendenbuchung (Issue #10, Teil B): genau ein Spender, keine
 * Änderung mehr, sobald eine gültige Bestätigung die Spende enthält, und die
 * Jahresliste mit Spender und Bestätigung.
 */
class DonationServiceTest extends TestCase {

	/** @var array<int, Donation> */
	private array $donations = [];
	/** @var list<DonationReceipt> */
	private array $receipts = [];
	/** @var array<int, int> journalId => Spendenbetrag */
	private array $amounts = [200 => 10000];

	private function service(): DonationService {
		$mapper = $this->createMock(DonationMapper::class);
		$mapper->method('findByJournal')->willReturnCallback(fn (int $id): ?Donation => $this->donations[$id] ?? null);
		$mapper->method('findByJournals')->willReturnCallback(fn (array $ids): array => array_intersect_key($this->donations, array_flip($ids)));
		$mapper->method('insert')->willReturnCallback(function (Donation $d): Donation {
			$this->donations[$d->getJournalId()] = $d;
			return $d;
		});
		$mapper->method('update')->willReturnArgument(0);
		$mapper->method('delete')->willReturnCallback(function (Donation $d): Donation {
			unset($this->donations[$d->getJournalId()]);
			return $d;
		});

		$ledger = $this->createMock(DonationLedger::class);
		$ledger->method('amountOf')->willReturnCallback(fn (int $id): int => $this->amounts[$id] ?? 0);
		$ledger->method('inYear')->willReturnCallback(function (): array {
			$j = new Journal();
			$j->setId(200);
			$j->setDate('2025-06-01');
			$j->setDescription('Spende Sommerfest');
			return [['journal' => $j, 'amountCents' => 10000]];
		});

		$receiptService = $this->createMock(DonationReceiptService::class);
		$receiptService->method('receiptsOf')->willReturnCallback(fn (): array => $this->receipts);
		$receiptService->method('years')->willReturn([['year' => 2025, 'amountCents' => 10000, 'count' => 1, 'receiptId' => null]]);
		$receiptService->method('describe')->willReturnCallback(static fn (DonationReceipt $r): array => $r->jsonSerialize());

		$receiptMapper = $this->createMock(DonationReceiptMapper::class);
		$receiptMapper->method('findByYear')->willReturnCallback(fn (): array => $this->receipts);

		$member = new Member();
		$member->setId(7);
		$member->setMemberType(Member::TYPE_PERSON);
		$member->setFirstName('Erika');
		$member->setLastName('Mustermann');
		$members = $this->createMock(MemberMapper::class);
		$members->method('find')->willReturn($member);
		$members->method('findOrNull')->willReturn($member);

		$debtor = new Debtor();
		$debtor->setId(3);
		$debtor->setName('Autohaus Beispiel GmbH');
		$debtors = $this->createMock(DebtorMapper::class);
		$debtors->method('find')->willReturn($debtor);
		$debtors->method('findByIds')->willReturn([3 => $debtor]);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []): string => vsprintf($text, $p));
		$invoiceSettings = $this->createMock(InvoiceSettings::class);
		$invoiceSettings->method('issuer')->willReturn(['name' => 'Förderverein', 'street' => 'Schulweg 1', 'postalCode' => '12345', 'city' => 'Musterstadt']);
		$settings = new DonationReceiptSettings(
			AppConfigStub::create($this, [DonationReceiptSettings::SETTING_ENABLED => '1', DonationReceiptSettings::SETTING_ACCOUNTS => '50']),
			$invoiceSettings,
			$l10n,
		);

		return new DonationService(
			$mapper, $ledger, $receiptService, $receiptMapper, $settings, $members, $debtors,
			$this->createMock(AuditService::class), ClubClockStub::at('2026-11-18 10:00:00'), $l10n,
		);
	}

	public function testGenauEinSpender(): void {
		$service = $this->service();
		foreach ([[0, 0], [7, 3]] as [$member, $debtor]) {
			try {
				$service->assign(200, $member, $debtor, false, 'kassenwart');
				$this->fail('Kein oder zwei Spender angenommen');
			} catch (\InvalidArgumentException $e) {
				$this->assertStringContainsString('genau einen Spender', $e->getMessage());
			}
		}
	}

	public function testNurBuchungenAufEinemSpendenkonto(): void {
		$this->expectExceptionMessage('keine Spende auf einem Spendenkonto');
		$this->service()->assign(999, 7, 0, false, 'kassenwart');
	}

	public function testZuordnenAendernUndAufheben(): void {
		$service = $this->service();
		$service->assign(200, 7, 0, false, 'kassenwart');
		$this->assertSame([Donation::DONOR_MEMBER, 7], $this->donations[200]->donor());
		$service->assign(200, 0, 3, true, 'kassenwart');
		$this->assertSame([Donation::DONOR_DEBTOR, 3], $this->donations[200]->donor());
		$this->assertTrue($this->donations[200]->isWaiver());
		$service->unassign(200, 'kassenwart');
		$this->assertSame([], $this->donations);
	}

	public function testBestaetigteSpendeBleibtWieSieIst(): void {
		$service = $this->service();
		$service->assign(200, 7, 0, false, 'kassenwart');
		$receipt = new DonationReceipt();
		$receipt->setId(1);
		$receipt->setMemberId(7);
		$receipt->setNumber('ZB-2026-0001');
		$receipt->setReceiptType(DonationReceipt::TYPE_SINGLE);
		$receipt->setItems((string)json_encode([['journalId' => 200, 'type' => 'spende', 'date' => '2025-06-01', 'amountCents' => 10000, 'waiver' => false]]));
		$this->receipts = [$receipt];

		try {
			$service->assign(200, 0, 3, false, 'kassenwart');
			$this->fail('Spender einer bestätigten Spende geändert');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('ZB-2026-0001', $e->getMessage());
		}
		$this->expectExceptionMessage('zuerst diese stornieren');
		$service->unassign(200, 'kassenwart');
	}

	public function testJahreslisteMitSpenderUndBestaetigung(): void {
		$service = $this->service();
		$service->assign(200, 0, 3, false, 'kassenwart');
		$overview = $service->overview(2025);
		$this->assertTrue($overview['accountsConfigured']);
		$this->assertSame('Autohaus Beispiel GmbH', $overview['donations'][0]['donor']['name']);
		$this->assertNull($overview['donations'][0]['receipt']);
		$this->assertSame([['kind' => 'debtor', 'id' => 3, 'name' => 'Autohaus Beispiel GmbH', 'amountCents' => 10000, 'count' => 1, 'receiptId' => null]], $overview['donors']);
		// Laufendes Jahr: noch keine Sammelbestätigungen.
		$this->assertSame([], $service->overview(2026)['donors']);
	}
}
