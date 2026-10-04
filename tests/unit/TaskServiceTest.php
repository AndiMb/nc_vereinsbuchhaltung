<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\Task;
use OCA\Vereinsbuchhaltung\Db\TaskMapper;
use OCA\Vereinsbuchhaltung\Service\TaskService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Auflösung persistierter Hinweise (Issue #117): ein Ereignis-Hinweis (NC-Konto
 * gelöscht, Mitglieder-Übernahme) steht nicht für immer in der Aufgabenliste
 * und wird nicht quittiert – siehe Klassendoc von {@see TaskService}.
 */
class TaskServiceTest extends TestCase {

	private const NOW = '2026-10-04 12:00:00';

	private TaskMapper&MockObject $mapper;
	private MemberMapper&MockObject $members;

	protected function setUp(): void {
		$this->mapper = $this->createMock(TaskMapper::class);
		$this->members = $this->createMock(MemberMapper::class);
	}

	/**
	 * @param list<Member> $members
	 * @return array<int,Member> wie MemberMapper::findAllById()
	 */
	private static function byId(array $members): array {
		$map = [];
		foreach ($members as $member) {
			$map[(int)$member->getId()] = $member;
		}
		return $map;
	}

	private function service(): TaskService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): \DateTime => new \DateTime(self::NOW));
		return new TaskService($this->mapper, $this->members, $time);
	}

	private function task(int $id, string $createdAt, ?string $objectType = 'member', ?int $objectId = null): Task {
		$task = new Task();
		$task->setId($id);
		$task->setSeverity(Task::SEVERITY_HINT);
		$task->setMessage('Hinweis ' . $id);
		$task->setObjectType($objectType);
		$task->setObjectId($objectId);
		$task->setCreatedAt($createdAt);
		return $task;
	}

	private function member(int $id, ?string $ncUserId = null, ?string $redactedAt = null): Member {
		$member = new Member();
		$member->setId($id);
		$member->setMemberType(Member::TYPE_PERSON);
		$member->setLastName('Beispiel');
		$member->setJoinedAt('2020-01-01');
		$member->setNcUserId($ncUserId);
		$member->setRedactedAt($redactedAt);
		return $member;
	}

	/** @param Task[] $tasks @return list<int> */
	private function ids(array $tasks): array {
		return array_map(static fn (Task $t): int => (int)$t->getId(), $tasks);
	}

	public function testFrischerHinweisZuEinemMitgliedStehtInDerListe(): void {
		$this->mapper->method('findAll')->willReturn([$this->task(1, '2026-10-01 08:00:00', 'member', 7)]);
		$this->members->method('findAllById')->willReturn(self::byId([$this->member(7)]));

		$this->assertSame([1], $this->ids($this->service()->findCurrent()));
	}

	public function testHinweisOhneMitgliedsBezugBleibtBisZurAltersgrenze(): void {
		// „N Mitglieder übernommen“ aus der Migration: kein einzelnes Mitglied, nur das Alter zählt.
		$this->mapper->method('findAll')->willReturn([
			$this->task(1, '2026-09-20 08:00:00'),
			$this->task(2, '2026-09-04 12:00:01'), // knapp innerhalb von 30 Tagen
		]);
		$this->members->expects($this->never())->method('findAllById');

		$this->assertSame([1, 2], $this->ids($this->service()->findCurrent()));
	}

	public function testHinweisVerschwindetNachDerAltersgrenze(): void {
		$this->mapper->method('findAll')->willReturn([
			$this->task(1, '2026-10-03 08:00:00'),
			$this->task(2, '2026-09-04 11:59:59'), // gerade 30 Tage und eine Sekunde alt
			$this->task(3, '2025-01-01 00:00:00'),
		]);

		$this->assertSame([1], $this->ids($this->service()->findCurrent()));
	}

	public function testHinweisVerschwindetWennDasMitgliedWiederEinKontoHat(): void {
		// „NC-Konto gelöscht, Adresse übernommen“: ein neu verknüpftes Konto bereinigt die Lage.
		$this->mapper->method('findAll')->willReturn([$this->task(1, '2026-10-01 08:00:00', 'member', 7)]);
		$this->members->method('findAllById')->willReturn(self::byId([$this->member(7, 'neuer-nutzer')]));

		$this->assertSame([], $this->service()->findCurrent());
	}

	public function testHinweisVerschwindetWennDasMitgliedGeloeschtWurde(): void {
		$this->mapper->method('findAll')->willReturn([$this->task(1, '2026-10-01 08:00:00', 'member', 7)]);
		$this->members->method('findAllById')->willReturn(self::byId([]));

		$this->assertSame([], $this->service()->findCurrent());
	}

	public function testHinweisVerschwindetWennDasMitgliedAnonymisiertWurde(): void {
		$this->mapper->method('findAll')->willReturn([$this->task(1, '2026-10-01 08:00:00', 'member', 7)]);
		$this->members->method('findAllById')->willReturn(self::byId([$this->member(7, null, '2026-10-02 09:00:00')]));

		$this->assertSame([], $this->service()->findCurrent());
	}

	public function testMitgliederWerdenEinmalGeladenNichtJeHinweis(): void {
		$tasks = [];
		$members = [];
		for ($i = 1; $i <= 25; $i++) {
			$tasks[] = $this->task($i, '2026-10-01 08:00:00', 'member', $i);
			$members[] = $this->member($i);
		}
		$this->mapper->method('findAll')->willReturn($tasks);
		$this->members->expects($this->once())->method('findAllById')->willReturn(self::byId($members));
		$this->members->expects($this->never())->method('find');

		$this->assertCount(25, $this->service()->findCurrent());
	}

	public function testHinweisZuAnderemObjektTypBrauchtKeinMitglied(): void {
		$this->mapper->method('findAll')->willReturn([$this->task(1, '2026-10-01 08:00:00', 'mandate', 3)]);
		$this->members->expects($this->never())->method('findAllById');

		$this->assertSame([1], $this->ids($this->service()->findCurrent()));
	}
}
