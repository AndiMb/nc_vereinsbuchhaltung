<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\ContributionCycleSettings;
use OCA\Vereinsbuchhaltung\Service\DueDateScheduleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Der Terminplan als Einstellung (Spec §2.2 „Terminplan (Due Date Schedule)",
 * Issue #70) – siehe {@see DueDateScheduleService} für Modell und Guards.
 * Lesen ist ab `revisor` erlaubt (Verb-Heuristik der PermissionMiddleware),
 * Schreiben ab `buchhalter`.
 */
class DueDateScheduleController extends Controller {

	public function __construct(
		IRequest $request,
		private DueDateScheduleService $schedule,
		private ContributionCycleSettings $settings,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse([
			'schedule' => $this->schedule->getFullSchedule(),
			'prenotificationLeadDays' => $this->settings->prenotificationLeadDays(),
			'warningLeadDays' => $this->settings->warningLeadDays(),
		]);
	}

	#[NoAdminRequired]
	public function setDefaultDay(int $intervalMonths, int $offsetDays): DataResponse {
		try {
			$this->schedule->setDefaultOffsetDays($intervalMonths, $offsetDays);
			return new DataResponse(['defaultOffsetDays' => $this->schedule->getDefaultOffsetDays($intervalMonths)]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/** `offsetDays` weglassen/null entfernt die Überschreibung wieder. */
	#[NoAdminRequired]
	public function setOverride(int $intervalMonths, int $periodIndex, ?int $offsetDays = null): DataResponse {
		try {
			$this->schedule->setOverride($intervalMonths, $periodIndex, $offsetDays);
			return new DataResponse(['overrides' => $this->schedule->getOverrides($intervalMonths)]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	public function setLeadDays(?int $prenotificationLeadDays = null, ?int $warningLeadDays = null): DataResponse {
		try {
			if ($prenotificationLeadDays !== null) {
				$this->settings->setPrenotificationLeadDays($prenotificationLeadDays);
			}
			if ($warningLeadDays !== null) {
				$this->settings->setWarningLeadDays($warningLeadDays);
			}
			return new DataResponse([
				'prenotificationLeadDays' => $this->settings->prenotificationLeadDays(),
				'warningLeadDays' => $this->settings->warningLeadDays(),
			]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
