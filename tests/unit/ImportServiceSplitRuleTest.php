<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\Rule;
use OCA\Vereinsbuchhaltung\Db\RuleMapper;
use OCA\Vereinsbuchhaltung\Db\RulePart;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\AccountService;
use OCA\Vereinsbuchhaltung\Service\BookingService;
use OCA\Vereinsbuchhaltung\Service\CostCenter\CostCenterDefaults;
use OCA\Vereinsbuchhaltung\Service\ImportService;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportExtractionService;
use OCA\Vereinsbuchhaltung\Service\Statement\RowNormalizer;
use OCA\Vereinsbuchhaltung\Service\Statement\StatementParserRegistry;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\ClubCurrencyStub;
use PHPUnit\Framework\TestCase;

/**
 * Eine Importregel mit festen Anteilen (Issue #163) teilt den Umsatz beim
 * Import auf: Anteile in ihrer Reihenfolge, der Rest aufs Gegenkonto. Ist
 * der Umsatz kleiner als die Anteile zusammen, bleibt er unzugeordnet und
 * zählt als gescheiterte Regel.
 */
class ImportServiceSplitRuleTest extends TestCase {

	private const KONTO = 5;

	/** @var list<list<array<string, mixed>>> jeder assignParts()-Aufruf */
	private array $assignedParts = [];
	/** @var list<int> jeder assign()-Aufruf: Gegenkonto */
	private array $assigned = [];
	/** @var list<array{0:?int, 1:int, 2:int}> jeder fullAllocation()-Aufruf: [Kostenstelle, Konto, Cent] */
	private array $defaultsCalls = [];

	/** @param list<array{0:int, 1:int, 2?:int|null}> $parts [Konto, Cent, Kostenstelle] */
	private function rule(array $parts): Rule {
		$rule = new Rule();
		$rule->setMatchField('purpose');
		$rule->setMatchValue('Beitrag');
		$rule->setContraAccountId(self::KONTO);
		$rule->setPartsList(array_map(static function (array $p): RulePart {
			$part = new RulePart();
			$part->setAccountId($p[0]);
			$part->setAmountCents($p[1]);
			$part->setCostCenterId($p[2] ?? null);
			return $part;
		}, $parts));
		return $rule;
	}

	/** @param list<array{costCenterId:int, amountCents:int}>|null $allocation was der Vorgabe-Helfer liefert */
	private function service(Rule $rule, ?array $allocation = null): ImportService {
		$txMapper = $this->createMock(BankTransactionMapper::class);
		$txMapper->method('findExistingHashes')->willReturn([]);
		$txMapper->method('findDedupKeys')->willReturn([]);
		$txMapper->method('insert')->willReturnArgument(0);
		$normalizer = $this->createMock(RowNormalizer::class);
		$normalizer->method('softKey')->willReturn(null);
		$normalizer->method('matchTexts')->willReturn([]);
		$rules = $this->createMock(RuleMapper::class);
		$rules->method('findAll')->willReturn([$rule]);
		$journals = $this->createMock(JournalMapper::class);
		$journals->method('findManualBookingKeys')->willReturn([]);
		$transaction = $this->createMock(TransactionRunner::class);
		$transaction->method('run')->willReturnCallback(static fn (callable $work) => $work());

		$booking = $this->createMock(BookingService::class);
		$booking->method('assign')->willReturnCallback(function (BankTransaction $tx, int $accountId): BankTransaction {
			$this->assigned[] = $accountId;
			return $tx;
		});
		$booking->method('assignParts')->willReturnCallback(function (BankTransaction $tx, array $parts): BankTransaction {
			$this->assignedParts[] = $parts;
			return $tx;
		});

		$defaults = $this->createMock(CostCenterDefaults::class);
		$defaults->method('fullAllocation')->willReturnCallback(
			function (string $userId, ?int $costCenterId, int $accountId, int $amountCents) use ($allocation): ?array {
				$this->defaultsCalls[] = [$costCenterId, $accountId, $amountCents];
				return $costCenterId === null ? null : $allocation;
			},
		);

		return new ImportService(
			$this->createMock(StatementParserRegistry::class),
			$normalizer,
			$txMapper,
			$rules,
			$booking,
			$journals,
			$transaction,
			$this->createMock(SepaImportExtractionService::class),
			$this->createMock(AccountService::class),
			ClubCurrencyStub::of($this),
			null,
			null,
			null,
			$defaults,
		);
	}

	/** @return array<string, mixed> Eingang über 60,00 mit Verwendungszweck „Beitrag“ */
	private function row(): array {
		return [
			'hash' => 'h1',
			'bookingDate' => '2026-10-01',
			'valueDate' => '2026-10-01',
			'amountCents' => 6000,
			'bookingText' => null,
			'purpose' => 'Beitrag 2026',
			'counterparty' => 'Mitglied',
			'counterpartyIban' => null,
			'counterpartyBic' => null,
			'ownAccount' => null,
			'sepaDetails' => [],
		];
	}

	public function testAufteilungsregelBuchtTeile(): void {
		$result = $this->service($this->rule([[10, 1000], [11, 500]]))->commitRows('book', [$this->row()], 'camt053');

		$this->assertSame([[
			['accountId' => 10, 'amountCents' => 1000],
			['accountId' => 11, 'amountCents' => 500],
			['accountId' => self::KONTO, 'amountCents' => 4500],
		]], $this->assignedParts);
		$this->assertSame([], $this->assigned);
		$this->assertSame(1, $result['autoAssigned']);
		$this->assertSame(0, $result['ruleFailed']);
	}

	public function testZuKleinBleibtUnzugeordnet(): void {
		$result = $this->service($this->rule([[10, 5000], [11, 2000]]))->commitRows('book', [$this->row()], 'camt053');

		$this->assertSame([], $this->assignedParts);
		$this->assertSame([], $this->assigned);
		$this->assertSame(0, $result['autoAssigned']);
		$this->assertSame(1, $result['ruleFailed']);
	}

	public function testKostenstelleJeTeil(): void {
		$allocation = [['costCenterId' => 7, 'amountCents' => 1000]];
		$this->service($this->rule([[10, 1000, 7]]), $allocation)->commitRows('book', [$this->row()], 'camt053');

		$this->assertContains([7, 10, 1000], $this->defaultsCalls);
		$this->assertSame([[
			['accountId' => 10, 'amountCents' => 1000, 'allocations' => $allocation],
			['accountId' => self::KONTO, 'amountCents' => 5000],
		]], $this->assignedParts);
	}

	public function testOhneTeileWieBisher(): void {
		$this->service($this->rule([]))->commitRows('book', [$this->row()], 'camt053');

		$this->assertSame([self::KONTO], $this->assigned);
		$this->assertSame([], $this->assignedParts);
	}
}
