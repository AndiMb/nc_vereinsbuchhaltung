<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\NextcloudAccountOptionsService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Angaben für das Formular „Nextcloud-Konto anlegen“ beim Aufnehmen eines Mitglieds
 * (siehe {@see NextcloudAccountOptionsService}). Das Konto selbst legt das Frontend
 * über die OCS-Schnittstelle von Nextcloud an – dort gelten Rechte, Passwortregeln
 * und die Einladungsmail so wie in der Benutzerverwaltung.
 */
class NextcloudAccountController extends Controller {

	public function __construct(
		IRequest $request,
		private NextcloudAccountOptionsService $options,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function options(): DataResponse {
		return new DataResponse($this->options->forCurrentUser());
	}
}
