<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\Journal;
use OCA\Vereinsbuchhaltung\Db\JournalLine;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Service\AccountStatementService;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use PHPUnit\Framework\TestCase;

/**
 * Kontoauszug/Kontenblatt ({@see AccountStatementService}): Saldovortrag,
 * laufender Saldo und Kommentar je Zeile – dieselben Zahlen für Bildschirm,
 * CSV und Druckansicht.
 */
class AccountStatementServiceTest extends TestCase {

	private const BANK = 1;
	private const INCOME = 2;
	private const EXPENSE = 3;

	/** @var array<int, Journal> */
	private array $journals = [];
	/** @var array<int, JournalLine[]> */
	private array $lines = [];

	private function account(int $id, string $number, string $type, bool $isBank = false): Account {
		$a = new Account();
		$a->setId($id);
		$a->setNumber($number);
		$a->setName('Konto ' . $number);
		$a->setType($type);
		$a->setIsBank($isBank);
		return $a;
	}

	/** Einfache Buchung: Soll-Konto an Haben-Konto. */
	private function book(int $id, int $entryNo, string $date, int $debitAccount, int $creditAccount, int $cents, ?string $comment = null): void {
		$j = new Journal();
		$j->setId($id);
		$j->setEntryNo($entryNo);
		$j->setDate($date);
		$j->setDescription('Buchung ' . $entryNo);
		$j->setComment($comment);
		$this->journals[$id] = $j;
		$this->lines[$id] = [];
		foreach ([[$debitAccount, $cents, 0], [$creditAccount, 0, $cents]] as [$acc, $debit, $credit]) {
			$l = new JournalLine();
			$l->setJournalId($id);
			$l->setAccountId($acc);
			$l->setDebitCents($debit);
			$l->setCreditCents($credit);
			$this->lines[$id][] = $l;
		}
	}

	private function service(?array $range = null): AccountStatementService {
		$accounts = [
			self::BANK => $this->account(self::BANK, '1200', 'asset', true),
			self::INCOME => $this->account(self::INCOME, '4000', 'income'),
			self::EXPENSE => $this->account(self::EXPENSE, '6000', 'expense'),
		];
		$accountMapper = $this->createMock(AccountMapper::class);
		$accountMapper->method('findAll')->willReturn(array_values($accounts));
		$accountMapper->method('find')->willReturnCallback(static fn (int $id) => $accounts[$id]);

		$lineMapper = $this->createMock(JournalLineMapper::class);
		$lineMapper->method('findJournalIdsForAccounts')->willReturnCallback(function (string $u, array $ids): array {
			$out = [];
			foreach ($this->lines as $jid => $lines) {
				foreach ($lines as $l) {
					if (in_array($l->getAccountId(), $ids, true)) {
						$out[] = $jid;
						break;
					}
				}
			}
			return $out;
		});
		$lineMapper->method('findByJournals')->willReturnCallback(fn (): array => $this->lines);

		$journalMapper = $this->createMock(JournalMapper::class);
		$journalMapper->method('findByIds')->willReturnCallback(fn (): array => $this->journals);

		$periods = $this->createMock(PeriodService::class);
		$periods->method('range')->willReturn($range ?? [null, null]);

		return new AccountStatementService($journalMapper, $lineMapper, $accountMapper, $periods);
	}

	public function testLaufenderSaldoEinesGeldkontosMitVortrag(): void {
		$this->book(1, 1, '2024-12-20', self::BANK, self::INCOME, 10000);  // Vorjahr → Vortrag
		$this->book(3, 2, '2025-02-01', self::EXPENSE, self::BANK, 2500, 'Beleg fehlt');
		$this->book(2, 1, '2025-01-15', self::BANK, self::INCOME, 5000);

		$s = $this->service(['2025-01-01', '2025-12-31'])->build('u', self::BANK, false, 5);

		$this->assertSame(100.0, (float)$s['carry']);
		$this->assertSame('2025-01-01', $s['carryDate']);
		// chronologisch, unabhängig von der Reihenfolge der IDs
		$this->assertSame([1, 2], array_column($s['rows'], 'entryNo'));
		$this->assertSame([150.0, 125.0], array_map('floatval', array_column($s['rows'], 'saldo')));
		$this->assertSame([null, 'Beleg fehlt'], array_column($s['rows'], 'comment'));
		$this->assertSame(125.0, (float)$s['totals']['balance']);
		$this->assertSame(2, $s['totals']['count']);
	}

	public function testHabenKontoZaehltHabenPositiv(): void {
		$this->book(1, 1, '2025-01-15', self::BANK, self::INCOME, 5000);
		$this->book(2, 2, '2025-02-15', self::BANK, self::INCOME, 1250);

		$s = $this->service()->build('u', self::INCOME, false, null);

		$this->assertNull($s['carryDate']);
		$this->assertSame([50.0, 62.5], array_map('floatval', array_column($s['rows'], 'saldo')));
		$this->assertSame(62.5, (float)$s['totals']['balance']);
	}
}
