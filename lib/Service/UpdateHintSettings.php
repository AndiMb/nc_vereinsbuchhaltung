<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Schalter „Hinweise auf neue Versionen“ (Einstellungen → Darstellung), ab
 * Werk an. Aus: kein „Was ist neu“-Splash und keine Nextcloud-Benachrichtigung
 * zum Update (UpdateNotificationFilter).
 */
class UpdateHintSettings {

	public const SETTING_ENABLED = 'update_hints_enabled';

	public function __construct(
		private IAppConfig $config,
	) {
	}

	public function isEnabled(): bool {
		return $this->config->getValueString(Application::APP_ID, self::SETTING_ENABLED, '1') !== '0';
	}
}
