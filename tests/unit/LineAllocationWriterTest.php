<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BookingCostCenter;
use OCA\Vereinsbuchhaltung\Db\BookingCostCenterMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLine;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\LineAllocationMapper;
use OCA\Vereinsbuchhaltung\Service\ClubCurrency;
use OCA\Vereinsbuchhaltung\Service\CostCenter\LineAllocationWriter;
use OCA\Vereinsbuchhaltung\Service\CostCenterSettings;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Der Schreiber der Verteilungen (Issue #7) ist die einzige Stelle, die
 * `vbh_line_alloc` füllt. Hier geht es um das Zusammenspiel: die Verteilung
 * landet an den frisch geschriebenen Zeilen, sie überlebt eine Bearbeitung
 * ohne Angabe – gerade auch bei ausgeschaltetem Modul –, und ausgeschaltet
 * wird nichts Neues angenommen.
 */
class LineAllocationWriterTest extends TestCase {

	private const JOURNAL = 40;
	private const AUSRUESTUNG = 5300;
	private const BANK = 1200;
	private const SEN = 3;
	private const AJU = 5;

	private LineAllocationMapper&MockObject $allocations;
	private JournalLineMapper&MockObject $lines;
	private bool $enabled = true;
	/** @var list<array{0:int, 1:int, 2:int}> jede Einfügung: [Zeilen-ID, Kostenstelle, Cent] */
	private array $inserted = [];

	protected function setUp(): void {
		$this->enabled = true;
		$this->inserted = [];
		$this->allocations = $this->createMock(LineAllocationMapper::class);
		$this->allocations->method('insertFor')->willReturnCallback(function (int $lineId, int $costCenterId, int $cents): void {
			$this->inserted[] = [$lineId, $costCenterId, $cents];
		});
		$this->lines = $this->createMock(JournalLineMapper::class);
		// So liegen die Zeilen nach dem Neuschreiben in der Datenbank: neue IDs.
		$this->lines->method('findByJournal')->willReturn([
			$this->journalLine(77, self::AUSRUESTUNG, 100000, 0),
			$this->journalLine(78, self::BANK, 0, 100000),
		]);
	}

	private function journalLine(int $id, int $accountId, int $debit, int $credit): JournalLine {
		$line = new JournalLine();
		$line->setId($id);
		$line->setJournalId(self::JOURNAL);
		$line->setAccountId($accountId);
		$line->setDebitCents($debit);
		$line->setCreditCents($credit);
		return $line;
	}

	private function account(int $id, string $type): Account {
		$account = new Account();
		$account->setId($id);
		$account->setType($type);
		return $account;
	}

	private function costCenter(int $id, string $code): BookingCostCenter {
		$cc = new BookingCostCenter();
		$cc->setId($id);
		$cc->setCode($code);
		$cc->setName($code);
		$cc->setActive(true);
		return $cc;
	}

	private function writer(): LineAllocationWriter {
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findAll')->willReturn([
			$this->account(self::AUSRUESTUNG, 'expense'),
			$this->account(self::BANK, 'asset'),
		]);
		$costCenters = $this->createMock(BookingCostCenterMapper::class);
		$costCenters->method('findAll')->willReturn([
			$this->costCenter(self::SEN, 'SEN'),
			$this->costCenter(self::AJU, 'AJU'),
		]);
		$settings = $this->createMock(CostCenterSettings::class);
		$settings->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$currency = $this->createMock(ClubCurrency::class);
		$currency->method('format')->willReturnCallback(static fn (int $cents): string => number_format($cents / 100, 2, ',', '.') . ' €');
		$currency->method('formatNumber')->willReturnCallback(static fn (int $cents): string => number_format($cents / 100, 2, ',', '.'));
		return new LineAllocationWriter($this->allocations, $this->lines, $accounts, $costCenters, $settings, $l10n, $currency);
	}

	/**
	 * @param list<array{0:int, 1:int}>|null $allocations
	 * @return array<string, mixed>
	 */
	private function line(int $accountId, int $debit, int $credit, ?array $allocations = null): array {
		$line = ['accountId' => $accountId, 'debitCents' => $debit, 'creditCents' => $credit];
		if ($allocations !== null) {
			$line['allocations'] = array_map(static fn (array $a): array => ['costCenterId' => $a[0], 'amountCents' => $a[1]], $allocations);
		}
		return $line;
	}

	/** @return list<array<string, mixed>> Stand vor der Änderung: 600/400 auf Ausrüstung */
	private function snapshot(): array {
		return [
			$this->line(self::AUSRUESTUNG, 100000, 0, [[self::SEN, 60000], [self::AJU, 40000]]),
			$this->line(self::BANK, 0, 100000, []),
		];
	}

	public function testApplySchreibtZuDenNeuenZeilenIds(): void {
		$this->writer()->apply(self::JOURNAL, 'u', [
			$this->line(self::AUSRUESTUNG, 100000, 0, [[self::SEN, 60000], [self::AJU, 40000]]),
			$this->line(self::BANK, 0, 100000),
		]);
		$this->assertSame([[77, self::SEN, 60000], [77, self::AJU, 40000]], $this->inserted);
	}

	public function testApplyOhneSchluesselUebernimmtAusSnapshot(): void {
		$this->writer()->apply(self::JOURNAL, 'u', [
			$this->line(self::AUSRUESTUNG, 100000, 0),
			$this->line(self::BANK, 0, 100000),
		], $this->snapshot());
		$this->assertSame([[77, self::SEN, 60000], [77, self::AJU, 40000]], $this->inserted);
	}

	public function testApplyBeiAusgeschaltetemModulUebernimmtWeiter(): void {
		$this->enabled = false;
		$this->writer()->apply(self::JOURNAL, 'u', [
			$this->line(self::AUSRUESTUNG, 100000, 0),
			$this->line(self::BANK, 0, 100000),
		], $this->snapshot());
		$this->assertSame([[77, self::SEN, 60000], [77, self::AJU, 40000]], $this->inserted);
	}

	public function testApplyMitVerteilungBeiAusgeschaltetemModulWirft(): void {
		$this->enabled = false;
		$this->expectException(\InvalidArgumentException::class);
		$this->writer()->apply(self::JOURNAL, 'u', [
			$this->line(self::AUSRUESTUNG, 100000, 0, [[self::SEN, 100000]]),
			$this->line(self::BANK, 0, 100000),
		]);
	}

	/**
	 * Ausgeschaltet darf auch ein leerer Schlüssel nichts löschen: wer das
	 * Modul nicht sieht, kann nicht meinen, die Kostenstellen zu entfernen.
	 */
	public function testLeererSchluesselBeiAusgeschaltetemModulLoeschtNichts(): void {
		$this->enabled = false;
		$this->writer()->apply(self::JOURNAL, 'u', [
			$this->line(self::AUSRUESTUNG, 100000, 0, []),
			$this->line(self::BANK, 0, 100000, []),
		], $this->snapshot());
		$this->assertSame([[77, self::SEN, 60000], [77, self::AJU, 40000]], $this->inserted);
	}

	public function testLeererSchluesselBeiEingeschaltetemModulEntfernt(): void {
		$this->writer()->apply(self::JOURNAL, 'u', [
			$this->line(self::AUSRUESTUNG, 100000, 0, []),
			$this->line(self::BANK, 0, 100000),
		], $this->snapshot());
		$this->assertSame([], $this->inserted);
	}

	public function testGeaenderterBetragOhneAngabeVerwirft(): void {
		$this->writer()->apply(self::JOURNAL, 'u', [
			$this->line(self::AUSRUESTUNG, 90000, 0),
			$this->line(self::BANK, 0, 90000),
		], $this->snapshot());
		$this->assertSame([], $this->inserted);
	}

	public function testUngueltigeVerteilungWirftUndSchreibtNichts(): void {
		try {
			$this->writer()->apply(self::JOURNAL, 'u', [
				$this->line(self::AUSRUESTUNG, 100000, 0, [[self::SEN, 60000], [self::AJU, 40001]]),
				$this->line(self::BANK, 0, 100000),
			]);
			$this->fail('Überverteilung wurde angenommen.');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('1.000,01', $e->getMessage());
			$this->assertStringContainsString('1.000,00', $e->getMessage());
		}
		$this->assertSame([], $this->inserted);
	}

	public function testCheckMeldetFehlerAlsText(): void {
		$message = $this->writer()->check('u', [
			$this->line(self::AUSRUESTUNG, 100000, 0),
			$this->line(self::BANK, 0, 100000, [[self::SEN, 100000]]),
		]);
		$this->assertIsString($message);
		$this->assertNotSame('', $message);
	}

	public function testCheckOhneVerteilungIstNull(): void {
		$this->assertNull($this->writer()->check('u', [
			$this->line(self::AUSRUESTUNG, 100000, 0),
			$this->line(self::BANK, 0, 100000),
		]));
	}

	public function testApplyNenntDieVerteilungFuerDasProtokoll(): void {
		$summary = $this->writer()->apply(self::JOURNAL, 'u', [
			$this->line(self::AUSRUESTUNG, 100000, 0, [[self::SEN, 60000], [self::AJU, 40000]]),
			$this->line(self::BANK, 0, 100000),
		]);
		$this->assertSame('SEN 600,00, AJU 400,00', $summary);
	}

	public function testApplyOhneVerteilungLiefertLeereZusammenfassung(): void {
		$summary = $this->writer()->apply(self::JOURNAL, 'u', [
			$this->line(self::AUSRUESTUNG, 100000, 0),
			$this->line(self::BANK, 0, 100000),
		]);
		$this->assertSame('', $summary);
		$this->assertSame([], $this->inserted);
	}

	public function testSnapshotLiestZeilenMitVerteilung(): void {
		$this->allocations->method('findByJournal')->with(self::JOURNAL)->willReturn([
			77 => [['costCenterId' => self::SEN, 'amountCents' => 60000]],
		]);
		$this->assertSame([
			['accountId' => self::AUSRUESTUNG, 'debitCents' => 100000, 'creditCents' => 0, 'allocations' => [['costCenterId' => self::SEN, 'amountCents' => 60000]]],
			['accountId' => self::BANK, 'debitCents' => 0, 'creditCents' => 100000, 'allocations' => []],
		], $this->writer()->snapshot(self::JOURNAL));
	}

	public function testAfterReassignAufAufwandskontoBehaelt(): void {
		$this->allocations->expects($this->never())->method('deleteByLine');
		$this->writer()->afterReassign(77, $this->account(5400, 'expense'));
	}

	public function testAfterReassignAufBankkontoLoescht(): void {
		$this->allocations->expects($this->once())->method('deleteByLine')->with(77);
		$this->writer()->afterReassign(77, $this->account(self::BANK, 'asset'));
	}

	public function testForJournalsLiefertEuro(): void {
		$this->allocations->method('findByJournals')->with([self::JOURNAL])->willReturn([
			77 => [['costCenterId' => self::SEN, 'amountCents' => 3333]],
		]);
		$this->assertSame(
			[77 => [['costCenterId' => self::SEN, 'amount' => 33.33]]],
			$this->writer()->forJournals([self::JOURNAL]),
		);
	}
}
