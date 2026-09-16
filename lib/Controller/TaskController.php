<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\TaskService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Aufgaben/Störfälle (siehe {@see \OCA\Vereinsbuchhaltung\Db\Task}). Gleiche
 * Einstufung wie die Mitglieder-Unterreiter (ab Buchhalter, nicht Revisor):
 * die Meldungen nennen Mitgliedsnamen und teils Mailadressen.
 */
class TaskController extends Controller {

	public function __construct(
		IRequest $request,
		private TaskService $service,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function index(): DataResponse {
		return new DataResponse(array_map(static fn ($t) => $t->jsonSerialize(), $this->service->findAll()));
	}
}
