<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\CostCenter\CostCenterBudgetService;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Budget je Kostenstelle (Issue #335).
 *
 * Rollen über die Verb-Regel der PermissionMiddleware: lesen ab Revisor,
 * schreiben ab Buchhalter. Ein unbekanntes Geschäftsjahr endet wie beim
 * Finanzplan als 404 (PeriodNotFoundException).
 */
class CostCenterBudgetController extends Controller {

	use BookContext;

	public function __construct(
		IRequest $request,
		private CostCenterBudgetService $service,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Planwerte eines Geschäftsjahres; ohne gewähltes Jahr gibt es keinen Plan. */
	#[NoAdminRequired]
	public function index(?int $period = null): DataResponse {
		if (!PeriodService::isSelected($period)) {
			return new DataResponse([]);
		}
		return new DataResponse($this->service->forPeriod($this->userId(), (int)$period));
	}

	/**
	 * Setzt die beiden Planwerte einer Kostenstelle (Beträge in Euro); beide
	 * 0 entfernt den Plan.
	 */
	#[NoAdminRequired]
	public function set(int $period, int $costCenterId, float $income = 0, float $expense = 0): DataResponse {
		try {
			return new DataResponse($this->service->set(
				$this->userId(),
				$period,
				$costCenterId,
				(int)round($income * 100),
				(int)round($expense * 100),
			));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Kostenstelle nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}
}
