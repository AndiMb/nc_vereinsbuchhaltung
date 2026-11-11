<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Schalter für Kostenstellen an der Buchungszeile (Issue #7).
 *
 * Standardmäßig aus. Ausgeschaltet zeigt die Oberfläche nichts davon, und
 * die Antworten der Buchungs-API sowie der CSV-Journalexport sind
 * zeichengleich zum Stand vor der Funktion. Bereits gespeicherte
 * Zuordnungen bleiben beim Ausschalten erhalten.
 */
class CostCenterSettings {

	public const SETTING_ENABLED = 'cost_centers_enabled';

	public function __construct(
		private IAppConfig $config,
	) {
	}

	public function isEnabled(): bool {
		return $this->config->getValueString(Application::APP_ID, self::SETTING_ENABLED, '0') === '1';
	}
}
