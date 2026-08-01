<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\DemoDataService;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class DemoController extends Controller {

	use BookContext;

	public function __construct(
		IRequest $request,
		private DemoDataService $demoService,
		private PermissionService $permissionService,
		private AuditService $audit,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Legt den Beispielverein an – nur Verwalter, nur wenn noch keine Konten existieren. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function seed(): DataResponse {
		if (!$this->permissionService->isAdmin()) {
			return new DataResponse(['message' => 'Nur Verwalter dürfen Beispieldaten anlegen.'], Http::STATUS_FORBIDDEN);
		}
		try {
			$result = $this->demoService->seed($this->userId());
			$this->audit->log('Beispieldaten angelegt', 'import', null, $result);
			return new DataResponse($result, Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
