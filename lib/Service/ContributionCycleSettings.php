<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCP\IConfig;

/**
 * Die beiden konfigurierbaren Abstände des Einzugszyklus-Crons (Spec §3.5/§7,
 * Issue #70): D−21 „Vorwarnfenster" und D−14 „Vorabinfo-Vorlauf". Bewusst eine
 * eigene, winzige Klasse statt zwei verstreuter `getAppValue()`-Aufrufe je
 * Nutzstelle – {@see ClaimGenerationService} (Generierungs-Horizont),
 * {@see ContributionPreNotificationService} (Versandfenster) und
 * {@see ContributionCycleTaskService} (Vorwarn-Aufgabe) brauchen alle
 * denselben Wert und müssen bei einer künftigen Änderung (z. B. Validierung)
 * nicht synchron gehalten werden.
 *
 * `prenotificationLeadDays()` ersetzt fachlich
 * {@see SepaNotificationService::LEAD_DAYS} für das neue Assignment/Claim-
 * Modell (Spec §4: „ersetzt SepaNotificationService::LEAD_DAYS = 14") – der
 * alte, feste Wert bleibt für den alten `vbh_sepa_batches`-Zyklus unverändert
 * bestehen, beide Systeme laufen bis zu dessen Ablösung nebeneinander.
 */
final class ContributionCycleSettings {

	public const SETTING_PRENOTIFICATION_LEAD_DAYS = 'prenotification_lead_days';
	public const DEFAULT_PRENOTIFICATION_LEAD_DAYS = 14;

	public const SETTING_WARNING_LEAD_DAYS = 'warning_lead_days';
	public const DEFAULT_WARNING_LEAD_DAYS = 21;

	/** Reine Plausibilitätsgrenzen gegen Tippfehler (0 oder ein Jahr Vorlauf ergäben keinen Sinn). */
	private const MIN_DAYS = 1;
	private const MAX_DAYS = 365;

	public function __construct(
		private IConfig $config,
	) {
	}

	public function prenotificationLeadDays(): int {
		return $this->readDays(self::SETTING_PRENOTIFICATION_LEAD_DAYS, self::DEFAULT_PRENOTIFICATION_LEAD_DAYS);
	}

	/** @throws \InvalidArgumentException außerhalb des Plausibilitätsbereichs */
	public function setPrenotificationLeadDays(int $days): void {
		$this->writeDays(self::SETTING_PRENOTIFICATION_LEAD_DAYS, $days);
	}

	public function warningLeadDays(): int {
		return $this->readDays(self::SETTING_WARNING_LEAD_DAYS, self::DEFAULT_WARNING_LEAD_DAYS);
	}

	/** @throws \InvalidArgumentException außerhalb des Plausibilitätsbereichs */
	public function setWarningLeadDays(int $days): void {
		$this->writeDays(self::SETTING_WARNING_LEAD_DAYS, $days);
	}

	private function readDays(string $key, int $default): int {
		$stored = (int)$this->config->getAppValue(Application::APP_ID, $key, (string)$default);
		return $stored >= self::MIN_DAYS && $stored <= self::MAX_DAYS ? $stored : $default;
	}

	private function writeDays(string $key, int $days): void {
		if ($days < self::MIN_DAYS || $days > self::MAX_DAYS) {
			throw new \InvalidArgumentException('Der Wert muss zwischen ' . self::MIN_DAYS . ' und ' . self::MAX_DAYS . ' Tagen liegen.');
		}
		$this->config->setAppValue(Application::APP_ID, $key, (string)$days);
	}
}
