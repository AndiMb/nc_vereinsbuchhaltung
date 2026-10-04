<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\ContributionCycleSettings;
use OCA\Vereinsbuchhaltung\Service\DueDateScheduleService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Der Terminplan als Einstellung (Spec §2.2 „Terminplan (Due Date Schedule)",
 * Issue #70) – siehe {@see DueDateScheduleService} für Modell und Guards.
 *
 * Rollen laut Spec §3.9, jede Methode ausdrücklich (Issue #119): Lesen ab
 * `revisor`; die Terminverschiebung – Standard-Einzugstag und Überschreibung
 * einzelner Perioden – ab `buchhalter`; die beiden Vorlaufzeiten
 * (Vorwarnfenster, Vorabinfo-Vorlauf) sind Einstellungen und damit nur für
 * `verwalter`. Sie bestimmen, wann Aufgaben und Vorabinfo-Mails ausgelöst
 * werden und ab wann eine Periode für Änderungen gesperrt ist – das gehört
 * in dieselbe Hand wie die übrigen Einstellungen des Beitragsmoduls.
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
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function index(): DataResponse {
		return new DataResponse([
			'schedule' => $this->schedule->getFullSchedule(),
			'prenotificationLeadDays' => $this->settings->prenotificationLeadDays(),
			'warningLeadDays' => $this->settings->warningLeadDays(),
		]);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
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
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function setOverride(int $intervalMonths, int $periodIndex, ?int $offsetDays = null): DataResponse {
		try {
			$this->schedule->setOverride($intervalMonths, $periodIndex, $offsetDays);
			return new DataResponse(['overrides' => $this->schedule->getOverrides($intervalMonths)]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/** Vorwarnfenster und Vorabinfo-Vorlauf – Einstellungen, deshalb `verwalter` (Spec §3.9). */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
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
