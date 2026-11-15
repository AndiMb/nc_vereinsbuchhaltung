<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BookingCostCenter;
use OCA\Vereinsbuchhaltung\Db\BookingCostCenterMapper;
use OCA\Vereinsbuchhaltung\Db\RuleMapper;
use OCA\Vereinsbuchhaltung\Service\CostCenter\CostCenterDefaults;
use OCA\Vereinsbuchhaltung\Service\CostCenterSettings;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Vorgabe-Kostenstellen (Regeln, Rechnungen, Beitragsgruppen) scheitern
 * weich: lässt sich eine Vorgabe nicht anwenden, kommt "nichts gesagt"
 * heraus und die Buchung entsteht ohne Kostenstelle. Kein Import, keine
 * Zahlung und kein Einzug soll an einer Kostenstelle hängen bleiben.
 */
class CostCenterDefaultsTest extends TestCase {

	private const SEN = 3;
	private const ALT = 9;
	private const AUFWAND = 5300;
	private const BANK = 1200;

	private bool $enabled = true;

	private function defaults(int $rulesUsing = 0): CostCenterDefaults {
		$settings = $this->createMock(CostCenterSettings::class);
		$settings->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);

		$cc = static function (int $id, bool $active): BookingCostCenter {
			$c = new BookingCostCenter();
			$c->setId($id);
			$c->setCode('C' . $id);
			$c->setActive($active);
			return $c;
		};
		$costCenters = $this->createMock(BookingCostCenterMapper::class);
		$costCenters->method('findAll')->willReturn([$cc(self::SEN, true), $cc(self::ALT, false)]);

		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('find')->willReturnCallback(static function (int $id): Account {
			if ($id !== self::AUFWAND && $id !== self::BANK) {
				throw new DoesNotExistException('kein Konto');
			}
			$a = new Account();
			$a->setId($id);
			$a->setType($id === self::AUFWAND ? 'expense' : 'asset');
			return $a;
		});

		$rules = $this->createMock(RuleMapper::class);
		$rules->method('countByCostCenter')->willReturn($rulesUsing);

		return new CostCenterDefaults($settings, $costCenters, $accounts, $rules);
	}

	public function testVolleZuordnungBeiAktiverKostenstelleUndAufwandskonto(): void {
		$this->assertSame(
			[['costCenterId' => self::SEN, 'amountCents' => 12345]],
			$this->defaults()->fullAllocation('u', self::SEN, self::AUFWAND, 12345),
		);
	}

	public function testModulAusLiefertNull(): void {
		$this->enabled = false;
		$this->assertNull($this->defaults()->fullAllocation('u', self::SEN, self::AUFWAND, 12345));
	}

	public function testInaktiveKostenstelleLiefertNull(): void {
		$this->assertNull($this->defaults()->fullAllocation('u', self::ALT, self::AUFWAND, 12345));
	}

	public function testUnbekannteKostenstelleLiefertNull(): void {
		$this->assertNull($this->defaults()->fullAllocation('u', 77, self::AUFWAND, 12345));
	}

	public function testKontoOhneKostenstellenFaehigkeitLiefertNull(): void {
		$this->assertNull($this->defaults()->fullAllocation('u', self::SEN, self::BANK, 12345));
	}

	public function testUnbekanntesKontoLiefertNull(): void {
		$this->assertNull($this->defaults()->fullAllocation('u', self::SEN, 4711, 12345));
	}

	public function testOhneVorgabeLiefertNull(): void {
		$this->assertNull($this->defaults()->fullAllocation('u', null, self::AUFWAND, 12345));
	}

	public function testBetragNullOderNegativLiefertNull(): void {
		$this->assertNull($this->defaults()->fullAllocation('u', self::SEN, self::AUFWAND, 0));
		$this->assertNull($this->defaults()->fullAllocation('u', self::SEN, self::AUFWAND, -5));
	}

	public function testUsableIdsNurAktive(): void {
		$this->assertSame([self::SEN], $this->defaults()->usableIds('u'));
	}

	public function testUsableIdsLeerBeiAusgeschaltetemModul(): void {
		$this->enabled = false;
		$this->assertSame([], $this->defaults()->usableIds('u'));
	}

	public function testUsageZaehltRegeln(): void {
		$this->assertSame(2, $this->defaults(2)->usage(self::SEN)['rules']);
	}
}
