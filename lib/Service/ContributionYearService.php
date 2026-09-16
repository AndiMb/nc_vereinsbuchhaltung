<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCP\IConfig;

/**
 * Das Beitragsjahr (Spec §3.3/§4): „Ein Beitragsjahr pro Verein, Startmonat
 * konfigurierbar. Periode ≠ Einzugstermin." Bewusst unabhängig vom
 * `Period`-Geschäftsjahr der Kern-Buchhaltung (siehe {@see PeriodRule}) – ein
 * Verein kann sein Geschäftsjahr im Oktober beginnen lassen und trotzdem
 * Beiträge im Kalenderjahr rechnen, oder umgekehrt (Spec §3.7).
 *
 * Rechnet die Periodengrenzen einer Zuweisung nicht selbst aus, sondern
 * reicht Startmonat + Turnusmonate als Raster an {@see PeriodRule} weiter –
 * dieselbe, bereits getestete Rasterlogik wie beim Geschäftsjahr, nur mit
 * `startDay = 1` (die Spec kennt keinen konfigurierbaren Starttag fürs
 * Beitragsjahr, nur den Startmonat).
 */
class ContributionYearService {
	private const SETTING_KEY = 'fiscal_year_start_month';
	public const DEFAULT_START_MONTH = 1;

	public function __construct(
		private IConfig $config,
	) {
	}

	public function getStartMonth(): int {
		$stored = $this->config->getAppValue(Application::APP_ID, self::SETTING_KEY, (string)self::DEFAULT_START_MONTH);
		$month = (int)$stored;
		return $month >= 1 && $month <= 12 ? $month : self::DEFAULT_START_MONTH;
	}

	/** @throws \InvalidArgumentException wenn der Monat nicht zwischen 1 und 12 liegt */
	public function setStartMonth(int $month): void {
		if ($month < 1 || $month > 12) {
			throw new \InvalidArgumentException('Startmonat muss zwischen 1 und 12 liegen.');
		}
		$this->config->setAppValue(Application::APP_ID, self::SETTING_KEY, (string)$month);
	}

	/**
	 * Das Rasterjahr für einen gegebenen Turnus, zur Weitergabe an
	 * {@see PeriodRule::containing()}/{@see PeriodRule::gridIndex()}.
	 *
	 * @return array{startDay:int, startMonth:int, lengthMonths:int}
	 * @throws \InvalidArgumentException bei ungültigem Turnus (kein Teiler von 12)
	 */
	public function periodRuleFor(int $intervalMonths): array {
		if (!in_array($intervalMonths, PeriodRule::LENGTHS, true)) {
			throw new \InvalidArgumentException('Turnus muss ein Teiler von 12 sein (1, 2, 3, 4, 6 oder 12).');
		}
		return ['startDay' => 1, 'startMonth' => $this->getStartMonth(), 'lengthMonths' => $intervalMonths];
	}

	/**
	 * Die Beitragsperiode (Turnus-Zeitraum), die $date enthält.
	 *
	 * @return array{0:string,1:string} [periodStart, periodEnd], beide inklusive
	 * @throws \InvalidArgumentException bei ungültigem Turnus oder Datum
	 */
	public function periodContaining(int $intervalMonths, string $date): array {
		return PeriodRule::containing($this->periodRuleFor($intervalMonths), $date);
	}
}
