<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Permission;
use OCA\Vereinsbuchhaltung\Db\PermissionMapper;
use OCA\Vereinsbuchhaltung\Listener\PermissionPrincipalDeletedListener;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCP\EventDispatcher\Event;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\IGroup;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * #298: mit einem gelöschten Nutzer oder einer gelöschten Gruppe verschwindet
 * auch die Rolle – sonst erbte sie ein später angelegter Prinzipal mit
 * derselben ID.
 */
class PermissionPrincipalDeletedListenerTest extends TestCase {

	private PermissionMapper&MockObject $mapper;
	private AuditService&MockObject $audit;

	protected function setUp(): void {
		$this->mapper = $this->createMock(PermissionMapper::class);
		$this->audit = $this->createMock(AuditService::class);
	}

	private function listener(): PermissionPrincipalDeletedListener {
		return new PermissionPrincipalDeletedListener($this->mapper, $this->audit);
	}

	private function grant(int $id, string $role): Permission {
		$p = new Permission();
		$p->setId($id);
		$p->setRole($role);
		return $p;
	}

	public function testGeloeschterNutzerVerliertSeineRolleUndDasStehtImProtokoll(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('kassenwart');
		$this->mapper->expects($this->once())->method('deleteByPrincipal')->with('user', 'kassenwart')
			->willReturn([$this->grant(11, 'verwalter')]);
		$this->audit->expects($this->once())->method('log')->with(
			'Berechtigung entfernt',
			'permission',
			11,
			$this->callback(static fn (array $d): bool => $d['typ'] === 'user' && $d['wer'] === 'kassenwart' && $d['rolle'] === 'verwalter'),
		);

		$this->listener()->handle(new UserDeletedEvent($user));
	}

	public function testGeloeschteGruppeVerliertIhreRolle(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('vorstand');
		$this->mapper->expects($this->once())->method('deleteByPrincipal')->with('group', 'vorstand')
			->willReturn([$this->grant(12, 'revisor')]);
		$this->audit->expects($this->once())->method('log');

		$this->listener()->handle(new GroupDeletedEvent($group));
	}

	public function testOhneRolleKeinProtokolleintrag(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('niemand');
		$this->mapper->method('deleteByPrincipal')->willReturn([]);
		$this->audit->expects($this->never())->method('log');

		$this->listener()->handle(new UserDeletedEvent($user));
	}

	public function testAndereEreignisseIgnoriert(): void {
		$this->mapper->expects($this->never())->method('deleteByPrincipal');

		$this->listener()->handle(new Event());
	}
}
