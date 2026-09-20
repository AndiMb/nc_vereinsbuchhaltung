<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\AnonymizationCandidateService;
use OCA\Vereinsbuchhaltung\Service\ContributionCycleTaskService;
use OCA\Vereinsbuchhaltung\Service\DebitBatchTaskService;
use OCA\Vereinsbuchhaltung\Service\DunningTaskService;
use OCA\Vereinsbuchhaltung\Service\MandateActivationService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\TaskService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Aufgaben/Störfälle (siehe {@see \OCA\Vereinsbuchhaltung\Db\Task}). Gleiche
 * Einstufung wie die Mitglieder-Unterreiter (ab Buchhalter, nicht Revisor):
 * die Meldungen nennen Mitgliedsnamen und teils Mailadressen.
 *
 * Mischt PERSISTIERTE Aufgaben (TaskService) mit ABGELEITETEN Abfragen aus
 * Fachdiensten - Issue #67 bringt mit
 * {@see MandateActivationService::findStaleElectronicDraftTasks()} die erste
 * solche Abfrage ein (siehe dortige Klassendoc, warum keine eigene Tabelle
 * nötig ist). Issue #70 hängt mit {@see ContributionCycleTaskService} den
 * Einzugszyklus (Vorwarnfenster, fehlendes Mandat, gerissene Vorlauffrist,
 * überfällige Überweiser-Forderungen) nach demselben Muster ein, Issue #71
 * ergänzt mit {@see DebitBatchTaskService} „Freigabe fällig"/„Einreichung
 * überfällig" (Spec §7). Issue #73 ergänzt mit {@see DunningTaskService}
 * „Mahnstufe an Vorstand eskaliert" nach demselben Muster. Issue #78 ergänzt
 * mit {@see AnonymizationCandidateService} „Mitglied X anonymisierungsreif"
 * (Spec §3.8/§7 „Anonymisierungs-Vorschlag").
 */
class TaskController extends Controller {

	public function __construct(
		IRequest $request,
		private TaskService $service,
		private MandateActivationService $mandateActivation,
		private ContributionCycleTaskService $contributionCycle,
		private DebitBatchTaskService $debitBatchTasks,
		private DunningTaskService $dunningTasks,
		private AnonymizationCandidateService $anonymizationCandidates,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function index(): DataResponse {
		$tasks = array_map(static fn ($t) => $t->jsonSerialize(), $this->service->findAll());
		foreach ($this->mandateActivation->findStaleElectronicDraftTasks() as $i => $task) {
			// Synthetische, stabile id fuer Frontend-Listenschluessel - diese
			// Eintraege sind nie in vbh_tasks persistiert (siehe Klassendoc).
			$tasks[] = $task + ['id' => 'mandate-activation-' . $task['objectId'] . '-' . $i, 'createdAt' => null];
		}
		foreach ($this->contributionCycle->findTasks() as $i => $task) {
			$tasks[] = $task + ['id' => 'contribution-cycle-' . ($task['objectId'] ?? 'run') . '-' . $i, 'createdAt' => null];
		}
		foreach ($this->debitBatchTasks->findTasks() as $i => $task) {
			$tasks[] = $task + ['id' => 'debit-batch-' . ($task['objectId'] ?? 'run') . '-' . $i, 'createdAt' => null];
		}
		foreach ($this->dunningTasks->findBoardEscalationTasks() as $i => $task) {
			$tasks[] = $task + ['id' => 'dunning-' . ($task['objectId'] ?? 'run') . '-' . $i, 'createdAt' => null];
		}
		foreach ($this->anonymizationCandidates->findTasks() as $i => $task) {
			$tasks[] = $task + ['id' => 'anonymization-' . ($task['objectId'] ?? 'run') . '-' . $i, 'createdAt' => null];
		}
		return new DataResponse($tasks);
	}
}
