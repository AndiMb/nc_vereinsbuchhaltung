<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Änderungsstand des gemeinsamen Buchhaltungsbestands.
 *
 * Bei jeder erfolgreichen Schreiboperation (siehe RevisionMiddleware) wird ein
 * neues, eindeutiges Token gesetzt. Clients pollen GET /api/revision und laden
 * ihre Ansicht neu, sobald sich das Token ändert. Ein Zähler ist bewusst nicht
 * nötig: verglichen wird nur auf Ungleichheit, damit entfällt jedes
 * Read-Modify-Write-Rennen.
 */
class RevisionService {

	private const KEY = 'data_revision';

	public function __construct(
		private IAppConfig $config,
	) {
	}

	public function get(): string {
		return $this->config->getValueString(Application::APP_ID, self::KEY, '');
	}

	/**
	 * Setzt ein neues Token und liefert den Stand davor und danach. Der
	 * schreibende Browser übernimmt damit den neuen Stand selbst, wenn davor
	 * sein eigener bekannter Stand lag – sonst hielte sein nächster Abgleich die
	 * eigene Änderung für eine fremde und lüde die ganze Ansicht ein zweites Mal.
	 *
	 * @return array{previous: string, current: string}
	 */
	public function bump(): array {
		$previous = $this->get();
		$token = microtime(true) . '.' . bin2hex(random_bytes(4));
		$this->config->setValueString(Application::APP_ID, self::KEY, $token);
		return ['previous' => $previous, 'current' => $token];
	}
}
