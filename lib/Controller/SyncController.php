<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\RevisionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Leichtgewichtiger Endpunkt für das Kollaborations-Polling: liefert nur das
 * aktuelle Änderungs-Token. Die Leseberechtigung erzwingt die
 * PermissionMiddleware (GET = Revisor+).
 */
class SyncController extends Controller {

	public function __construct(
		IRequest $request,
		private RevisionService $revision,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function revision(): DataResponse {
		return new DataResponse(['revision' => $this->revision->get()]);
	}
}
