<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCP\Group\ISubAdmin;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;

/**
 * Was das Formular „Nextcloud-Konto anlegen“ anbietet und darf: dieselben Auswahlen
 * wie die Benutzerverwaltung von Nextcloud (Gruppen, Speicherplatz, Sprache) und ob
 * die angemeldete Person überhaupt Konten anlegen darf. Die Entscheidung trifft
 * Nextcloud beim Anlegen noch einmal selbst; hier geht es darum, dem Verwalter ohne
 * diese Rechte kein Formular zu zeigen, das nur mit einem Fehler endet.
 *
 * Rechte wie in der Benutzerverwaltung: Administratoren dürfen alles, delegierte
 * Administratoren alles außer der Gruppe „admin“, Gruppen-Administratoren nur ihre
 * Gruppen (und müssen eine davon angeben).
 */
class NextcloudAccountOptionsService {

	private const GROUP_LIMIT = 500;

	public function __construct(
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private ISubAdmin $subAdmin,
		private IConfig $config,
		private IFactory $l10nFactory,
	) {
	}

	/**
	 * @return array{
	 *   canCreate:bool,
	 *   groupRequired:bool,
	 *   groups:list<array{id:string, displayName:string}>,
	 *   languages:list<array{code:string, name:string}>,
	 *   quotaPresets:list<string>,
	 *   defaultQuota:string,
	 *   requireEmail:bool,
	 *   generateUserId:bool,
	 *   sendInvitation:bool
	 * }
	 */
	public function forCurrentUser(): array {
		$user = $this->userSession->getUser();
		$uid = $user?->getUID() ?? '';
		$isAdmin = $uid !== '' && $this->groupManager->isAdmin($uid);
		$isDelegated = !$isAdmin && $uid !== '' && $this->groupManager->isDelegatedAdmin($uid);
		$isSubAdmin = !$isAdmin && !$isDelegated && $user !== null && $this->subAdmin->isSubAdmin($user);

		$groups = [];
		if ($isAdmin || $isDelegated) {
			foreach ($this->groupManager->search('', self::GROUP_LIMIT) as $group) {
				if ($isDelegated && $group->getGID() === 'admin') {
					continue;
				}
				$groups[] = $this->describe($group);
			}
		} elseif ($isSubAdmin && $user !== null) {
			foreach ($this->subAdmin->getSubAdminsGroups($user) as $group) {
				$groups[] = $this->describe($group);
			}
		}
		usort($groups, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));

		$presets = array_values(array_filter(array_map(
			'trim',
			explode(',', $this->config->getAppValue('files', 'quota_preset', '1 GB, 5 GB, 10 GB')),
		), static fn (string $preset): bool => $preset !== ''));

		return [
			'canCreate' => $isAdmin || $isDelegated || $isSubAdmin,
			'groupRequired' => $isSubAdmin,
			'groups' => $groups,
			'languages' => $this->languages(),
			'quotaPresets' => $presets,
			'defaultQuota' => $this->config->getAppValue('files', 'default_quota', 'none'),
			'requireEmail' => $this->config->getAppValue('core', 'newUser.requireEmail', 'no') === 'yes',
			'generateUserId' => $this->config->getAppValue('core', 'newUser.generateUserID', 'no') === 'yes',
			'sendInvitation' => $this->config->getAppValue('core', 'newUser.sendEmail', 'yes') === 'yes',
		];
	}

	/** @return array{id:string, displayName:string} */
	private function describe(IGroup $group): array {
		return ['id' => $group->getGID(), 'displayName' => $group->getDisplayName()];
	}

	/** @return list<array{code:string, name:string}> */
	private function languages(): array {
		$all = $this->l10nFactory->getLanguages();
		$languages = [];
		foreach (array_merge($all['commonLanguages'] ?? [], $all['otherLanguages'] ?? []) as $language) {
			if (isset($language['code'], $language['name'])) {
				$languages[] = ['code' => (string)$language['code'], 'name' => (string)$language['name']];
			}
		}
		return $languages;
	}
}
