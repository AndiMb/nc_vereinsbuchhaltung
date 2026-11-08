<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\CostCenter;
use OCA\Vereinsbuchhaltung\Db\CostCenterMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Exception\PeriodClosedException;
use OCA\Vereinsbuchhaltung\Service\AccountEvaluationLock;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\CostCenterService;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * #300: Zuordnen und Löschen von Auswertungsgruppen unterliegen derselben
 * Festschreibung wie das Ändern eines einzelnen Kontos – sonst verschöbe sich
 * die Kostenstellenübersicht eines abgeschlossenen Jahres nachträglich.
 */
class CostCenterServiceLockTest extends TestCase {

	private AccountMapper&MockObject $accounts;
	private CostCenterMapper&MockObject $costCenters;
	private AccountEvaluationLock&MockObject $lock;

	protected function setUp(): void {
		$this->accounts = $this->createMock(AccountMapper::class);
		$this->costCenters = $this->createMock(CostCenterMapper::class);
		$this->lock = $this->createMock(AccountEvaluationLock::class);
		$target = new CostCenter();
		$target->setId(7);
		$target->setCode('51');
		$target->setName('Jugend');
		$this->costCenters->method('find')->willReturn($target);
		// Konto 1 ist in einem abgeschlossenen Jahr bebucht, Konto 2 nicht.
		$this->lock->method('assertOpen')->willReturnCallback(static function (string $userId, int $accountId): void {
			if ($accountId === 1) {
				throw new PeriodClosedException('Das Geschäftsjahr 2012 ist abgeschlossen.');
			}
		});
	}

	private function service(): CostCenterService {
		$runner = $this->createMock(TransactionRunner::class);
		$runner->method('run')->willReturnCallback(static fn (callable $fn) => $fn());
		return new CostCenterService(
			$this->costCenters,
			$this->accounts,
			$runner,
			$this->createMock(AuditService::class),
			$this->createMock(IL10N::class),
			$this->lock,
		);
	}

	private function account(int $id, ?int $costCenterId = null): Account {
		$account = new Account();
		$account->setId($id);
		$account->setNumber('5000 0' . $id);
		$account->setName('Konto ' . $id);
		$account->setCostCenterId($costCenterId);
		return $account;
	}

	public function testZuordnenEinesKontosImAbgeschlossenenJahrWirdAbgelehnt(): void {
		$this->accounts->method('find')->willReturnCallback(fn (int $id): Account => $this->account($id));
		$this->accounts->expects($this->never())->method('update');

		$this->expectException(PeriodClosedException::class);
		$this->service()->assign('u', [1], 7);
	}

	public function testZuordnenImOffenenJahrUndOhneAenderungGehtDurch(): void {
		$this->accounts->method('find')->willReturnCallback(fn (int $id): Account => $id === 1 ? $this->account(1, 7) : $this->account($id));
		// Konto 1 hängt schon an der Gruppe: keine Änderung, also auch keine Sperre.
		$this->accounts->expects($this->once())->method('update')->willReturnArgument(0);

		$this->assertSame(2, $this->service()->assign('u', [1, 2], 7));
	}

	public function testLoeschenEinerGruppeMitGesperrtemKontoWirdAbgelehnt(): void {
		$this->accounts->method('findByCostCenter')->willReturn([$this->account(2, 7), $this->account(1, 7)]);
		$this->accounts->expects($this->never())->method('clearCostCenter');
		$this->costCenters->expects($this->never())->method('delete');

		$this->expectException(PeriodClosedException::class);
		$this->service()->delete(7, 'u');
	}

	public function testLoeschenEinerGruppeOhneGesperrteKontenGehtDurch(): void {
		$this->accounts->method('findByCostCenter')->willReturn([$this->account(2, 7)]);
		$this->accounts->expects($this->once())->method('clearCostCenter')->willReturn(1);
		$this->costCenters->expects($this->once())->method('delete');

		$this->service()->delete(7, 'u');
	}
}
