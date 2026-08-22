<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Settings;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Erscheint unter Einstellungen → Verwaltung. Nextcloud-Administratoren sind
 * laut PermissionService immer Verwalter, eine zusätzliche Rollenprüfung
 * braucht es hier also nicht - anders als bei PersonalSettings.
 */
class AdminSettings implements ISettings {
	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-settings');
		return new TemplateResponse(Application::APP_ID, 'settings/vereinsbuchhaltung');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}
}
