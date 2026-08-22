<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Settings;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Dieselbe Klasse wird sowohl als <admin-section> als auch als
 * <personal-section> eingetragen (appinfo/info.xml) - die Registrierung ist
 * intern nach Typ getrennt, ein Abschnitt kann also in beiden Bereichen
 * auftauchen, ohne dass es hier zwei Klassen bräuchte.
 */
class Section implements IIconSection {
	public function __construct(
		private IL10N $l,
		private IURLGenerator $url,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l->t('Vereinsbuchhaltung');
	}

	public function getPriority(): int {
		return 75;
	}

	public function getIcon(): string {
		return $this->url->imagePath(Application::APP_ID, 'app-dark.svg');
	}
}
