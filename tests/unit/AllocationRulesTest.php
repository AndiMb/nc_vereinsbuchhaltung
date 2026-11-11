<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\CostCenter\AllocationRules;
use PHPUnit\Framework\TestCase;

/**
 * Regeln für die Verteilung einer Buchungszeile auf Kostenstellen (Issue #7).
 *
 * Zwei Dinge dürfen nicht passieren: eine Verteilung, die mehr verteilt, als
 * die Zeile hergibt – und eine Verteilung, die still verloren geht, weil
 * jemand die Buchung bearbeitet, ohne die Kostenstellen zu kennen (alter
 * Client, Modul ausgeschaltet). Ein fehlender Schlüssel `allocations` heißt
 * deshalb "nichts gesagt", ein vorhandener (auch leerer) heißt "ersetzen".
 */
class AllocationRulesTest extends TestCase {

	private const AUSRUESTUNG = 5300;
	private const BANK = 1200;
	private const SEN = 3;
	private const AJU = 5;
	private const ALT = 9;

	private const TYPES = [self::AUSRUESTUNG => 'expense', 5400 => 'expense', 4000 => 'income', self::BANK => 'asset'];
	private const KNOWN = [self::SEN, self::AJU, self::ALT];
	private const ACTIVE = [self::SEN, self::AJU];

	/**
	 * @param list<array{0:int, 1:int}>|null $allocations [Kostenstelle, Cent]; null = Schlüssel fehlt
	 * @return array<string, mixed>
	 */
	private function line(int $accountId, int $debit, int $credit, ?array $allocations = null): array {
		$line = ['accountId' => $accountId, 'debitCents' => $debit, 'creditCents' => $credit];
		if ($allocations !== null) {
			$line['allocations'] = array_map(
				static fn (array $a): array => ['costCenterId' => $a[0], 'amountCents' => $a[1]],
				$allocations,
			);
		}
		return $line;
	}

	/** @param list<array<string, mixed>> $lines */
	private function validate(array $lines, array $previous = []): ?array {
		return AllocationRules::validate($lines, self::TYPES, self::KNOWN, self::ACTIVE, $previous);
	}

	// ---- validate ----

	public function testZeileOhneSchluesselWirdNichtGeprueft(): void {
		$this->assertNull($this->validate([
			$this->line(self::AUSRUESTUNG, 100000, 0),
			$this->line(self::BANK, 0, 100000),
		]));
	}

	public function testSummeGleichZeilenbetragIstGueltig(): void {
		$this->assertNull($this->validate([
			$this->line(self::AUSRUESTUNG, 100000, 0, [[self::SEN, 60000], [self::AJU, 40000]]),
			$this->line(self::BANK, 0, 100000),
		]));
	}

	public function testSummeUeberZeilenbetrag(): void {
		$error = $this->validate([
			$this->line(self::AUSRUESTUNG, 100000, 0, [[self::SEN, 60000], [self::AJU, 40001]]),
			$this->line(self::BANK, 0, 100000),
		]);
		$this->assertSame('alloc_exceeds_line', $error['code'] ?? null);
		$this->assertSame(0, $error['line'] ?? null);
		$this->assertSame(['allocated' => 100001, 'line' => 100000], $error['params'] ?? null);
	}

	public function testTeilverteilungIstGueltig(): void {
		$this->assertNull($this->validate([
			$this->line(self::AUSRUESTUNG, 100000, 0, [[self::SEN, 60000]]),
			$this->line(self::BANK, 0, 100000),
		]));
	}

	public function testVerteilungAufHabenZeileRechnetMitDemHabenBetrag(): void {
		$this->assertNull($this->validate([
			$this->line(self::BANK, 50000, 0),
			$this->line(4000, 0, 50000, [[self::SEN, 50000]]),
		]));
	}

