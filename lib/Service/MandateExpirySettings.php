<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCP\IConfig;
use OCP\IL10N;

/**
 * Die konfigurierbare Ablauf-Vorwarnung der Mandate (Spec §4
 * `expiry_warning_days`, Spec §7 „Mandat verfällt in 180 Tagen"): wie viele
 * Tage vor dem 36-Monats-Verfall ({@see MandateExpiryCalculator}) ein Mandat
 * als „läuft bald ab" gilt. Vorher eine feste Konstante
 * ({@see MandateExpiryCalculator::WARNING_DAYS}, bleibt als Standardwert
 * bestehen).
 *
 * Eigene, winzige Klasse nach dem Muster von {@see DunningSettings}: der
 * Rechner selbst bleibt eine reine Rechenklasse ohne Konfigurationszugriff,
 * die Aufrufer ({@see MandateService::findDueForExpiryWarning()}) geben ihm
 * den Wert mit.
 */
final class MandateExpirySettings {

	use OptionalL10n;

	public const SETTING_WARNING_DAYS = 'expiry_warning_days';
	public const DEFAULT_WARNING_DAYS = MandateExpiryCalculator::WARNING_DAYS;

	/** Reine Plausibilitätsgrenzen gegen Tippfehler (über ein Jahr Vorwarnung ergäbe keinen Sinn). */
	public const MIN_DAYS = 1;
	public const MAX_DAYS = 365;

	public function __construct(
		private IConfig $config,
		private ?IL10N $l10n = null,
	) {
	}

	public function warningDays(): int {
		$stored = (int)$this->config->getAppValue(Application::APP_ID, self::SETTING_WARNING_DAYS, (string)self::DEFAULT_WARNING_DAYS);
		return $stored >= self::MIN_DAYS && $stored <= self::MAX_DAYS ? $stored : self::DEFAULT_WARNING_DAYS;
	}

	/** @throws \InvalidArgumentException außerhalb des Plausibilitätsbereichs */
	public function setWarningDays(int $days): void {
		if ($days < self::MIN_DAYS || $days > self::MAX_DAYS) {
			throw new \InvalidArgumentException($this->msg('Die Ablauf-Vorwarnung muss zwischen %1$d und %2$d Tagen liegen.', [self::MIN_DAYS, self::MAX_DAYS]));
		}
		$this->config->setAppValue(Application::APP_ID, self::SETTING_WARNING_DAYS, (string)$days);
	}
}
