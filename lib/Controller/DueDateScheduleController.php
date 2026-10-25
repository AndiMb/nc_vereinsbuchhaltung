<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\ContributionCycleSettings;
use OCA\Vereinsbuchhaltung\Service\ContributionYearService;
use OCA\Vereinsbuchhaltung\Service\DueDateScheduleService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
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
		private ContributionYearService $contributionYear,
		private AssignmentMapper $assignments,
		private ITimeFactory $time,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Neben Terminplan und Fristen das, was die Ansicht zum Erklären braucht:
	 * die Mitglieder je Turnus (ungenutzte Turnusse treten zurück) und die
	 * Grenzen des Versatzes.
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function index(): DataResponse {
		return new DataResponse([
			'schedule' => array_map(fn (array $entry) => ['defaultOffsetDays' => $entry['defaultOffsetDays'], 'overrides' => self::overridesJson($entry['overrides'])], $this->schedule->getFullSchedule()),
			'prenotificationLeadDays' => $this->settings->prenotificationLeadDays(),
			'warningLeadDays' => $this->settings->warningLeadDays(),
			'releaseLeadDays' => $this->settings->releaseLeadDays(),
			'memberCounts' => $this->assignments->countMembersByIntervalFrom($this->today()),
			'offsetRange' => ['min' => DueDateScheduleService::MIN_OFFSET_DAYS, 'max' => DueDateScheduleService::MAX_OFFSET_DAYS],
		]);
	}

	/**
	 * Was ein Entwurf des Terminplans ergäbe – Termine je Periode und Verstöße
	 * gegen die Guards –, ohne etwas zu speichern. Rechnen statt ändern, deshalb
	 * ab `revisor`; POST nur, weil der Entwurf eine Zuordnung Periode → Versatz
	 * mitbringt.
	 *
	 * @param array<int|string,int> $overrides Periodenindex => Tage-Versatz
	 * @param int|null $year Anker-Kalenderjahr des gezeigten Beitragsjahrs, sonst das laufende
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function preview(int $intervalMonths, int $defaultOffsetDays, array $overrides = [], ?int $year = null): DataResponse {
		try {
			return new DataResponse($this->schedule->preview($intervalMonths, $defaultOffsetDays, $overrides, $year ?? $this->contributionYear->anchorYearFor($this->today())));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Standard-Einzugstag und Überschreibungen eines Turnus in einem Schritt
	 * (Terminverschiebung, deshalb ab `buchhalter` wie die Einzelaufrufe).
	 *
	 * @param array<int|string,int> $overrides Periodenindex => Tage-Versatz; fehlende Perioden folgen dem Standard
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function setSchedule(int $intervalMonths, int $defaultOffsetDays, array $overrides = []): DataResponse {
		try {
			$this->schedule->setSchedule($intervalMonths, $defaultOffsetDays, $overrides);
			return new DataResponse([
				'defaultOffsetDays' => $this->schedule->getDefaultOffsetDays($intervalMonths),
				'overrides' => self::overridesJson($this->schedule->getOverrides($intervalMonths)),
			]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
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
			return new DataResponse(['overrides' => self::overridesJson($this->schedule->getOverrides($intervalMonths))]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/** Vorwarnfenster, Vorabinfo-Vorlauf und Freigabe-Vorlauf – Einstellungen, deshalb `verwalter` (Spec §3.9). */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function setLeadDays(?int $prenotificationLeadDays = null, ?int $warningLeadDays = null, ?int $releaseLeadDays = null): DataResponse {
		try {
			if ($prenotificationLeadDays !== null) {
				$this->settings->setPrenotificationLeadDays($prenotificationLeadDays);
			}
			if ($warningLeadDays !== null) {
				$this->settings->setWarningLeadDays($warningLeadDays);
			}
			if ($releaseLeadDays !== null) {
				$this->settings->setReleaseLeadDays($releaseLeadDays);
			}
			return new DataResponse([
				'prenotificationLeadDays' => $this->settings->prenotificationLeadDays(),
				'warningLeadDays' => $this->settings->warningLeadDays(),
				'releaseLeadDays' => $this->settings->releaseLeadDays(),
			]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Überschreibungen immer als JSON-Objekt: json_encode machte aus [0 => 5]
	 * eine Liste `[5]`, aus [1 => 31] aber `{"1":31}` – die Form hinge sonst an
	 * den Daten.
	 *
	 * @param array<int,int> $overrides
	 */
	private static function overridesJson(array $overrides): object {
		return (object)$overrides;
	}

	private function today(): string {
		return $this->time->getDateTime()->format('Y-m-d');
	}
}