	public function testDoppelteKostenstelle(): void {
		$error = $this->validate([
			$this->line(self::AUSRUESTUNG, 100000, 0, [[self::SEN, 30000], [self::SEN, 30000]]),
			$this->line(self::BANK, 0, 100000),
		]);
		$this->assertSame('alloc_duplicate', $error['code'] ?? null);
	}

	public function testUnbekannteKostenstelle(): void {
		$error = $this->validate([
			$this->line(self::AUSRUESTUNG, 100000, 0, [[77, 30000]]),
			$this->line(self::BANK, 0, 100000),
		]);
		$this->assertSame('alloc_unknown', $error['code'] ?? null);
	}

	public function testBetragNullWirdAbgelehnt(): void {
		$error = $this->validate([
			$this->line(self::AUSRUESTUNG, 100000, 0, [[self::SEN, 0]]),
			$this->line(self::BANK, 0, 100000),
		]);
		$this->assertSame('alloc_amount', $error['code'] ?? null);
	}

	public function testNegativerBetragWirdAbgelehnt(): void {
		$error = $this->validate([
			$this->line(self::AUSRUESTUNG, 100000, 0, [[self::SEN, -100]]),
			$this->line(self::BANK, 0, 100000),
		]);
		$this->assertSame('alloc_amount', $error['code'] ?? null);
	}

	public function testBestandskontoMitVerteilung(): void {
		$error = $this->validate([
			$this->line(self::AUSRUESTUNG, 100000, 0),
			$this->line(self::BANK, 0, 100000, [[self::SEN, 100000]]),
		]);
		$this->assertSame('alloc_account_type', $error['code'] ?? null);
		$this->assertSame(1, $error['line'] ?? null);
	}

	public function testBestandskontoMitLeererVerteilungIstGueltig(): void {
		$this->assertNull($this->validate([
			$this->line(self::AUSRUESTUNG, 100000, 0, []),
			$this->line(self::BANK, 0, 100000, []),
		]));
	}

	public function testKontoOhneBekanntenTypTraegtKeineVerteilung(): void {
		$error = $this->validate([
			$this->line(8888, 100000, 0, [[self::SEN, 100000]]),
			$this->line(self::BANK, 0, 100000),
		]);
		$this->assertSame('alloc_account_type', $error['code'] ?? null);
	}

	public function testInaktiveNeuZugeordnet(): void {
		$error = $this->validate([
			$this->line(self::AUSRUESTUNG, 100000, 0, [[self::ALT, 100000]]),
			$this->line(self::BANK, 0, 100000),
		]);
		$this->assertSame('alloc_inactive', $error['code'] ?? null);
	}

	public function testInaktiveBereitsZugeordnetBleibtGueltig(): void {
		$this->assertNull($this->validate(
			[
				$this->line(self::AUSRUESTUNG, 100000, 0, [[self::ALT, 100000]]),
				$this->line(self::BANK, 0, 100000),
			],
			[self::AUSRUESTUNG => [self::ALT]],
		));
	}

	public function testInaktiveAufAndererZeileBisherZugeordnetGiltNicht(): void {
		$error = $this->validate(
			[
				$this->line(self::AUSRUESTUNG, 100000, 0, [[self::ALT, 100000]]),
				$this->line(self::BANK, 0, 100000),
			],
			[5400 => [self::ALT]],
		);
		$this->assertSame('alloc_inactive', $error['code'] ?? null);
	}

	public function testFehlerNenntDenZeilenindex(): void {
		$error = $this->validate([
			$this->line(self::BANK, 0, 100000),
			$this->line(self::AUSRUESTUNG, 60000, 0, [[self::SEN, 60000]]),
			$this->line(5400, 40000, 0, [[self::AJU, 40001]]),
		]);
		$this->assertSame('alloc_exceeds_line', $error['code'] ?? null);
		$this->assertSame(2, $error['line'] ?? null);
	}

	// ---- carryOver ----

