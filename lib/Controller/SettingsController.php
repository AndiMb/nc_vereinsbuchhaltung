<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\DemoDataService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

class SettingsController extends Controller {

	public function __construct(
		IRequest $request,
		private IConfig $config,
		private PermissionService $permissionService,
		private DemoDataService $demoService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse([
			'storage_user' => $this->config->getAppValue(Application::APP_ID, 'storage_user', ''),
			'storage_path' => $this->config->getAppValue(Application::APP_ID, 'storage_path', 'Vereinsbuchhaltung/Belege'),
			'cost_center_mode' => $this->config->getAppValue(Application::APP_ID, 'cost_center_mode', 'group'),
			'club_name' => $this->config->getAppValue(Application::APP_ID, 'club_name', ''),
			'demo_active' => $this->demoService->isActive(),
		]);
	}

	#[NoAdminRequired]
	public function update(): DataResponse {
		if (PermissionService::RANK[$this->permissionService->getRole()] < PermissionService::RANK[PermissionService::ROLE_ADMIN]) {
			return new DataResponse(['message' => 'Zugriff verweigert'], Http::STATUS_FORBIDDEN);
		}

		$storageUser = trim((string)($this->request->getParam('storage_user') ?? ''));
		$storagePath = trim((string)($this->request->getParam('storage_path') ?? ''));
		if ($storagePath === '') {
			$storagePath = 'Vereinsbuchhaltung/Belege';
		}
		$ccMode = (string)($this->request->getParam('cost_center_mode') ?? 'group');
		if (!in_array($ccMode, ['group', 'account'], true)) {
			$ccMode = 'group';
		}
		$clubName = mb_substr(trim((string)($this->request->getParam('club_name') ?? '')), 0, 128);

		$this->config->setAppValue(Application::APP_ID, 'storage_user', $storageUser);
		$this->config->setAppValue(Application::APP_ID, 'storage_path', $storagePath);
		$this->config->setAppValue(Application::APP_ID, 'cost_center_mode', $ccMode);
		$this->config->setAppValue(Application::APP_ID, 'club_name', $clubName);

		return new DataResponse([
			'storage_user' => $storageUser,
			'storage_path' => $storagePath,
			'cost_center_mode' => $ccMode,
			'club_name' => $clubName,
		]);
	}
}
