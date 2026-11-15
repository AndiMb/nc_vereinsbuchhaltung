<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\BookingCostCenter;
use OCA\Vereinsbuchhaltung\Service\LedgerAggregator;
use OCA\Vereinsbuchhaltung\Service\Report\CostCenterAllocationReportService;
use PHPUnit\Framework\TestCase;

/**
 * Bericht "Kostenstellen" (Issue #7): Einnahmen, Ausgaben und Ergebnis je
 * Kostenstelle, aufgeteilt nach Konten.
 *
 * Der Bericht darf nie eine andere Summe zeigen als die übrigen Berichte.
 * Deshalb ist "Ohne Kostenstelle" kein eigener Zähler, sondern der Rest zur
 * Kontensumme – und die Schlusssumme stimmt damit immer mit dem Ergebnis
 * laut Kontensalden überein.
 */
class CostCenterAllocationReportTest extends TestCase {

	private const AUSRUESTUNG = 5300;
	private const MIETE = 5400;
	private const BEITRAEGE = 4000;
	private const BANK = 1200;
	private const DURCHLAUF = 1590;
	private const SEN = 3;
	private const AJU = 5;
	private const ALT = 9;

	private function account(int $id, string $type, string $name, bool $isBank = false): Account {
		$account = new Account();
		$account->setId($id);
		$account->setNumber((string)$id);
		$account->setName($name);
		$account->setType($type);
		$account->setIsBank($isBank);
		return $account;
	}

	/** @return list<Account> */
	private function accounts(): array {
		return [
			$this->account(self::MIETE, 'expense', 'Miete'),
			$this->account(self::AUSRUESTUNG, 'expense', 'Ausrüstung'),
			$this->account(self::BEITRAEGE, 'income', 'Beiträge'),
			$this->account(self::BANK, 'asset', 'Bank', true),
			$this->account(self::DURCHLAUF, 'asset', 'Durchlaufende Posten'),
		];
	}

	private function costCenter(int $id, string $code, string $name, bool $active = true): BookingCostCenter {
		$cc = new BookingCostCenter();
		$cc->setId($id);
		$cc->setCode($code);
		$cc->setName($name);
		$cc->setActive($active);
		return $cc;
	}

	/** @return list<BookingCostCenter> */
	private function costCenters(): array {
		return [
			$this->costCenter(self::SEN, 'SEN', 'Senioren'),
			$this->costCenter(self::AJU, 'AJU', 'A-Junioren'),
			$this->costCenter(self::ALT, 'ALT', 'Alte Herren', false),
		];
	}

	/**
	 * @param array<int, array{debit:int, credit:int}> $ledger
	 * @param list<array{0:int, 1:int, 2:int, 3:int}> $allocations [Kostenstelle, Konto, Soll, Haben]
	 * @param array<int, array{incomeCents:int, expenseCents:int}> $plans Kostenstelle => Planwerte
	 * @return array<string, mixed>
	 */
	private function compute(array $ledger, array $allocations, array $plans = []): array {
		return CostCenterAllocationReportService::compute(
			$this->accounts(),
			$ledger,
			array_map(static fn (array $a): array => ['costCenterId' => $a[0], 'accountId' => $a[1], 'debit' => $a[2], 'credit' => $a[3]], $allocations),
			$this->costCenters(),
			$plans,
		);
	}

	/** @param array<string, mixed> $report */
	private function center(array $report, string $code): ?array {
		foreach ($report['centers'] as $center) {
			if ($center['code'] === $code) {
				return $center;
			}
		}
		return null;
	}

