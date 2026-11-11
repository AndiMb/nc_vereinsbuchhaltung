<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\Report\BudgetStatus;
use OCP\IAppConfig;
use OCP\IL10N;

/**
 * Die vereinsweiten Ampel-Schwellen der visuellen Gliederungsansicht (Plan
 * gegen Ist, siehe {@see BudgetStatus}). Gespeichert als ein JSON-Wert unter
 * {@see KEY}, damit die fünf Zahlen nur gemeinsam gelten: einzeln gespeichert
 * könnte eine halb übernommene Änderung die Reihenfolge verletzen.
 *
 * Lesen ist tolerant – fehlt der Eintrag oder ist er in irgendeiner Weise
 * ungültig, gelten die Vorgaben als Ganzes. Schreiben ist streng und meldet
 * den Grund (Muster {@see DunningSettings}).
 */
final class BudgetStatusSettings {

	use OptionalL10n;

	public const KEY = 'budget_status_thresholds';

	/** Reine Plausibilitätsgrenzen in Prozent. */
	private const MIN = 0;
	private const MAX = 999;

	public function __construct(
		private IAppConfig $config,
		private ?IL10N $l10n = null,
	) {
	}

	/**
	 * Die gespeicherten Schwellen, sonst {@see BudgetStatus::DEFAULTS}.
	 *
	 * @return array<string, int>
	 */
	public function thresholds(): array {
		$stored = json_decode($this->config->getValueString(Application::APP_ID, self::KEY, ''), true);
		if (!is_array($stored)) {
			return BudgetStatus::DEFAULTS;
		}
		try {
			return $this->validated($stored);
		} catch (\InvalidArgumentException) {
			return BudgetStatus::DEFAULTS;
		}
	}

	/**
	 * @param array<mixed> $values genau die fünf Schlüssel aus {@see BudgetStatus::DEFAULTS}
	 * @throws \InvalidArgumentException bei fehlenden oder unbekannten Schlüsseln, Werten außerhalb 0–999 oder verletzter Reihenfolge
	 */
	public function set(array $values): void {
		$this->config->setValueString(Application::APP_ID, self::KEY, (string)json_encode($this->validated($values)));
	}

	/**
	 * @param array<mixed> $values
	 * @return array<string, int> in der Reihenfolge von DEFAULTS
	 * @throws \InvalidArgumentException
	 */
	private function validated(array $values): array {
		if (count($values) !== count(BudgetStatus::DEFAULTS) || array_diff_key(BudgetStatus::DEFAULTS, $values) !== []) {
			throw new \InvalidArgumentException($this->msg('Die Ampel-Schwellen sind unvollständig.'));
		}
		$result = [];
		foreach (array_keys(BudgetStatus::DEFAULTS) as $key) {
			$value = self::wholeNumber($values[$key]);
			if ($value === null || $value < self::MIN || $value > self::MAX) {
				throw new \InvalidArgumentException($this->msg('Jede Ampel-Schwelle muss eine ganze Zahl zwischen %1$d und %2$d sein.', [self::MIN, self::MAX]));
			}
			$result[$key] = $value;
		}
		if ($result['expWarn'] >= $result['expCrit']) {
			throw new \InvalidArgumentException($this->msg('Bei den Ausgaben muss die Schwelle für Orange unter der für Rot liegen.'));
		}
		if ($result['incWarn'] >= $result['incGood']) {
			throw new \InvalidArgumentException($this->msg('Bei den Einnahmen muss die Schwelle für Rot unter der für Grün liegen.'));
		}
		return $result;
	}

	/**
	 * Ganze Zahl, auch als Ziffernfolge (HTTP-Clients senden Text) oder als
	 * Gleitkommazahl ohne Nachkommastellen (JSON `80.0`); sonst null.
	 */
	private static function wholeNumber(mixed $value): ?int {
		if (is_int($value)) {
			return $value;
		}
		if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 1e6) {
			return (int)$value;
		}
		if (is_string($value) && preg_match('/^-?\d{1,6}$/', $value) === 1) {
			return (int)$value;
		}
		return null;
	}
}
