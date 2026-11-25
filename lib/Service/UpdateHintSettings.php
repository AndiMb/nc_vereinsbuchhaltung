<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Schalter „Hinweise auf neue Versionen“ (Einstellungen → Darstellung).
 *
 * Standardmäßig an. Ausgeschaltet erscheint nach einem Update weder der
 * eigene „Was ist neu“-Splash der App (WhatsNewDialog.vue) noch Nextclouds
 * Benachrichtigung „Vereinsbuchhaltung wurde aktualisiert“ aus der App
 * `updatenotification` – die verwirft UpdateNotificationFilter. Über den
 * Hilfe-Link „Was ist neu in Version …?“ lässt sich die Liste weiterhin
 * aufrufen; das ist kein Hinweis, sondern eine Frage der Person selbst.
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