	public function testTrikotBeispiel(): void {
		$report = $this->compute(
			[self::AUSRUESTUNG => ['debit' => 100000, 'credit' => 0], self::BANK => ['debit' => 0, 'credit' => 100000]],
			[[self::SEN, self::AUSRUESTUNG, 60000, 0], [self::AJU, self::AUSRUESTUNG, 40000, 0]],
		);

		$sen = $this->center($report, 'SEN');
		$this->assertSame(60000, $sen['expenseCents']);
		$this->assertSame(-60000, $sen['resultCents']);
		$this->assertSame(
			[['accountId' => self::AUSRUESTUNG, 'number' => '5300', 'name' => 'Ausrüstung', 'amountCents' => 60000]],
			$sen['expense'],
		);
		$this->assertSame(40000, $this->center($report, 'AJU')['expenseCents']);
		$this->assertSame(0, $report['unassigned']['expenseCents']);
		$this->assertSame([], $report['unassigned']['expense']);
		$this->assertSame(-100000, $report['total']['resultCents']);
		$this->assertTrue($report['reconciled']);
	}

	public function testKostenstellenSindNachKuerzelSortiert(): void {
		$report = $this->compute(
			[self::AUSRUESTUNG => ['debit' => 100000, 'credit' => 0]],
			[[self::SEN, self::AUSRUESTUNG, 60000, 0], [self::AJU, self::AUSRUESTUNG, 40000, 0]],
		);
		$this->assertSame(['AJU', 'SEN'], array_column($report['centers'], 'code'));
	}

	public function testTeilverteilungLandetImRest(): void {
		$report = $this->compute(
			[self::AUSRUESTUNG => ['debit' => 100000, 'credit' => 0]],
			[[self::SEN, self::AUSRUESTUNG, 60000, 0]],
		);
		$this->assertSame(60000, $this->center($report, 'SEN')['expenseCents']);
		$this->assertSame(40000, $report['unassigned']['expenseCents']);
		$this->assertSame(
			[['accountId' => self::AUSRUESTUNG, 'number' => '5300', 'name' => 'Ausrüstung', 'amountCents' => 40000]],
			$report['unassigned']['expense'],
		);
	}

	public function testEinnahmenUndAusgabenErgebenErgebnis(): void {
		$report = $this->compute(
			[self::AUSRUESTUNG => ['debit' => 60000, 'credit' => 0], self::BEITRAEGE => ['debit' => 0, 'credit' => 30000]],
			[[self::SEN, self::AUSRUESTUNG, 60000, 0], [self::SEN, self::BEITRAEGE, 0, 30000]],
		);
		$sen = $this->center($report, 'SEN');
		$this->assertSame(30000, $sen['incomeCents']);
		$this->assertSame(60000, $sen['expenseCents']);
		$this->assertSame(-30000, $sen['resultCents']);
		$this->assertSame('Beiträge', $sen['income'][0]['name']);
	}

	public function testKontenSindNachNummerSortiert(): void {
		$report = $this->compute(
			[self::AUSRUESTUNG => ['debit' => 100, 'credit' => 0], self::MIETE => ['debit' => 200, 'credit' => 0]],
			[],
		);
		$this->assertSame(['5300', '5400'], array_column($report['unassigned']['expense'], 'number'));
	}

	public function testBankkontenBleibenAussenVor(): void {
		$report = $this->compute(
			[self::BANK => ['debit' => 0, 'credit' => 100000], self::AUSRUESTUNG => ['debit' => 100000, 'credit' => 0]],
			[],
		);
		$numbers = array_merge(
			array_column($report['unassigned']['income'], 'number'),
			array_column($report['unassigned']['expense'], 'number'),
		);
		$this->assertSame(['5300'], $numbers);
	}

	/**
	 * Ein Durchlaufkonto trägt keine Kostenstelle, geht aber ins Ergebnis ein.
	 * Fehlte es im Rest, stimmte die Schlusssumme nicht mehr mit den übrigen
	 * Berichten überein.
	 */
	public function testErgebniswirksameKontenOhneKostenstelleStehenImRest(): void {
		$ledger = [
			self::DURCHLAUF => ['debit' => 5000, 'credit' => 0],
			self::AUSRUESTUNG => ['debit' => 100000, 'credit' => 0],
		];
		$report = $this->compute($ledger, [[self::SEN, self::AUSRUESTUNG, 100000, 0]]);
		$this->assertContains('1590', array_column($report['unassigned']['expense'], 'number'));
		$this->assertSame(
			LedgerAggregator::incomeExpense($this->accounts(), $ledger)['resultCents'],
			$report['total']['resultCents'],
		);
	}

