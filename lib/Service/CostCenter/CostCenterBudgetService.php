<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\CostCenter;

use OCA\Vereinsbuchhaltung\Db\BookingCostCenterMapper;
use OCA\Vereinsbuchhaltung\Db\CostCenterBudgetMapper;
use OCA\Vereinsbuchhaltung\Exception\PeriodNotFoundException;
use OCA\Vereinsbuchhaltung\Service\CostCenterSettings;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Budget je Kostenstelle (Issue #335): Plan Einnahmen und Plan Ausgaben je
 * Kostenstelle und Geschäftsjahr.
 *
 * Ein Plan ist keine Buchung – er lässt sich auch für ein abgeschlossenes
 * Geschäftsjahr ändern, wie der Finanzplan je Konto. Gelesen wird auch bei
 * ausgeschaltetem Modul (die Werte bleiben erhalten), geschrieben nur bei
 * eingeschaltetem.
 */
class CostCenterBudgetService {

	public function __construct(
		private CostCenterSettings $settings,
		private BookingCostCenterMapper $costCenters,
		private CostCenterBudgetMapper $budgets,
		private PeriodService $periods,
		private IL10N $l10n,
	) {
	}

	/**
	 * @return list<array{costCenterId:int, incomeCents:int, expenseCents:int}>
	 * @throws PeriodNotFoundException
	 */
	public function forPeriod(string $userId, int $periodId): array {
		$this->periods->find($userId, $periodId);
		$out = [];
		foreach ($this->budgets->findByPeriod($periodId) as $costCenterId => $plan) {
			$out[] = ['costCenterId' => $costCenterId] + $plan;
		}
		return $out;
	}

	/**
	 * @return array{costCenterId:int, periodId:int, incomeCents:int, expenseCents:int}
	 * @throws \InvalidArgumentException bei ausgeschaltetem Modul oder negativem Wert
	 * @throws DoesNotExistException wenn es die Kostenstelle nicht gibt
	 * @throws PeriodNotFoundException wenn es das Geschäftsjahr nicht gibt
	 */
	public function set(string $userId, int $periodId, int $costCenterId, int $incomeCents, int $expenseCents): array {
		if (!$this->settings->isEnabled()) {
			throw new \InvalidArgumentException($this->l10n->t('Kostenstellen sind in den Einstellungen ausgeschaltet.'));
		}
		if ($incomeCents < 0 || $expenseCents < 0) {
			throw new \InvalidArgumentException($this->l10n->t('Planwerte dürfen nicht negativ sein.'));
		}
		// Ohne diese beiden Prüfungen entstünde bei einer veralteten oder
		// erfundenen ID ein Planwert, den keine Ansicht je zeigt.
		$this->periods->find($userId, $periodId);
		$this->costCenters->find($costCenterId, $userId);

		$this->budgets->upsert($costCenterId, $periodId, $incomeCents, $expenseCents);
		return ['costCenterId' => $costCenterId, 'periodId' => $periodId, 'incomeCents' => $incomeCents, 'expenseCents' => $expenseCents];
	}
}
