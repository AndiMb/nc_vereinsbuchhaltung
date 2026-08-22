<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\PermissionMapper;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Ermittelt die Rolle des aktuellen Nutzers und die daraus folgenden Rechte.
 *
 * Rollen (aufsteigend): kein Zugriff < Revisor (lesen) < Buchhalter (schreiben)
 * < Verwalter (alles inkl. Rechtevergabe).
 * Nextcloud-Administratoren sind immer Verwalter (Bootstrap/Notausgang).
 */
class PermissionService {
	public const ROLE_NONE = 'none';
	public const ROLE_READ = 'revisor';
	public const ROLE_WRITE = 'buchhalter';
	public const ROLE_ADMIN = 'verwalter';

	public const RANK = [
		self::ROLE_NONE => 0,
		self::ROLE_READ => 1,
		self::ROLE_WRITE => 2,
		self::ROLE_ADMIN => 3,
	];

	private ?string $cachedRole = null;

	public function __construct(
		private PermissionMapper $mapper,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
	) {
	}

	public function getRole(): string {
		if ($this->cachedRole !== null) {
			return $this->cachedRole;
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->cachedRole = self::ROLE_NONE;
		}
		$uid = $user->getUID();
		if ($this->groupManager->isAdmin($uid)) {
			return $this->cachedRole = self::ROLE_ADMIN;
		}
		$groupIds = $this->groupManager->getUserGroupIds($user);
		$best = self::ROLE_NONE;
		foreach ($this->mapper->findMatching($uid, $groupIds) as $perm) {
			$role = $perm->getRole();
			if (isset(self::RANK[$role]) && self::RANK[$role] > self::RANK[$best]) {
				$best = $role;
			}
		}
		return $this->cachedRole = $best;
	}

	public function canRead(): bool {
		return self::RANK[$this->getRole()] >= self::RANK[self::ROLE_READ];
	}

	public function canWrite(): bool {
		return self::RANK[$this->getRole()] >= self::RANK[self::ROLE_WRITE];
	}

	public function isAdmin(): bool {
		return self::RANK[$this->getRole()] >= self::RANK[self::ROLE_ADMIN];
	}

	/**
	 * Ob der aktuelle Nutzer Nextcloud-Administrator ist (unabhängig von der
	 * App-Rolle, die das bei isAdmin() bereits einschließt). Wird gebraucht,
	 * um zwischen "Einstellungen → Verwaltung" und "Einstellungen →
	 * Persönlich" zu unterscheiden, siehe Settings\PersonalSettings.
	 */
	public function isServerAdmin(): bool {
		$user = $this->userSession->getUser();
		return $user !== null && $this->groupManager->isAdmin($user->getUID());
	}

	/**
	 * @return array<string,mixed>
	 */
	public function describeCurrent(): array {
		$user = $this->userSession->getUser();
		$role = $this->getRole();
		return [
			'uid' => $user?->getUID(),
			'displayName' => $user?->getDisplayName(),
			'role' => $role,
			'canRead' => $this->canRead(),
			'canWrite' => $this->canWrite(),
			'isAdmin' => $this->isAdmin(),
			'isServerAdmin' => $this->isServerAdmin(),
		];
	}
}
