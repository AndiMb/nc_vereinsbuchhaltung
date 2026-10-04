<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Service\TaskTargetResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Sprungziel der Aufgabenliste (Issue #99): `memberId` je Aufgabe, damit das
 * Aufgaben-Flyout in die richtige Mitglieder-Akte springen kann - siehe
 * Klassendoc von {@see TaskTargetResolver}.
 */
class TaskTargetResolverTest extends TestCase {

	private MemberMapper&MockObject $members;
	private MandateMapper&MockObject $mandates;
	private AssignmentMapper&MockObject $assignments;
	private OpenItemMapper&MockObject $openItems;
	private TaskTargetResolver $resolver;

	protected function setUp(): void {
		$this->members = $this->createMock(MemberMapper::class);
		$this->mandates = $this->createMock(MandateMapper::class);
		$this->assignments = $this->createMock(AssignmentMapper::class);
		$this->openItems = $this->createMock(OpenItemMapper::class);
		$this->resolver = new TaskTargetResolver($this->members, $this->mandates, $this->assignments, $this->openItems);
	}

	private function task(?string $type, ?int $id): array {
		return ['severity' => 'handlungsbedarf', 'message' => 'x', 'objectType' => $type, 'objectId' => $id];
	}

	public function testMemberAufgabeVerweistAufDasMitgliedSelbst(): void {
		$this->members->method('findOrNull')->with(7)->willReturn(new Member());

		$out = $this->resolver->withMemberIds([$this->task('member', 7)]);

		$this->assertSame(7, $out[0]['memberId']);
	}

	public function testGeloeschtesMitgliedHatKeinZiel(): void {
		$this->members->method('findOrNull')->willReturn(null);

		$out = $this->resolver->withMemberIds([$this->task('member', 7)]);

		$this->assertNull($out[0]['memberId']);
	}

	public function testMandatZuweisungUndForderungLoesenAufDasMitgliedAuf(): void {
		$mandate = new Mandate();
		$mandate->setMemberId(11);
		$this->mandates->method('findOrNull')->with(3)->willReturn($mandate);
		$assignment = new Assignment();
		$assignment->setMemberId(12);
		$this->assignments->method('find')->with(4)->willReturn($assignment);
		$claim = new OpenItem();
		$claim->setMemberId(13);
		$this->openItems->method('find')->with(5)->willReturn($claim);

		$out = $this->resolver->withMemberIds([
			$this->task('mandate', 3),
			$this->task('assignment', 4),
			$this->task('claim', 5),
		]);

		$this->assertSame([11, 12, 13], array_column($out, 'memberId'));
	}

	public function testGeloeschtesObjektHatKeinZiel(): void {
		$this->mandates->method('findOrNull')->willReturn(null);
		$this->assignments->method('find')->willThrowException(new DoesNotExistException(''));
		$this->openItems->method('find')->willThrowException(new DoesNotExistException(''));

		$out = $this->resolver->withMemberIds([
			$this->task('mandate', 3),
			$this->task('assignment', 4),
			$this->task('claim', 5),
		]);

		$this->assertSame([null, null, null], array_column($out, 'memberId'));
	}

	/** Aggregierte Einzug-Aufgaben, Läufe und unbekannte Typen betreffen kein Mitglied. */
	public function testAufgabenOhneMitgliedBleibenOhneZiel(): void {
		$this->members->expects($this->never())->method('findOrNull');
		$this->mandates->expects($this->never())->method('findOrNull');
		$this->assignments->expects($this->never())->method('find');
		$this->openItems->expects($this->never())->method('find');

		$out = $this->resolver->withMemberIds([
			$this->task(null, null),
			$this->task('debit_batch', 9),
			$this->task('unbekannt', 9),
			$this->task('member', null),
		]);

		$this->assertSame([null, null, null, null], array_column($out, 'memberId'));
	}

	public function testBestehendeFelderBleibenUnveraendertUndGleicheObjekteWerdenEinmalGelesen(): void {
		$mandate = new Mandate();
		$mandate->setMemberId(11);
		$this->mandates->expects($this->once())->method('findOrNull')->with(3)->willReturn($mandate);

		$in = [$this->task('mandate', 3) + ['id' => 'a'], $this->task('mandate', 3) + ['id' => 'b']];
		$out = $this->resolver->withMemberIds($in);

		$this->assertSame('a', $out[0]['id']);
		$this->assertSame('b', $out[1]['id']);
		$this->assertSame('handlungsbedarf', $out[0]['severity']);
		$this->assertSame([11, 11], array_column($out, 'memberId'));
	}
}
