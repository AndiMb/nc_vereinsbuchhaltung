<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\AccountHandoverService;
use OCP\Group\ISubAdmin;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\VerificationToken\IVerificationToken;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * „Neu angelegt" = nie angemeldet und von der angemeldeten Person in Nextcloud
 * verwaltbar (dieselbe Regel wie Nextclouds Benutzerverwaltung). Nur dann gilt
 * eine Verknüpfung sofort und gibt es den Passwort-Link in der Willkommensmail.
 */
class AccountHandoverServiceTest extends TestCase {

	private IUserManager&MockObject $users;
	private IUserSession&MockObject $session;
	private IGroupManager&MockObject $groups;
	private ISubAdmin&MockObject $subAdmin;
	private IVerificationToken&MockObject $tokens;
	/** @var array<string, IUser> */
	private array $accounts = [];

	protected function setUp(): void {
		$this->users = $this->createMock(IUserManager::class);
		$this->users->method('get')->willReturnCallback(fn (string $uid): ?IUser => $this->accounts[$uid] ?? null);
		$this->session = $this->createMock(IUserSession::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->subAdmin = $this->createMock(ISubAdmin::class);
		$this->tokens = $this->createMock(IVerificationToken::class);
	}

	private function account(string $uid, int $lastLogin, ?string $email = null): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getLastLogin')->willReturn($lastLogin);
		$user->method('getEMailAddress')->willReturn($email);
		return $this->accounts[$uid] = $user;
	}

	private function service(string $actorUid = 'admin', bool $isAdmin = true): AccountHandoverService {
		$this->session->method('getUser')->willReturn($this->account($actorUid, 1700000000));
		$this->groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $isAdmin && $uid === $actorUid);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $p): string => 'https://cloud.example.org/' . $route . '/' . $p['token'] . '/' . $p['userId'],
		);
		return new AccountHandoverService($this->users, $this->session, $this->groups, $this->subAdmin, $this->tokens, $urls);
	}

	public function testNieAngemeldetesKontoGiltFuerDieAdministrationAlsNeu(): void {
		$this->account('anna', 0);
		$this->assertTrue($this->service()->isFreshManagedAccount('anna'));
	}

	/** Wer sich über die Profil-Mail als Mitglied ausgibt (#295), musste sich dafür anmelden. */
	public function testAngemeldetesKontoGiltNichtAlsNeu(): void {
		$this->account('anna', 1700000000);
		$this->assertFalse($this->service()->isFreshManagedAccount('anna'));
	}

	public function testOhneVerwaltungsrechtGiltDasKontoNichtAlsNeu(): void {
		$this->account('anna', 0);
		$this->subAdmin->method('isUserAccessible')->willReturn(false);
		$this->assertFalse($this->service('kassenwart', false)->isFreshManagedAccount('anna'));
	}

	public function testGruppenadministrationMitZugriffAufDasKonto(): void {
		$this->account('anna', 0);
		$this->subAdmin->method('isUserAccessible')->willReturn(true);
		$this->assertTrue($this->service('gruppenadmin', false)->isFreshManagedAccount('anna'));
	}

	public function testUnbekanntesKonto(): void {
		$this->assertFalse($this->service()->isFreshManagedAccount('niemand'));
	}

	/** Derselbe Token wie Nextclouds eigene Einladung: `lostpassword`, an die Mailadresse des Kontos gebunden. */
	public function testPasswortLinkFuerEinNeuAngelegtesKonto(): void {
		$this->account('anna', 0, 'anna@example.org');
		$this->tokens->expects($this->once())->method('create')
			->with($this->accounts['anna'], 'lostpassword', 'anna@example.org')
			->willReturn('tok123');

		$this->assertSame('https://cloud.example.org/core.lost.resetform/tok123/anna', $this->service()->setPasswordUrl('anna'));
	}

	public function testKeinPasswortLinkFuerEinAngemeldetesKonto(): void {
		$this->account('anna', 1700000000, 'anna@example.org');
		$this->tokens->expects($this->never())->method('create');

		$this->assertNull($this->service()->setPasswordUrl('anna'));
	}
}
