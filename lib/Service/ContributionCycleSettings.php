<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCP\IConfig;
use OCP\IL10N;

/**
 * Die konfigurierbaren Abstände des Einzugszyklus (Spec §3.5/§7/§3.9): D−21
 * „Vorwarnfenster", D−14 „Vorabinfo-Vorlauf" (Issue #70) und D−5
 * „Vorlauf-Puffer" (Issue #71, Spec §8 Compliance-Anhang: „Vorlagefrist D-1
 * bis D-14 … Vorlauf-Puffer konfigurierbar (Default 5 Tage)"). Bewusst eine
 * eigene, winzige Klasse statt verstreuter `getAppValue()`-Aufrufe je
 * Nutzstelle – {@see ClaimGenerationService} (Generierungs-Horizont),
 * {@see ContributionPreNotificationService} (Versandfenster),
 * {@see ContributionCycleTaskService} (Vorwarn-Aufgabe) und
 * {@see DebitBatchTaskService} („Freigabe fällig"/„Einreichung überfällig")
 * brauchen dieselben Werte und müssen bei einer künftigen Änderung (z. B.
 * Validierung) nicht synchron gehalten werden. Spec §3.9 führt alle drei
 * zusammen mit der XML-Ablage als EINE `verwalter`-Einstellungsgruppe.
 *
 * `prenotificationLeadDays()` ersetzt die frühere feste Vorlaufzeit des
 * flachen Alt-Moduls (Spec §4: „ersetzt SepaNotificationService::LEAD_DAYS =
 * 14", Issue #107 hat den Dienst entfernt).
 */
final class ContributionCycleSettings {

	use OptionalL10n;

	public const SETTING_PRENOTIFICATION_LEAD_DAYS = 'prenotification_lead_days';
	public const DEFAULT_PRENOTIFICATION_LEAD_DAYS = 14;

	public const SETTING_WARNING_LEAD_DAYS = 'warning_lead_days';
	public const DEFAULT_WARNING_LEAD_DAYS = 21;

	public const SETTING_RELEASE_LEAD_DAYS = 'release_lead_days';
	public const DEFAULT_RELEASE_LEAD_DAYS = 5;

	/** Reine Plausibilitätsgrenzen gegen Tippfehler (0 oder ein Jahr Vorlauf ergäben keinen Sinn). */
	private const MIN_DAYS = 1;
	private const MAX_DAYS = 365;

	public function __construct(
		private IConfig $config,
		private ?IL10N $l10n = null,
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

	/** Der „Vorlauf-Puffer" (Spec §8): ab wann ein noch nicht freigegebener/eingereichter Lauf zum Störfall wird (Issue #71). */
	public function releaseLeadDays(): int {
		return $this->readDays(self::SETTING_RELEASE_LEAD_DAYS, self::DEFAULT_RELEASE_LEAD_DAYS);
	}

	/** @throws \InvalidArgumentException außerhalb des Plausibilitätsbereichs */
	public function setReleaseLeadDays(int $days): void {
		$this->writeDays(self::SETTING_RELEASE_LEAD_DAYS, $days);
	}

	private function readDays(string $key, int $default): int {
		$stored = (int)$this->config->getAppValue(Application::APP_ID, $key, (string)$default);
		return $stored >= self::MIN_DAYS && $stored <= self::MAX_DAYS ? $stored : $default;
	}

	private function writeDays(string $key, int $days): void {
		if ($days < self::MIN_DAYS || $days > self::MAX_DAYS) {
			throw new \InvalidArgumentException($this->msg('Der Wert muss zwischen %1$d und %2$d Tagen liegen.', [self::MIN_DAYS, self::MAX_DAYS]));
		}
		$this->config->setAppValue(Application::APP_ID, $key, (string)$days);
	}
}
