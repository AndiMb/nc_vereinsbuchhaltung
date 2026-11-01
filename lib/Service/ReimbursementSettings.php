<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\PermissionMapper;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * Einstellungen der Auslagenerstattung und der Kreis der Freigeber.
 *
 * Freigeber sind alle aktiven Nutzer mit der Rolle Verwalter: per Nutzer oder
 * per Gruppe zugewiesen, dazu die Nextcloud-Administratoren (die sind immer
 * Verwalter, siehe {@see PermissionService}). Der Einreicher scheidet bei
 * seinem eigenen Antrag aus, deshalb verlangt die Einstellung mindestens N+1
 * Verwalter.
 */
class ReimbursementSettings {

	public const SETTING_ENABLED = 'reimbursement_enabled';
	public const SETTING_REQUIRED_APPROVALS = 'reimbursement_required_approvals';
	public const DEFAULT_REQUIRED_APPROVALS = 2;

	public function __construct(
		private IConfig $config,
		private PermissionMapper $permissions,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private IL10N $l10n,
	) {
	}

	public function isEnabled(): bool {
		return $this->config->getAppValue(Application::APP_ID, self::SETTING_ENABLED, '0') === '1';
	}

	public function requiredApprovals(): int {
		$value = (int)$this->config->getAppValue(Application::APP_ID, self::SETTING_REQUIRED_APPROVALS, (string)self::DEFAULT_REQUIRED_APPROVALS);
		return max(1, $value);
	}

	/**
	 * @return list<string> Nutzerkennungen aller aktiven Verwalter
	 */
	public function approverUids(): array {
		$uids = [];
		foreach ($this->permissions->findAll() as $permission) {
			if ($permission->getRole() !== PermissionService::ROLE_ADMIN) {
				continue;
			}
			if ($permission->getPrincipalType() === 'user') {
				$uids[] = $permission->getPrincipalId();
			} elseif ($permission->getPrincipalType() === 'group') {
				$group = $this->groupManager->get($permission->getPrincipalId());
				foreach ($group?->getUsers() ?? [] as $user) {
					$uids[] = $user->getUID();
				}
			}
		}
		foreach ($this->groupManager->get('admin')?->getUsers() ?? [] as $user) {
			$uids[] = $user->getUID();
		}

		$active = [];
		foreach (array_unique($uids) as $uid) {
			$user = $this->userManager->get($uid);
			if ($user !== null && $user->isEnabled()) {
				$active[] = $uid;
			}
		}
		return $active;
	}

	/**
	 * Die Freigeber für einen Antrag: alle Verwalter außer dem Einreicher.
	 *
	 * @return list<string>
	 */
	public function approversFor(string $submitterUid): array {
		return array_values(array_filter(
			$this->approverUids(),
			static fn (string $uid): bool => $uid !== $submitterUid,
		));
	}

	/**
	 * Speichert Schalter und N. Beim Einschalten muss der Kreis der Verwalter
	 * für N+1 reichen, sonst bliebe jeder Antrag eines Verwalters hängen.
	 *
	 * @throws \InvalidArgumentException bei einem ungültigen N oder zu wenigen Verwaltern
	 */
	public function save(bool $enabled, int $requiredApprovals): void {
		if ($requiredApprovals < 1) {
			throw new \InvalidArgumentException($this->l10n->t('Mindestens eine Freigabe ist erforderlich.'));
		}
		if ($enabled) {
			$available = count($this->approverUids());
			if ($available < $requiredApprovals + 1) {
				throw new \InvalidArgumentException($this->l10n->t(
					'Für %1$d Freigaben braucht es mindestens %2$d Verwalter (der Einreicher kann seinen eigenen Antrag nicht freigeben), vorhanden sind %3$d.',
					[$requiredApprovals, $requiredApprovals + 1, $available],
				));
			}
		}
		$this->config->setAppValue(Application::APP_ID, self::SETTING_ENABLED, $enabled ? '1' : '0');
		$this->config->setAppValue(Application::APP_ID, self::SETTING_REQUIRED_APPROVALS, (string)$requiredApprovals);
	}
}
