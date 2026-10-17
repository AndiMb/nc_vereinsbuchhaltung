<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\CostCenter;
use OCA\Vereinsbuchhaltung\Db\CostCenterMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Service\Report\CostCenterResolver;
use OCA\Vereinsbuchhaltung\Service\ReportService;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Die Zuordnung Konto → Kostenstelle in allen drei Modi, und dass der
 * Kostenstellenbericht nach dem Herauslösen dieselben Gruppen bildet wie
 * vorher.
 */
class CostCenterResolverTest extends TestCase {

	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		return $l10n;
	}

	private function account(int $id, string $number, string $type = 'expense', ?int $costCenterId = null): Account {
		$a = new Account();
		$a->setId($id);
		$a->setNumber($number);
		$a->setName('Konto ' . $number);
		$a->setType($type);
		$a->setIsBank(false);
		$a->setCostCenterId($costCenterId);
		return $a;
	}

	public function testGruppeAusDerKontonummer(): void {
		$r = new CostCenterResolver('group', ['51' => 'Landesjugendchor'], [], $this->l10n());

		$this->assertSame(['key' => '51', 'code' => '51', 'name' => 'Landesjugendchor'], $r->resolve($this->account(1, '427 51')));
		$this->assertSame('51', $r->resolve($this->account(2, '111 51 2021'))['key'], 'Jahreskonto gehört zum Projekt');
		$this->assertSame(['key' => '', 'code' => null, 'name' => '(ohne Auswertungsgruppe)'], $r->resolve($this->account(3, '427')));
	}

	public function testUnbenannteKostenstelleBekommtStandardnamen(): void {
		$r = new CostCenterResolver('group', [], [], $this->l10n());

		$this->assertSame('Ideeller Bereich', $r->resolve($this->account(1, '546 01'))['name']);
		$this->assertSame('Verbandszeitung', $r->resolve($this->account(2, '531 11'))['name']);
		$this->assertSame('Auswertungsgruppe 63', $r->resolve($this->account(3, '546 63'))['name']);
	}

	public function testJedesKontoIstEigeneGruppe(): void {
		$r = new CostCenterResolver('account', [], [], $this->l10n());

		$this->assertSame(['key' => 'acc-7', 'code' => '4000', 'name' => 'Konto 4000'], $r->resolve($this->account(7, '4000')));
		$this->assertSame([], $r->definedGroups());
	}

	public function testManuelleZuordnung(): void {
		$defined = [3 => ['code' => 'SF', 'name' => 'Sommerfest']];
		$r = new CostCenterResolver('manual', ['SF' => 'Sommerfest'], $defined, $this->l10n());

		$this->assertSame(['key' => 'cc-3', 'code' => 'SF', 'name' => 'Sommerfest'], $r->resolve($this->account(1, '4000', 'income', 3)));
		$this->assertSame('', $r->resolve($this->account(2, '4100', 'income', null))['key']);
		$this->assertSame('', $r->resolve($this->account(3, '4200', 'income', 99))['key'], 'gelöschte Kostenstelle zählt als ohne');
		$this->assertSame([['key' => 'cc-3', 'code' => 'SF', 'name' => 'Sommerfest']], $r->definedGroups());
	}

	// --- Regression: Kostenstellenbericht ---------------------------------

	/**
	 * @param list<Account> $accounts
	 * @param array<int, array{debit:int, credit:int}> $sums
	 * @param list<CostCenter> $costCenters
	 */
	private function report(string $mode, array $accounts, array $sums, array $costCenters = []): array {
		$accountMapper = $this->createMock(AccountMapper::class);
		$accountMapper->method('findAll')->willReturn($accounts);
		$lineMapper = $this->createMock(JournalLineMapper::class);
		$lineMapper->method('sumByAccount')->willReturn($sums);
		$ccMapper = $this->createMock(CostCenterMapper::class);
		$ccMapper->method('findAll')->willReturn($costCenters);
		$periods = $this->createMock(PeriodService::class);
		$periods->method('range')->willReturn(['2026-01-01', '2026-12-31']);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn($mode);

		$service = new ReportService($accountMapper, $lineMapper, $ccMapper, $periods, $config, $this->l10n());
		return $service->costCenterReport('u');
	}

	private function costCenter(int $id, string $code, string $name): CostCenter {
		$cc = new CostCenter();
		$cc->setId($id);
		$cc->setCode($code);
		$cc->setName($name);
		return $cc;
	}

	public function testBerichtImGruppenmodus(): void {
		$accounts = [
			$this->account(1, '111 51', 'income'),
			$this->account(2, '427 51'),
			$this->account(3, '427 01'),
			$this->account(4, '546 52'),
		];
		$sums = [1 => ['debit' => 0, 'credit' => 5000], 2 => ['debit' => 2000, 'credit' => 0], 3 => ['debit' => 700, 'credit' => 0]];

		$r = $this->report('group', $accounts, $sums, [$this->costCenter(1, '51', 'Landesjugendchor')]);

		$this->assertSame('group', $r['mode']);
		$this->assertSame(['01', '51', '52'], array_column($r['costCenters'], 'code'));
		$this->assertSame('Landesjugendchor', $r['costCenters'][1]['name']);
		$this->assertSame(30.0, (float)$r['costCenters'][1]['result']);
		$this->assertSame([], $r['costCenters'][2]['accounts'], 'unbewegte Konten fehlen im Gruppenmodus');
		$this->assertSame(23.0, (float)$r['totals']['result']);
	}

	public function testBerichtImKontomodusLaesstUnbewegteKontenWeg(): void {
		$accounts = [$this->account(1, '4000', 'income'), $this->account(2, '4100', 'income')];
		$sums = [1 => ['debit' => 0, 'credit' => 1000]];

		$r = $this->report('account', $accounts, $sums);

		$this->assertSame(['4000'], array_column($r['costCenters'], 'code'));
	}

	public function testBerichtImManuellenModusZeigtLeereKostenstellen(): void {
		$accounts = [$this->account(1, '4000', 'income', 1), $this->account(2, '5000', 'expense')];
		$sums = [1 => ['debit' => 0, 'credit' => 1000], 2 => ['debit' => 400, 'credit' => 0]];

		$r = $this->report('manual', $accounts, $sums, [$this->costCenter(1, 'A', 'Alpha'), $this->costCenter(2, 'B', 'Beta')]);

		$this->assertSame(['A', 'B', null], array_column($r['costCenters'], 'code'));
		$this->assertSame([], $r['costCenters'][1]['accounts']);
		$this->assertSame('(ohne Auswertungsgruppe)', $r['costCenters'][2]['name']);
		$this->assertCount(1, $r['costCenters'][2]['accounts'], 'ausdrückliche Zuordnung zeigt auch Konten ohne Kostenstelle');
	}
}
