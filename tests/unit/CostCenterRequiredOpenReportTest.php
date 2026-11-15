<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\BookingCostCenter;
use OCA\Vereinsbuchhaltung\Service\Export\CostCenterAllocationExport;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Service\Report\CostCenterAllocationReportService;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\ClubClockStub;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\ClubCurrencyStub;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * „Pflichtkonten mit offenem Betrag“ im Kostenstellen-Bericht (Issue #334):
 * die Vollständigkeitsprüfung zum Kennzeichen „Kostenstelle verpflichtend“.
 * Gelistet wird je Pflichtkonto, was keiner Kostenstelle zugeordnet ist –
 * auch aus automatisch erzeugten Buchungen, die niemand im Dialog gesehen hat.
 */
class CostCenterRequiredOpenReportTest extends TestCase {

	private const AUSRUESTUNG = 5300;
	private const MIETE = 5400;
	private const BEITRAEGE = 4000;
	private const SEN = 3;

	private function account(int $id, string $type, string $name, bool $required): Account {
		$account = new Account();
		$account->setId($id);
		$account->setNumber((string)$id);
		$account->setName($name);
		$account->setType($type);
		$account->setIsBank(false);
		$account->setCostCenterRequired($required);
		return $account;
	}

	/**
	 * @param array<int, array{debit:int, credit:int}> $ledger
	 * @param list<array{0:int, 1:int, 2:int, 3:int}> $allocations [Kostenstelle, Konto, Soll, Haben]
	 * @return array<string, mixed>
	 */
	private function compute(array $ledger, array $allocations, bool $anyRequired = true): array {
		$cc = new BookingCostCenter();
		$cc->setId(self::SEN);
		$cc->setCode('SEN');
		$cc->setName('Senioren');
		$cc->setActive(true);
		return CostCenterAllocationReportService::compute(
			[
				$this->account(self::MIETE, 'expense', 'Miete', $anyRequired),
				$this->account(self::AUSRUESTUNG, 'expense', 'Ausrüstung', $anyRequired),
				$this->account(self::BEITRAEGE, 'income', 'Beiträge', false),
			],
			$ledger,
			array_map(static fn (array $a): array => ['costCenterId' => $a[0], 'accountId' => $a[1], 'debit' => $a[2], 'credit' => $a[3]], $allocations),
			[$cc],
		);
	}

	public function testPflichtkontoMitRestStehtInRequiredOpen(): void {
		$report = $this->compute(
			[self::AUSRUESTUNG => ['debit' => 100000, 'credit' => 0]],
			[[self::SEN, self::AUSRUESTUNG, 60000, 0]],
		);
		$this->assertSame(
			[['accountId' => self::AUSRUESTUNG, 'number' => '5300', 'name' => 'Ausrüstung', 'amountCents' => 40000, 'kind' => 'expense']],
			$report['requiredOpen'],
		);
	}

	public function testPflichtkontoGanzOhneZuordnungStehtMitDemVollenBetrag(): void {
		$report = $this->compute([self::MIETE => ['debit' => 5000, 'credit' => 0]], []);
		$this->assertSame(5000, $report['requiredOpen'][0]['amountCents']);
	}

	public function testPflichtkontoVollVerteiltFehlt(): void {
		$report = $this->compute(
			[self::AUSRUESTUNG => ['debit' => 100000, 'credit' => 0]],
			[[self::SEN, self::AUSRUESTUNG, 100000, 0]],
		);
		$this->assertSame([], $report['requiredOpen']);
	}

	public function testKontoOhneKennzeichenFehlt(): void {
		$report = $this->compute([self::BEITRAEGE => ['debit' => 0, 'credit' => 30000]], []);
		$this->assertSame([], $report['requiredOpen']);
	}

	public function testOhnePflichtkontenIstRequiredOpenLeer(): void {
		$report = $this->compute([self::AUSRUESTUNG => ['debit' => 100000, 'credit' => 0]], [], false);
		$this->assertSame([], $report['requiredOpen']);
	}

	public function testPflichtkontenSindNachNummerSortiert(): void {
		$report = $this->compute(
			[self::MIETE => ['debit' => 100, 'credit' => 0], self::AUSRUESTUNG => ['debit' => 200, 'credit' => 0]],
			[],
		);
		$this->assertSame(['5300', '5400'], array_column($report['requiredOpen'], 'number'));
	}

	public function testDieUebrigenSummenBleibenUnveraendert(): void {
		$ledger = [self::AUSRUESTUNG => ['debit' => 100000, 'credit' => 0]];
		$with = $this->compute($ledger, [[self::SEN, self::AUSRUESTUNG, 60000, 0]]);
		$without = $this->compute($ledger, [[self::SEN, self::AUSRUESTUNG, 60000, 0]], false);
		unset($with['requiredOpen'], $without['requiredOpen']);
		$this->assertSame($without, $with);
	}

	// ---- Druckseite und CSV ----

	/** @param list<array<string, mixed>> $requiredOpen */
	private function export(array $requiredOpen): CostCenterAllocationExport {
		$empty = ['income' => [], 'expense' => [], 'incomeCents' => 0, 'expenseCents' => 0, 'resultCents' => 0];
		$service = $this->createMock(CostCenterAllocationReportService::class);
		$service->method('report')->willReturn([
			'centers' => [],
			'unassigned' => [
				'income' => [],
				'expense' => [['accountId' => self::AUSRUESTUNG, 'number' => '5300', 'name' => 'Ausrüstung', 'amountCents' => 40000]],
				'incomeCents' => 0, 'expenseCents' => 40000, 'resultCents' => -40000,
			],
			'total' => ['incomeCents' => 0, 'expenseCents' => 40000, 'resultCents' => -40000],
			'ledgerResultCents' => -40000,
			'reconciled' => true,
			'requiredOpen' => $requiredOpen,
		] + ['_' => $empty]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		return new CostCenterAllocationExport(
			$service,
			$this->createMock(PeriodService::class),
			$this->createMock(IAppConfig::class),
			$l10n,
			ClubClockStub::at('2026-11-12 10:00:00'),
			ClubCurrencyStub::of($this),
		);
	}

	private const OPEN = [['accountId' => self::AUSRUESTUNG, 'number' => '5300', 'name' => 'Ausrüstung', 'amountCents' => 40000, 'kind' => 'expense']];

	public function testDruckseiteNenntPflichtkontenMitOffenemBetrag(): void {
		$html = $this->export(self::OPEN)->html('u', null);
		$this->assertStringContainsString('Pflichtkonten mit offenem Betrag', $html);
		$this->assertLessThan(
			strpos($html, 'Ohne Kostenstelle'),
			strpos($html, 'Pflichtkonten mit offenem Betrag'),
			'Der Hinweis steht vor den Blöcken.',
		);
	}

	public function testDruckseiteOhnePflichtkontenHatDenAbschnittNicht(): void {
		$this->assertStringNotContainsString('Pflichtkonten', $this->export([])->html('u', null));
	}

	public function testCsvNenntPflichtkonten(): void {
		$csv = $this->export(self::OPEN)->csv('u', null)->content;
		$this->assertStringContainsString('"Pflichtkonto offen";"Ausgabe";"5300";"Ausrüstung";"400,00"', $csv);
	}

	public function testCsvOhnePflichtkontenBleibtWieBisher(): void {
		$this->assertStringNotContainsString('Pflichtkonto', $this->export([])->csv('u', null)->content);
	}
}
