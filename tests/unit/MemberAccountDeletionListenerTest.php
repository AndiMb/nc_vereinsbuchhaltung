<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\Task;
use OCA\Vereinsbuchhaltung\Listener\MemberAccountDeletionListener;
use OCA\Vereinsbuchhaltung\Service\TaskService;
use OCP\IL10N;
use OCP\IUser;
use OCP\User\Events\BeforeUserDeletedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Hinweis „Nextcloud-Konto gelöscht, Adresse übernommen“ (Spec §3.1, Issue
 * #117): wird gespeichert, deshalb darf der Mitgliedsname nicht als
 * t()-Variable im Text landen (t() maskiert HTML-Zeichen in Variablen).
 */
class MemberAccountDeletionListenerTest extends TestCase {

	private MemberMapper&MockObject $members;
	private TaskService&MockObject $tasks;

	protected function setUp(): void {
		$this->members = $this->createMock(MemberMapper::class);
		$this->tasks = $this->createMock(TaskService::class);
	}

	private function listener(): MemberAccountDeletionListener {
		// Wie die echte Übersetzung HTML-Zeichen in Variablen maskiert.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, array_map(static fn ($p) => is_string($p) ? htmlspecialchars($p) : $p, $params)));
		return new MemberAccountDeletionListener($this->members, $this->tasks, $l10n);
	}

	private function event(?string $email): BeforeUserDeletedEvent {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('mmueller');
		$user->method('getEMailAddress')->willReturn($email);
		return new BeforeUserDeletedEvent($user);
	}

	private function member(?string $email = null): Member {
		$member = new Member();
		$member->setId(7);
		$member->setMemberType(Member::TYPE_ORGANIZATION);
		$member->setOrganizationName('Müller & Söhne');
		$member->setJoinedAt('2020-01-01');
		$member->setNcUserId('mmueller');
		$member->setEmail($email);
		return $member;
	}

	public function testGerettetteAdresseErzeugtEinenHinweisMitUnmaskiertemNamen(): void {
		$member = $this->member();
		$this->members->method('findByNcUserId')->with('mmueller')->willReturn($member);
		$this->members->expects($this->once())->method('update');
		$this->tasks->expects($this->once())->method('create')->with(
			Task::SEVERITY_HINT,
			$this->callback(static fn (string $message): bool => str_starts_with($message, 'Müller & Söhne: ') && !str_contains($message, '&amp;')),
			'member',
			7,
		);

		$this->listener()->handle($this->event('info@mueller.example'));

		$this->assertSame('info@mueller.example', $member->getEmail());
		$this->assertNull($member->getNcUserId());
	}

	public function testOhneGerettetteAdresseKeinHinweis(): void {
		// Das Mitglied hat schon eine eigene Adresse: die Verknüpfung wird gelöst, mehr nicht.
		$this->members->method('findByNcUserId')->willReturn($this->member('eigene@mueller.example'));
		$this->tasks->expects($this->never())->method('create');

		$this->listener()->handle($this->event('info@mueller.example'));
	}
}
