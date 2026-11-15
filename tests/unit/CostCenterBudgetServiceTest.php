<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\BookingCostCenter;
use OCA\Vereinsbuchhaltung\Db\BookingCostCenterMapper;
use OCA\Vereinsbuchhaltung\Db\CostCenterBudgetMapper;
use OCA\Vereinsbuchhaltung\Db\Period;
use OCA\Vereinsbuchhaltung\Exception\PeriodNotFoundException;
use OCA\Vereinsbuchhaltung\Service\CostCenter\CostCenterBudgetService;
use OCA\Vereinsbuchhaltung\Service\CostCenterSettings;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Budget je Kostenstelle (Issue #335): zwei Planwerte – Einnahmen und
 * Ausgaben – je Kostenstelle und Geschäftsjahr. Ein Plan ist keine Buchung:
 * er lässt sich auch für ein abgeschlossenes Jahr ändern.
 */
class CostCenterBudgetServiceTest extends TestCase {

	private CostCenterSettings&MockObject $settings;
	private BookingCostCenterMapper&MockObject $costCenters;
	private CostCenterBudgetMapper&MockObject $budgets;
	private PeriodService&MockObject $periods;

	protected function setUp(): void {
		$this->settings = $this->createMock(CostCenterSettings::class);
		$this->costCenters = $this->createMock(BookingCostCenterMapper::class);
		$this->budgets = $this->createMock(CostCenterBudgetMapper::class);
		$this->periods = $this->createMock(PeriodService::class);
	}

	private function service(bool $enabled = true): CostCenterBudgetService {
		$this->settings->method('isEnabled')->willReturn($enabled);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		return new CostCenterBudgetService($this->settings, $this->costCenters, $this->budgets, $this->periods, $l10n);
	}

	private function known(): void {
		$this->periods->method('find')->willReturn(new Period());
		$this->costCenters->method('find')->willReturn(new BookingCostCenter());
	}

	public function testSetzenSpeichertCentwerte(): void {
		$this->known();
		$this->budgets->expects($this->once())->method('upsert')->with(3, 7, 250000, 180000);

		$result = $this->service()->set('u', 7, 3, 250000, 180000);

		$this->assertSame(['costCenterId' => 3, 'periodId' => 7, 'incomeCents' => 250000, 'expenseCents' => 180000], $result);
	}

	/** Beide Werte 0 heißt „kein Plan“ – der Mapper löscht die Zeile dann. */
	public function testBeideNullWerdenAnDenMapperDurchgereicht(): void {
		$this->known();
		$this->budgets->expects($this->once())->method('upsert')->with(3, 7, 0, 0);

		$this->service()->set('u', 7, 3, 0, 0);
	}

	public function testNegativerWertWirdAbgelehnt(): void {
		$this->known();
		$this->budgets->expects($this->never())->method('upsert');

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->set('u', 7, 3, 100, -1);
	}

	public function testModulAusWirdAbgelehnt(): void {
		$this->known();
		$this->budgets->expects($this->never())->method('upsert');

		$this->expectExceptionMessage('ausgeschaltet');
		$this->service(false)->set('u', 7, 3, 100, 100);
	}

	public function testUnbekannteKostenstelleWirft(): void {
		$this->periods->method('find')->willReturn(new Period());
		$this->costCenters->method('find')->willThrowException(new DoesNotExistException('weg'));
		$this->budgets->expects($this->never())->method('upsert');

		$this->expectException(DoesNotExistException::class);
		$this->service()->set('u', 7, 99, 100, 100);
	}

	/** Ohne diese Prüfung entstünde ein Planwert, den keine Ansicht je zeigt. */
	public function testUnbekannterZeitraumWirft(): void {
		$this->periods->method('find')->willThrowException(new PeriodNotFoundException('weg'));
		$this->budgets->expects($this->never())->method('upsert');

		$this->expectException(PeriodNotFoundException::class);
		$this->service()->set('u', 99, 3, 100, 100);
	}

	public function testLesenBleibtBeiAusgeschaltetemModulMoeglich(): void {
		$this->periods->method('find')->willReturn(new Period());
		$this->budgets->method('findByPeriod')->with(7)->willReturn([3 => ['incomeCents' => 250000, 'expenseCents' => 0]]);

		$this->assertSame(
			[['costCenterId' => 3, 'incomeCents' => 250000, 'expenseCents' => 0]],
			$this->service(false)->forPeriod('u', 7),
		);
	}
}
