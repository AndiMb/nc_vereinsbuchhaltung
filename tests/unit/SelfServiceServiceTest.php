<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Service\SelfServiceService;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Self-Service-Zugang (Spec §3.4): zwei unabhängige Bedingungen, die BEIDE
 * gelten müssen – `self_service_enabled` und die Kontoverknüpfung
 * (nc_user_id). Die PermissionMiddleware verlässt sich exakt auf diese
 * Klasse (siehe PermissionMiddlewareSelfServiceTest), hier wird ihr eigenes
 * Verhalten isoliert geprüft.
 */
class SelfServiceServiceTest extends TestCase {

	private const UID = 'kassenwart';

	private IAppConfig&MockObject $config;
	private MemberMapper&MockObject $memberMapper;
	private IUserSession&MockObject $userSession;

	protected function setUp(): void {
		$this->config = $this->createMock(IAppConfig::class);
		$this->memberMapper = $this->createMock(MemberMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
	}

	private function service(): SelfServiceService {
		return new SelfServiceService($this->config, $this->memberMapper, $this->userSession);
	}

	private function loggedInAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	/** Ein Mitglied, dessen Verknüpfung mit UID bestätigt ist (#295). */
	private function linkedMember(int $id): Member {
		$member = new Member();
		$member->setId($id);
		$member->setNcUserId(self::UID);
		$member->setNcLinkConfirmedAt('2026-01-01 00:00:00');
		return $member;
	}

	public function testIsEnabledLiestAppEinstellung(): void {
		$this->config->method('getValueString')
			->with(Application::APP_ID, 'self_service_enabled', '0')
			->willReturn('1');

		$this->assertTrue($this->service()->isEnabled());
	}

	public function testIsEnabledDefaultAusOhneEinstellung(): void {
		$this->config->method('getValueString')->willReturn('0');

		$this->assertFalse($this->service()->isEnabled());
	}

	public function testCanSwitchToTransferBrauchtBeideSchalter(): void {
		$this->config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => $key === 'self_service_enabled' || $key === 'self_service_transfer_switch_enabled' ? '1' : $default,
		);

		$this->assertTrue($this->service()->canSwitchToTransfer());
	}

	public function testCanSwitchToTransferIstOhneSelfServiceAusAuchWennDerZusatzschalterAnIst(): void {
		$this->config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => $key === 'self_service_transfer_switch_enabled' ? '1' : $default,
		);

		$this->assertFalse($this->service()->canSwitchToTransfer());
	}

	public function testCanSwitchToTransferIstStandardmaessigAus(): void {
		$this->config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => $key === 'self_service_enabled' ? '1' : $default,
		);

		$this->assertFalse($this->service()->canSwitchToTransfer());
	}

	public function testOhneAngemeldetenNutzerKeinMitglied(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertNull($this->service()->currentMember());
		$this->memberMapper->expects($this->never())->method('findByNcUserId');
	}

	public function testCurrentMemberSuchtUeberNcUserId(): void {
		$this->loggedInAs(self::UID);
		$member = $this->linkedMember(3);
		$this->memberMapper->method('findByNcUserId')->with(self::UID)->willReturn($member);

		$this->assertSame($member, $this->service()->currentMember());
	}

	/**
	 * #295: eine Verknüpfung, die das Mitglied noch nicht über den Einmal-Link
	 * an seine Mailadresse bestätigt hat, öffnet keinen Self-Service. Sonst
	 * könnte ein fremdes Konto mit der Mailadresse des Mitglieds im Profil
	 * dessen Daten lesen, sobald die Verwaltung den Vorschlag übernimmt.
	 */
	public function testAusstehendeVerknuepfungIstKeinMitglied(): void {
		$this->loggedInAs(self::UID);
		$member = new Member();
		$member->setId(3);
		$member->setNcUserId(self::UID);
		$this->memberMapper->method('findByNcUserId')->with(self::UID)->willReturn($member);
		$this->config->method('getValueString')->willReturn('1');

		$this->assertNull($this->service()->currentMember());
		$this->assertFalse($this->service()->hasAccess());
	}

	public function testHasAccessAktiviertUndVerknuepft(): void {
		$this->loggedInAs(self::UID);
		$member = $this->linkedMember(3);
		$this->memberMapper->method('findByNcUserId')->willReturn($member);
		$this->config->method('getValueString')->willReturn('1');

		$this->assertTrue($this->service()->hasAccess());
	}

	/**
	 * Eigener Mock statt Umkonfigurieren des bestehenden: ein zweites
	 * ->method('getValueString')->willReturn() auf demselben Mock würde die
	 * erste Konfiguration nicht ersetzen, sondern PHPUnit matcht weiter die
	 * zuerst registrierte Rückgabe - der Fehler fiele hier nicht auf, weil
	 * beide Werte zufällig zum falschen Ergebnis "Zugriff" führen könnten.
	 */
	public function testHasAccessDeaktiviertTrotzVerknuepfung(): void {
		$this->loggedInAs(self::UID);
		$member = $this->linkedMember(3);
		$this->memberMapper->method('findByNcUserId')->willReturn($member);
		$this->config->method('getValueString')->willReturn('0');

		$this->assertFalse($this->service()->hasAccess());
	}

	public function testHasAccessOhneVerknuepfungImmerFalse(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->config->method('getValueString')->willReturn('1');

		$this->assertFalse($this->service()->hasAccess());
	}

	public function testDescribeCurrentOhneEinstellungFragtMitgliedNichtAb(): void {
		$this->config->method('getValueString')->willReturn('0');
		$this->memberMapper->expects($this->never())->method('findByNcUserId');

		$this->assertSame(['available' => false, 'memberId' => null], $this->service()->describeCurrent());
	}

	public function testDescribeCurrentMitVerknuepfung(): void {
		$this->loggedInAs(self::UID);
		$member = $this->linkedMember(42);
		$this->memberMapper->method('findByNcUserId')->willReturn($member);
		$this->config->method('getValueString')->willReturn('1');

		$this->assertSame(['available' => true, 'memberId' => 42], $this->service()->describeCurrent());
	}

	/** #296: ein anonymisiertes Mitglied hat keinen Self-Service mehr – sonst schriebe es Personendaten zurück. */
	public function testAnonymisiertesMitgliedHatKeinenSelfService(): void {
		$this->loggedInAs(self::UID);
		$member = new Member();
		$member->setId(3);
		$member->setNcUserId(self::UID);
		$member->setNcLinkConfirmedAt('2026-01-01 00:00:00');
		$member->setRedactedAt('2026-01-01 00:00:00');
		$this->memberMapper->method('findByNcUserId')->willReturn($member);
		$this->config->method('getValueString')->willReturn('1');

		$this->assertNull($this->service()->currentMember());
		$this->assertFalse($this->service()->hasAccess());
	}
}
