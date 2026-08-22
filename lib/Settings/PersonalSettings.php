<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Settings;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Erscheint unter Einstellungen → Persönlich, aber nur für App-Verwalter, die
 * keine Nextcloud-Administratoren sind - die sehen den Abschnitt bereits
 * unter Verwaltung (AdminSettings). Ohne diese Unterscheidung stünde der
 * Abschnitt doppelt für dieselbe Person.
 */
class PersonalSettings implements ISettings {
	public function __construct(
		private PermissionService $permissionService,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-settings');
		return new TemplateResponse(Application::APP_ID, 'settings/vereinsbuchhaltung');
	}

	public function getSection(): ?string {
		if (!$this->permissionService->isAdmin() || $this->permissionService->isServerAdmin()) {
			return null;
		}
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}
}