	public function testGesamtEntsprichtKontensalden(): void {
		$ledger = [
			self::AUSRUESTUNG => ['debit' => 123456, 'credit' => 1000],
			self::MIETE => ['debit' => 50000, 'credit' => 0],
			self::BEITRAEGE => ['debit' => 500, 'credit' => 240000],
			self::BANK => ['debit' => 240000, 'credit' => 172956],
		];
		$report = $this->compute($ledger, [
			[self::SEN, self::AUSRUESTUNG, 60000, 0],
			[self::AJU, self::AUSRUESTUNG, 40000, 1000],
			[self::SEN, self::BEITRAEGE, 0, 100000],
		]);
		$expected = LedgerAggregator::incomeExpense($this->accounts(), $ledger);

		$this->assertSame($expected['resultCents'], $report['ledgerResultCents']);
		$this->assertSame($expected['resultCents'], $report['total']['resultCents']);
		$this->assertSame($expected['incomeCents'], $report['total']['incomeCents']);
		$this->assertSame($expected['expenseCents'], $report['total']['expenseCents']);
		$this->assertTrue($report['reconciled']);

		// Die Teile ergeben das Ganze.
		$parts = array_merge($report['centers'], [$report['unassigned']]);
		$this->assertSame($report['total']['resultCents'], array_sum(array_column($parts, 'resultCents')));
		$this->assertSame($report['total']['incomeCents'], array_sum(array_column($parts, 'incomeCents')));
		$this->assertSame($report['total']['expenseCents'], array_sum(array_column($parts, 'expenseCents')));
	}

	public function testInaktiveKostenstelleMitBetragErscheint(): void {
		$report = $this->compute(
			[self::AUSRUESTUNG => ['debit' => 100000, 'credit' => 0]],
			[[self::ALT, self::AUSRUESTUNG, 100000, 0]],
		);
		$alt = $this->center($report, 'ALT');
		$this->assertNotNull($alt);
		$this->assertFalse($alt['active']);
		$this->assertSame(100000, $alt['expenseCents']);
	}

	public function testKostenstelleOhneBetragErscheintNicht(): void {
		$report = $this->compute(
			[self::AUSRUESTUNG => ['debit' => 100000, 'credit' => 0]],
			[[self::SEN, self::AUSRUESTUNG, 100000, 0]],
		);
		$this->assertNull($this->center($report, 'AJU'));
	}

	/** Eine Erstattung (Haben auf dem Aufwandskonto) mindert die Ausgaben der Kostenstelle. */
	public function testGegenbuchungMindert(): void {
		$report = $this->compute(
			[self::AUSRUESTUNG => ['debit' => 100000, 'credit' => 20000]],
			[[self::SEN, self::AUSRUESTUNG, 100000, 20000]],
		);
		$this->assertSame(80000, $this->center($report, 'SEN')['expenseCents']);
		$this->assertSame(0, $report['unassigned']['expenseCents']);
	}

	public function testOhneBuchungenIstDerBerichtLeerUndAbgestimmt(): void {
		$report = $this->compute([], []);
		$this->assertSame([], $report['centers']);
		$this->assertSame(0, $report['total']['resultCents']);
		$this->assertTrue($report['reconciled']);
	}

	/** Verteilungen auf eine gelöschte Kostenstelle darf es nicht geben; falls doch, geht ihr Betrag nicht verloren. */
	public function testUnbekannteKostenstelleBleibtSichtbar(): void {
		$report = $this->compute(
			[self::AUSRUESTUNG => ['debit' => 100000, 'credit' => 0]],
			[[77, self::AUSRUESTUNG, 100000, 0]],
		);
		$this->assertCount(1, $report['centers']);
		$this->assertSame(100000, $report['centers'][0]['expenseCents']);
		$this->assertTrue($report['reconciled']);
	}

