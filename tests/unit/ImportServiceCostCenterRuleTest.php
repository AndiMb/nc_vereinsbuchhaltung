<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\Rule;
use OCA\Vereinsbuchhaltung\Db\RuleMapper;
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
 * Eine Importregel kann eine Kostenstelle vorgeben (Issue #331): greift sie,
 * wird der ganze Betrag zugeordnet. Lässt sich die Vorgabe nicht anwenden,
 * wird der Umsatz trotzdem zugeordnet – nur ohne Kostenstelle.
 */
class ImportServiceCostCenterRuleTest extends TestCase {

	private const KONTO = 5;
	private const SEN = 3;

	/** @var list<array{0:int, 1:mixed}> jeder assign()-Aufruf: [Gegenkonto, Verteilung] */
	private array $assigned = [];

	private function rule(?int $costCenterId): Rule {
		$rule = new Rule();
		$rule->setMatchField('purpose');
		$rule->setMatchValue('Test');
		$rule->setContraAccountId(self::KONTO);
		$rule->setCostCenterId($costCenterId);
		return $rule;
	}

	/** @param list<array{costCenterId:int, amountCents:int}>|null $allocation was der Vorgabe-Helfer liefert */
	private function service(Rule $rule, ?array $allocation, bool $withDefaults = true): ImportService {
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
		$booking->method('assign')->willReturnCallback(function (BankTransaction $tx, int $accountId, ?array $allocations = null): BankTransaction {
			$this->assigned[] = [$accountId, $allocations];
			return $tx;
		});

		$defaults = $this->createMock(CostCenterDefaults::class);
		$defaults->method('fullAllocation')->willReturnCallback(
			static fn (string $userId, ?int $costCenterId, int $accountId, int $amountCents): ?array => $allocation,
		);
		$defaults->expects($withDefaults ? $this->atLeastOnce() : $this->never())->method('fullAllocation')
			->with('book', $rule->getCostCenterId(), self::KONTO, 6000);

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
			$withDefaults ? $defaults : null,
		);
	}

	/** @return array<string, mixed> Ausgang über 60,00 mit Verwendungszweck „Test“ */
	private function row(): array {
		return [
			'hash' => 'h1',
			'bookingDate' => '2026-10-01',
			'valueDate' => '2026-10-01',
			'amountCents' => -6000,
			'bookingText' => null,
			'purpose' => 'Test',
			'counterparty' => 'Gegenseite',
			'counterpartyIban' => null,
			'counterpartyBic' => null,
			'ownAccount' => null,
			'sepaDetails' => [],
		];
	}

	public function testRegelMitKostenstelleOrdnetDenGanzenBetragZu(): void {
		$allocation = [['costCenterId' => self::SEN, 'amountCents' => 6000]];
		$result = $this->service($this->rule(self::SEN), $allocation)->commitRows('book', [$this->row()], 'camt053');

		$this->assertSame([[self::KONTO, $allocation]], $this->assigned);
		$this->assertSame(1, $result['autoAssigned']);
	}

	public function testNichtAnwendbareVorgabeOrdnetOhneKostenstelleZu(): void {
		// Kostenstelle inzwischen deaktiviert: der Helfer liefert null.
		$result = $this->service($this->rule(self::SEN), null)->commitRows('book', [$this->row()], 'camt053');

		$this->assertSame([[self::KONTO, null]], $this->assigned);
		$this->assertSame(1, $result['autoAssigned']);
		$this->assertSame(0, $result['ruleFailed']);
	}

	public function testOhneHelferBleibtDerImportWieBisher(): void {
		$this->service($this->rule(self::SEN), null, false)->commitRows('book', [$this->row()], 'camt053');

		$this->assertSame([[self::KONTO, null]], $this->assigned);
	}
}
