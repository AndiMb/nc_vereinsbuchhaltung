<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\NextcloudAccountOptionsService;
use OCP\Group\ISubAdmin;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Was das Formular „Nextcloud-Konto anlegen“ anbietet: Rechte wie in der
 * Benutzerverwaltung von Nextcloud (Administrator, delegierter Administrator,
 * Gruppen-Administrator) und die Auswahlen daraus.
 */
class NextcloudAccountOptionsServiceTest extends TestCase {

	private IUserSession&MockObject $session;
	private IGroupManager&MockObject $groups;
	private ISubAdmin&MockObject $subAdmin;
	private IFactory&MockObject $l10n;
	/** @var array<string,string> */
	private array $config = [];

	protected function setUp(): void {
		$this->session = $this->createMock(IUserSession::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->subAdmin = $this->createMock(ISubAdmin::class);
		$this->l10n = $this->createMock(IFactory::class);
		$this->l10n->method('getLanguages')->willReturn([
			'commonLanguages' => [['code' => 'de', 'name' => 'Deutsch (Persönlich: Du)'], ['code' => 'en', 'name' => 'English']],
			'otherLanguages' => [['code' => 'fr', 'name' => 'Français']],
		]);
		$this->config = [];
	}

	private function service(): NextcloudAccountOptionsService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->config[$app . '.' . $key] ?? $default);
		// Wie IAppConfig::getValueBool: gespeichert wird ein String, '1', 'true', 'yes' und 'on' gelten als wahr
		// (so bleibt auch der alte Wert 'yes' lesbar, den Nextcloud früher dort ablegte).
		$config->method('getValueBool')->willReturnCallback(function (string $app, string $key, bool $default = false): bool {
			$stored = $this->config[$app . '.' . $key] ?? null;
			return $stored === null ? $default : in_array(strtolower($stored), ['1', 'true', 'yes', 'on'], true);
		});
		return new NextcloudAccountOptionsService($this->session, $this->groups, $this->subAdmin, $config, $this->l10n);
	}

	private function actor(string $uid, bool $admin, bool $delegated, bool $subAdmin): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
		$this->groups->method('isAdmin')->willReturn($admin);
		$this->groups->method('isDelegatedAdmin')->willReturn($delegated);
		$this->subAdmin->method('isSubAdmin')->willReturn($subAdmin);
		return $user;
	}

	private function group(string $id, string $name): IGroup&MockObject {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($id);
		$group->method('getDisplayName')->willReturn($name);
		return $group;
	}

	public function testAdministratorSiehtAlleGruppen(): void {
		$this->actor('root', true, false, false);
		$this->groups->method('search')->willReturn([$this->group('chor', 'Chor'), $this->group('admin', 'admin')]);

		$options = $this->service()->forCurrentUser();

		$this->assertTrue($options['canCreate']);
		$this->assertFalse($options['groupRequired']);
		$this->assertSame(['admin', 'chor'], array_column($options['groups'], 'id'));
	}

	public function testDelegierterAdministratorBekommtDieGruppeAdminNicht(): void {
		$this->actor('deleg', false, true, false);
		$this->groups->method('search')->willReturn([$this->group('chor', 'Chor'), $this->group('admin', 'admin')]);

		$options = $this->service()->forCurrentUser();

		$this->assertTrue($options['canCreate']);
		$this->assertSame(['chor'], array_column($options['groups'], 'id'));
	}

	public function testGruppenAdministratorBekommtNurSeineGruppenUndMussEineWaehlen(): void {
		$user = $this->actor('sub', false, false, true);
		$this->subAdmin->method('getSubAdminsGroups')->with($user)->willReturn([$this->group('alt', 'Alt')]);
		$this->groups->expects($this->never())->method('search');

		$options = $this->service()->forCurrentUser();

		$this->assertTrue($options['canCreate']);
		$this->assertTrue($options['groupRequired']);
		$this->assertSame(['alt'], array_column($options['groups'], 'id'));
	}

	public function testEinfacheNutzerDuerfenKeineKontenAnlegen(): void {
		$this->actor('bob', false, false, false);

		$options = $this->service()->forCurrentUser();

		$this->assertFalse($options['canCreate']);
		$this->assertSame([], $options['groups']);
	}

	public function testSprachenUndInstanzEinstellungenKommenAusNextcloud(): void {
		$this->actor('root', true, false, false);
		$this->groups->method('search')->willReturn([]);
		$this->config = [
			'files.quota_preset' => '2 GB, 20 GB',
			'files.default_quota' => '5 GB',
			'core.newUser.requireEmail' => '1',
			'core.newUser.generateUserID' => '1',
			'core.newUser.sendEmail' => '0',
		];

		$options = $this->service()->forCurrentUser();

		$this->assertSame(['de', 'en', 'fr'], array_column($options['languages'], 'code'));
		$this->assertSame(['2 GB', '20 GB'], $options['quotaPresets']);
		$this->assertSame('5 GB', $options['defaultQuota']);
		$this->assertTrue($options['requireEmail']);
		$this->assertTrue($options['generateUserId']);
		$this->assertFalse($options['sendInvitation']);
	}

	/** Ohne gesetzte Werte gelten die Vorgaben der Nextcloud-Benutzerverwaltung: keine Pflicht-E-Mail, keine erzeugte ID, Einladung an. */
	public function testNewUserVorgabenOhneGespeicherteWerte(): void {
		$this->actor('root', true, false, false);
		$this->groups->method('search')->willReturn([]);

		$options = $this->service()->forCurrentUser();

		$this->assertFalse($options['requireEmail']);
		$this->assertFalse($options['generateUserId']);
		$this->assertTrue($options['sendInvitation']);
	}

	/** Ältere Nextcloud-Stände legten 'yes'/'no' ab; getValueBool liest beides. */
	public function testAlteJaNeinWerteBleibenLesbar(): void {
		$this->actor('root', true, false, false);
		$this->groups->method('search')->willReturn([]);
		$this->config = [
			'core.newUser.requireEmail' => 'yes',
			'core.newUser.generateUserID' => 'no',
			'core.newUser.sendEmail' => 'no',
		];

		$options = $this->service()->forCurrentUser();

		$this->assertTrue($options['requireEmail']);
		$this->assertFalse($options['generateUserId']);
		$this->assertFalse($options['sendInvitation']);
	}
}