	/** @return list<array<string, mixed>> */
	private function old(): array {
		return [
			$this->line(self::AUSRUESTUNG, 100000, 0, [[self::SEN, 60000], [self::AJU, 40000]]),
			$this->line(self::BANK, 0, 100000, []),
		];
	}

	private const SPLIT = [
		['costCenterId' => self::SEN, 'amountCents' => 60000],
		['costCenterId' => self::AJU, 'amountCents' => 40000],
	];

	public function testGleichesKontoGleicherBetragUebernimmt(): void {
		$new = AllocationRules::carryOver($this->old(), [
			$this->line(self::AUSRUESTUNG, 100000, 0),
			$this->line(self::BANK, 0, 100000),
		]);
		$this->assertSame(self::SPLIT, $new[0]['allocations']);
		$this->assertSame([], $new[1]['allocations']);
	}

	public function testReihenfolgeDerZeilenSpieltKeineRolle(): void {
		$new = AllocationRules::carryOver($this->old(), [
			$this->line(self::BANK, 0, 100000),
			$this->line(self::AUSRUESTUNG, 100000, 0),
		]);
		$this->assertSame([], $new[0]['allocations']);
		$this->assertSame(self::SPLIT, $new[1]['allocations']);
	}

	public function testGeaenderterBetragVerwirft(): void {
		$new = AllocationRules::carryOver($this->old(), [
			$this->line(self::AUSRUESTUNG, 90000, 0),
			$this->line(self::BANK, 0, 90000),
		]);
		$this->assertSame([], $new[0]['allocations']);
	}

	public function testGeaendertesKontoVerwirft(): void {
		$new = AllocationRules::carryOver($this->old(), [
			$this->line(5400, 100000, 0),
			$this->line(self::BANK, 0, 100000),
		]);
		$this->assertSame([], $new[0]['allocations']);
	}

	public function testSeitenwechselVerwirft(): void {
		$new = AllocationRules::carryOver($this->old(), [
			$this->line(self::AUSRUESTUNG, 0, 100000),
			$this->line(self::BANK, 100000, 0),
		]);
		$this->assertSame([], $new[0]['allocations']);
	}

	public function testVorhandenerLeererSchluesselGewinnt(): void {
		$new = AllocationRules::carryOver($this->old(), [
			$this->line(self::AUSRUESTUNG, 100000, 0, []),
			$this->line(self::BANK, 0, 100000),
		]);
		$this->assertSame([], $new[0]['allocations']);
	}

	public function testVorhandenerSchluesselErsetzt(): void {
		$new = AllocationRules::carryOver($this->old(), [
			$this->line(self::AUSRUESTUNG, 100000, 0, [[self::SEN, 100000]]),
			$this->line(self::BANK, 0, 100000),
		]);
		$this->assertSame([['costCenterId' => self::SEN, 'amountCents' => 100000]], $new[0]['allocations']);
	}

	public function testNeueZeileOhneAltesGegenstueckBleibtLeer(): void {
		$new = AllocationRules::carryOver([], [
			$this->line(self::AUSRUESTUNG, 100000, 0),
			$this->line(self::BANK, 0, 100000),
		]);
		$this->assertSame([], $new[0]['allocations']);
		$this->assertSame([], $new[1]['allocations']);
	}

	public function testUebrigeFelderDerZeileBleiben(): void {
		$new = AllocationRules::carryOver($this->old(), [$this->line(self::AUSRUESTUNG, 100000, 0)]);
		$this->assertSame(self::AUSRUESTUNG, $new[0]['accountId']);
		$this->assertSame(100000, $new[0]['debitCents']);
		$this->assertSame(0, $new[0]['creditCents']);
	}

	// ---- previousOf ----

	public function testPreviousOfNenntDieBisherigenKostenstellenJeKonto(): void {
		$this->assertSame(
			[self::AUSRUESTUNG => [self::SEN, self::AJU]],
			AllocationRules::previousOf($this->old()),
		);
	}
}
