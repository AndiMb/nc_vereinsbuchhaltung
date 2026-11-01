<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\ReimbursementEvent;
use OCA\Vereinsbuchhaltung\Db\ReimbursementEventMapper;
use OCA\Vereinsbuchhaltung\Service\ReimbursementEventLog;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Hash-Kette der Historie eines Auslagenantrags (docs/reimbursement-design.md §5).
 */
class ReimbursementEventLogTest extends TestCase {

	/** @var list<ReimbursementEvent> */
	private array $stored = [];
	private ReimbursementEventMapper&MockObject $mapper;

	protected function setUp(): void {
		$this->stored = [];
		$this->mapper = $this->createMock(ReimbursementEventMapper::class);
		$this->mapper->method('findLast')->willReturnCallback(fn (int $id): ?ReimbursementEvent => $this->lastOf($id));
		$this->mapper->method('insert')->willReturnCallback(function (ReimbursementEvent $event): ReimbursementEvent {
			$event->setId(count($this->stored) + 1);
			$this->stored[] = $event;
			return $event;
		});
		$this->mapper->method('findByReimbursement')->willReturnCallback(
			fn (int $id): array => array_values(array_filter($this->stored, static fn (ReimbursementEvent $e): bool => $e->getReimbursementId() === $id)),
		);
		$this->mapper->method('approverUids')->willReturn(['alice']);
	}

	private function lastOf(int $id): ?ReimbursementEvent {
		$matching = array_values(array_filter($this->stored, static fn (ReimbursementEvent $e): bool => $e->getReimbursementId() === $id));
		return $matching === [] ? null : $matching[count($matching) - 1];
	}

	private function log(): ReimbursementEventLog {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-01 10:00:00'));
		return new ReimbursementEventLog($this->mapper, $time);
	}

	public function testErstesEreignisHatFolgenummerEinsUndKeinenVorgaenger(): void {
		$event = $this->log()->append(7, ReimbursementEvent::ACTION_SUBMITTED, 'anna', ReimbursementEvent::ROLE_MEMBER, null, ['amountCents' => 1250]);

		$this->assertSame(1, $event->getSeq());
		$this->assertNull($event->getPrevHash());
		$this->assertSame(64, strlen($event->getHash()));
		$this->assertSame(['amountCents' => 1250], $event->decodedData());
	}

	public function testJedesEreignisTraegtDenHashSeinesVorgaengers(): void {
		$log = $this->log();
		$first = $log->append(7, ReimbursementEvent::ACTION_SUBMITTED, 'anna', ReimbursementEvent::ROLE_MEMBER);
		$second = $log->append(7, ReimbursementEvent::ACTION_APPROVED, 'berta', ReimbursementEvent::ROLE_STAFF);

		$this->assertSame(2, $second->getSeq());
		$this->assertSame($first->getHash(), $second->getPrevHash());
		$this->assertNotSame($first->getHash(), $second->getHash());
	}

	public function testFolgenummernZaehlenJeAntrag(): void {
		$log = $this->log();
		$log->append(7, ReimbursementEvent::ACTION_SUBMITTED, 'anna', ReimbursementEvent::ROLE_MEMBER);
		$other = $log->append(8, ReimbursementEvent::ACTION_SUBMITTED, 'anna', ReimbursementEvent::ROLE_MEMBER);

		$this->assertSame(1, $other->getSeq());
		$this->assertNull($other->getPrevHash());
	}

	public function testUnveraenderteHistorieIstGueltig(): void {
		$log = $this->log();
		$log->append(7, ReimbursementEvent::ACTION_SUBMITTED, 'anna', ReimbursementEvent::ROLE_MEMBER);
		$log->append(7, ReimbursementEvent::ACTION_APPROVED, 'berta', ReimbursementEvent::ROLE_STAFF, null, ['count' => 1]);
		$log->append(7, ReimbursementEvent::ACTION_REJECTED, 'carla', ReimbursementEvent::ROLE_STAFF, 'Beleg unleserlich');

		$this->assertTrue(ReimbursementEventLog::verify($log->history(7)));
	}

	public function testNachtraeglichGeaendertesFeldBrichtDieKette(): void {
		$log = $this->log();
		$log->append(7, ReimbursementEvent::ACTION_SUBMITTED, 'anna', ReimbursementEvent::ROLE_MEMBER);
		$log->append(7, ReimbursementEvent::ACTION_REJECTED, 'berta', ReimbursementEvent::ROLE_STAFF, 'Beleg unleserlich');

		$history = $log->history(7);
		$history[1]->setComment('Alles in Ordnung');

		$this->assertFalse(ReimbursementEventLog::verify($history));
	}

	public function testGeaenderterAkteurBrichtDieKette(): void {
		$log = $this->log();
		$log->append(7, ReimbursementEvent::ACTION_SUBMITTED, 'anna', ReimbursementEvent::ROLE_MEMBER);
		$log->append(7, ReimbursementEvent::ACTION_APPROVED, 'berta', ReimbursementEvent::ROLE_STAFF);

		$history = $log->history(7);
		$history[1]->setActorUid('carla');

		$this->assertFalse(ReimbursementEventLog::verify($history));
	}

	public function testFehlendesEreignisInDerMitteBrichtDieKette(): void {
		$log = $this->log();
		$log->append(7, ReimbursementEvent::ACTION_SUBMITTED, 'anna', ReimbursementEvent::ROLE_MEMBER);
		$log->append(7, ReimbursementEvent::ACTION_APPROVED, 'berta', ReimbursementEvent::ROLE_STAFF);
		$log->append(7, ReimbursementEvent::ACTION_APPROVED, 'carla', ReimbursementEvent::ROLE_STAFF);

		$history = $log->history(7);
		unset($history[1]);

		$this->assertFalse(ReimbursementEventLog::verify(array_values($history)));
	}

	public function testMitgliederSichtOhneAkteur(): void {
		$event = $this->log()->append(7, ReimbursementEvent::ACTION_APPROVED, 'berta', ReimbursementEvent::ROLE_STAFF, null, ['count' => 1, 'required' => 2]);

		$member = $event->toArray(false);
		$this->assertArrayNotHasKey('actorUid', $member);
		$this->assertArrayNotHasKey('hash', $member);
		$this->assertSame(['count' => 1, 'required' => 2], $member['data']);

		$staff = $event->toArray(true);
		$this->assertSame('berta', $staff['actorUid']);
	}

	public function testDieHistorieIstAppendOnly(): void {
		$mapper = new ReimbursementEventMapper($this->createMock(\OCP\IDBConnection::class));
		$event = new ReimbursementEvent();

		$this->expectException(\LogicException::class);
		$mapper->update($event);
	}

	public function testLoeschenIstGesperrt(): void {
		$mapper = new ReimbursementEventMapper($this->createMock(\OCP\IDBConnection::class));

		$this->expectException(\LogicException::class);
		$mapper->delete(new ReimbursementEvent());
	}
}