	// ---- Budget je Kostenstelle (Issue #335) ----

	public function testKostenstelleBekommtIhrenPlan(): void {
		$report = $this->compute(
			[self::AUSRUESTUNG => ['debit' => 60000, 'credit' => 0], self::BANK => ['debit' => 0, 'credit' => 60000]],
			[[self::SEN, self::AUSRUESTUNG, 60000, 0]],
			[self::SEN => ['incomeCents' => 250000, 'expenseCents' => 180000]],
		);

		$this->assertSame(['incomeCents' => 250000, 'expenseCents' => 180000, 'resultCents' => 70000], $this->center($report, 'SEN')['plan']);
		$this->assertTrue($report['planned']);
	}

	/** Geplant, aber noch nichts gebucht: die Kostenstelle erscheint trotzdem – sonst sähe man dem Bericht den Plan nicht an. */
	public function testKostenstelleMitPlanOhneIstErscheint(): void {
		$report = $this->compute(
			[self::AUSRUESTUNG => ['debit' => 60000, 'credit' => 0], self::BANK => ['debit' => 0, 'credit' => 60000]],
			[[self::SEN, self::AUSRUESTUNG, 60000, 0]],
			[self::AJU => ['incomeCents' => 0, 'expenseCents' => 90000]],
		);

		$aju = $this->center($report, 'AJU');
		$this->assertNotNull($aju);
		$this->assertSame([], $aju['income']);
		$this->assertSame([], $aju['expense']);
		$this->assertSame(0, $aju['resultCents']);
		$this->assertSame(['incomeCents' => 0, 'expenseCents' => 90000, 'resultCents' => -90000], $aju['plan']);
		// Die Kostenstelle ohne Plan trägt null, damit die Oberfläche nicht raten muss.
		$this->assertNull($this->center($report, 'SEN')['plan']);
		$this->assertSame(['AJU', 'SEN'], array_column($report['centers'], 'code'));
	}

	public function testOhnePlaeneIstPlannedFalseUndPlanNull(): void {
		$report = $this->compute(
			[self::AUSRUESTUNG => ['debit' => 60000, 'credit' => 0], self::BANK => ['debit' => 0, 'credit' => 60000]],
			[[self::SEN, self::AUSRUESTUNG, 60000, 0]],
		);

		$this->assertFalse($report['planned']);
		$this->assertNull($this->center($report, 'SEN')['plan']);
		$this->assertNull($this->center($report, 'AJU'));
	}

	/** Ein Plan ist keine Buchung: Ist-Summen und Abstimmung bleiben, wie sie ohne Plan wären. */
	public function testPlanAendertDieIstSummenNicht(): void {
		$ledger = [self::AUSRUESTUNG => ['debit' => 100000, 'credit' => 0], self::BANK => ['debit' => 0, 'credit' => 100000]];
		$allocations = [[self::SEN, self::AUSRUESTUNG, 60000, 0]];

		$without = $this->compute($ledger, $allocations);
		$with = $this->compute($ledger, $allocations, [self::SEN => ['incomeCents' => 1, 'expenseCents' => 2], self::AJU => ['incomeCents' => 3, 'expenseCents' => 4]]);

		$this->assertSame($without['total'], $with['total']);
		$this->assertSame($without['unassigned'], $with['unassigned']);
		$this->assertSame($without['reconciled'], $with['reconciled']);
		$this->assertTrue($with['reconciled']);
	}

	/** Planwerte einer gelöschten Kostenstelle gibt es nicht (das Löschen räumt sie ab); taucht doch einer auf, zeigt der Bericht ihn nicht. */
	public function testPlanEinerUnbekanntenKostenstelleWirdUebergangen(): void {
		$report = $this->compute([], [], [777 => ['incomeCents' => 100, 'expenseCents' => 100]]);

		$this->assertSame([], $report['centers']);
		$this->assertFalse($report['planned']);
	}
}
