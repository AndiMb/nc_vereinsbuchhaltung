<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Änderungsprotokoll – lesbar für alle Leseberechtigten (insbesondere
 * für die Kassenprüfung).
 */
class AuditController extends Controller {

	public function __construct(
		IRequest $request,
		private AuditService $auditService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(int $limit = 100, int $offset = 0): DataResponse {
		return new DataResponse($this->auditService->latest($limit, $offset));
	}
}
