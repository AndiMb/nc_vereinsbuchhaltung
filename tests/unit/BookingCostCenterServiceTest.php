<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\BookingCostCenter;
use OCA\Vereinsbuchhaltung\Db\BookingCostCenterMapper;
use OCA\Vereinsbuchhaltung\Db\LineAllocationMapper;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\CostCenter\BookingCostCenterService;
use OCA\Vereinsbuchhaltung\Service\CostCenterSettings;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Stammdaten der Kostenstellen an der Buchungszeile (Issue #7): Kürzel und
 * Name werden geprüft, eine verwendete Kostenstelle lässt sich nicht löschen
 * (nur deaktivieren), und bei ausgeschaltetem Modul wird nichts geschrieben.
 */
class BookingCostCenterServiceTest extends TestCase {

	private BookingCostCenterMapper&MockObject $mapper;
	private LineAllocationMapper&MockObject $allocations;
	private bool $enabled = true;

	protected function setUp(): void {
		$this->enabled = true;
		$this->mapper = $this->createMock(BookingCostCenterMapper::class);
		$this->allocations = $this->createMock(LineAllocationMapper::class);
		$this->mapper->method('insert')->willReturnCallback(static function (BookingCostCenter $cc): BookingCostCenter {
			$cc->setId(11);
			return $cc;
		});
		$this->mapper->method('update')->willReturnArgument(0);
	}

	private function service(): BookingCostCenterService {
		$settings = $this->createMock(CostCenterSettings::class);
		$settings->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		return new BookingCostCenterService(
			$this->mapper,
			$this->allocations,
			$settings,
			$this->createMock(AuditService::class),
			$l10n,
		);
	}

	private function existing(int $id, string $code, string $name = 'Senioren', bool $active = true): BookingCostCenter {
		$cc = new BookingCostCenter();
		$cc->setId($id);
		$cc->setUserId('u');
		$cc->setCode($code);
		$cc->setName($name);
		$cc->setActive($active);
		return $cc;
	}

	public function testKuerzelWirdGetrimmt(): void {
		$cc = $this->service()->create('u', ' SEN ', ' Senioren ');
		$this->assertSame('SEN', $cc->getCode());
		$this->assertSame('Senioren', $cc->getName());
		$this->assertTrue($cc->isActive());
	}

	public function testKuerzelMitNeunZeichenWirdAbgelehnt(): void {
		$this->mapper->expects($this->never())->method('insert');
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->create('u', 'SENIOREN1', 'Senioren');
	}

	public function testKuerzelMitAchtZeichenIstErlaubt(): void {
		$this->assertSame('SENIOREN', $this->service()->create('u', 'SENIOREN', 'Senioren')->getCode());
	}

	public function testLeeresKuerzelWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->create('u', '   ', 'Senioren');
	}

	public function testLeererNameWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->create('u', 'SEN', '  ');
	}

	public function testDoppeltesKuerzelWirdAbgelehnt(): void {
		$this->mapper->method('findByCode')->willReturn($this->existing(3, 'SEN'));
		$this->mapper->expects($this->never())->method('insert');
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->create('u', 'SEN', 'Senioren zwei');
	}

	public function testDoppeltesKuerzelBeimAendernWirdAbgelehnt(): void {
		$this->mapper->method('find')->willReturn($this->existing(5, 'AJU', 'A-Junioren'));
		$this->mapper->method('findByCode')->willReturn($this->existing(3, 'SEN'));
		$this->mapper->expects($this->never())->method('update');
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->update(5, 'u', 'SEN', 'A-Junioren', true);
	}

	public function testEigenesKuerzelBeimAendernIstErlaubt(): void {
		$own = $this->existing(3, 'SEN');
		$this->mapper->method('find')->willReturn($own);
		$this->mapper->method('findByCode')->willReturn($own);
		$cc = $this->service()->update(3, 'u', 'SEN', 'Erste Mannschaft', false);
		$this->assertSame('Erste Mannschaft', $cc->getName());
		$this->assertFalse($cc->isActive());
	}

	public function testLoeschenMitVerwendungWirdAbgelehnt(): void {
		$this->mapper->method('find')->willReturn($this->existing(3, 'SEN'));
		$this->allocations->method('countByCostCenter')->with(3)->willReturn(1);
		$this->mapper->expects($this->never())->method('delete');
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->delete(3, 'u');
	}

	public function testLoeschenOhneVerwendungLoescht(): void {
		$cc = $this->existing(3, 'SEN');
		$this->mapper->method('find')->willReturn($cc);
		$this->allocations->method('countByCostCenter')->willReturn(0);
		$this->mapper->expects($this->once())->method('delete')->with($cc);
		$this->service()->delete(3, 'u');
	}

	public function testAnlegenBeiAusgeschaltetemModulWirdAbgelehnt(): void {
		$this->enabled = false;
		$this->mapper->expects($this->never())->method('insert');
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->create('u', 'SEN', 'Senioren');
	}

	public function testAendernBeiAusgeschaltetemModulWirdAbgelehnt(): void {
		$this->enabled = false;
		$this->mapper->method('find')->willReturn($this->existing(3, 'SEN'));
		$this->mapper->expects($this->never())->method('update');
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->update(3, 'u', 'SEN', 'Senioren', true);
	}

	public function testLoeschenBeiAusgeschaltetemModulWirdAbgelehnt(): void {
		$this->enabled = false;
		$this->mapper->method('find')->willReturn($this->existing(3, 'SEN'));
		$this->mapper->expects($this->never())->method('delete');
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->delete(3, 'u');
	}

	public function testLesenBleibtBeiAusgeschaltetemModulMoeglich(): void {
		$this->enabled = false;
		$this->mapper->method('findAll')->willReturn([$this->existing(3, 'SEN')]);
		$this->assertCount(1, $this->service()->all('u'));
	}
}
