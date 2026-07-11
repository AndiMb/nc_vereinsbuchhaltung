<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\PermissionMapper;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;

class PermissionController extends Controller {

	public function __construct(
		IRequest $request,
		private PermissionService $permissions,
		private PermissionMapper $mapper,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private AuditService $audit,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Eigene Rolle/Rechte – für jeden angemeldeten Nutzer erreichbar. */
	#[NoAdminRequired]
	public function me(): DataResponse {
		return new DataResponse($this->permissions->describeCurrent());
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse($this->mapper->findAll());
	}

	/** Liste der Nextcloud-Gruppen für die Auswahl. */
	#[NoAdminRequired]
	public function groups(): DataResponse {
		$groups = [];
		foreach ($this->groupManager->search('') as $group) {
			$groups[] = ['id' => $group->getGID(), 'displayName' => $group->getDisplayName()];
		}
		return new DataResponse($groups);
	}

	/** Liste der Nextcloud-Nutzer für die Auswahl. */
	#[NoAdminRequired]
	public function users(): DataResponse {
		$users = [];
		foreach ($this->userManager->search('') as $user) {
			$users[] = ['id' => $user->getUID(), 'displayName' => $user->getDisplayName()];
		}
		return new DataResponse($users);
	}

	#[NoAdminRequired]
	public function setRole(string $principalType, string $principalId, string $role): DataResponse {
		if (!in_array($principalType, ['user', 'group'], true)) {
			return new DataResponse(['message' => 'Ungültiger Typ'], Http::STATUS_BAD_REQUEST);
		}
		if (!in_array($role, [PermissionService::ROLE_READ, PermissionService::ROLE_WRITE, PermissionService::ROLE_ADMIN], true)) {
			return new DataResponse(['message' => 'Ungültige Rolle'], Http::STATUS_BAD_REQUEST);
		}
		$principalId = trim($principalId);
		if ($principalId === '') {
			return new DataResponse(['message' => 'Nutzer/Gruppe fehlt'], Http::STATUS_BAD_REQUEST);
		}
		$entry = $this->mapper->upsert($principalType, $principalId, $role);
		$this->audit->log('Berechtigung gesetzt', 'permission', null, [
			'typ' => $principalType,
			'wer' => $principalId,
			'rolle' => $role,
		]);
		return new DataResponse($entry, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		try {
			$perm = $this->mapper->find($id);
			$this->mapper->delete($perm);
			$this->audit->log('Berechtigung entfernt', 'permission', $id, [
				'typ' => $perm->getPrincipalType(),
				'wer' => $perm->getPrincipalId(),
			]);
			return new DataResponse([]);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Eintrag nicht gefunden'], Http::STATUS_NOT_FOUND);
		}
	}
}
