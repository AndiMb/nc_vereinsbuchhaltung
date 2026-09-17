<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCP\IConfig;

/**
 * Der konfigurierbare Mahnabstand (Spec §3.6 „nach konfigurierbarem
 * Mahnabstand (Default 14 Tage)", Issue #73): der Abstand zwischen
 * Zahlungsaufforderung (Stufe 0) und Zahlungserinnerung (Stufe 1) sowie
 * zwischen Zahlungserinnerung und Mahnung (Stufe 2) – dieselbe Zahl für beide
 * Übergänge, die Spec nennt keinen zweiten, eigenen Wert.
 *
 * Bewusst eine eigene, winzige Klasse statt eines Felds in
 * {@see ContributionCycleSettings}: das Mahnwesen ist fachlich eigenständig
 * (Issue #73 statt #70) und braucht diesen Wert nicht zwingend gemeinsam mit
 * den Einzugszyklus-Abständen – folgt aber demselben Muster (siehe dortige
 * Klassendoc zur Begründung „eigene Klasse statt verstreuter
 * getAppValue()-Aufrufe").
 *
 * Der Vorlauf für die Stufe-0-Zahlungsaufforderung bei `ueberweisung`-Fälligkeit
 * (Spec „mit Vorabinfo-Vorlauf bei ueberweisung-Fälligkeit") nutzt dagegen
 * bewusst denselben Wert wie {@see ContributionCycleSettings::prenotificationLeadDays()}
 * – siehe {@see \OCA\Vereinsbuchhaltung\Service\DunningLadderService}-Klassendoc:
 * ein Überweiser bekommt nie eine separate Vorabinfo-Mail, die
 * Zahlungsaufforderung übernimmt fachlich deren Rolle als erster
 * Fälligkeits-Hinweis.
 */
final class DunningSettings {

	public const SETTING_INTERVAL_DAYS = 'dunning_interval_days';
	public const DEFAULT_INTERVAL_DAYS = 14;

	/** Reine Plausibilitätsgrenzen gegen Tippfehler. */
	private const MIN_DAYS = 1;
	private const MAX_DAYS = 365;

	public function __construct(
		private IConfig $config,
	) {
	}

	public function intervalDays(): int {
		$stored = (int)$this->config->getAppValue(Application::APP_ID, self::SETTING_INTERVAL_DAYS, (string)self::DEFAULT_INTERVAL_DAYS);
		return $stored >= self::MIN_DAYS && $stored <= self::MAX_DAYS ? $stored : self::DEFAULT_INTERVAL_DAYS;
	}

	/** @throws \InvalidArgumentException außerhalb des Plausibilitätsbereichs */
	public function setIntervalDays(int $days): void {
		if ($days < self::MIN_DAYS || $days > self::MAX_DAYS) {
			throw new \InvalidArgumentException('Der Mahnabstand muss zwischen ' . self::MIN_DAYS . ' und ' . self::MAX_DAYS . ' Tagen liegen.');
		}
		$this->config->setAppValue(Application::APP_ID, self::SETTING_INTERVAL_DAYS, (string)$days);
	}
}
