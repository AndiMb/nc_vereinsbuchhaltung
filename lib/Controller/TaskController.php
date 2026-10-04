<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\AnonymizationCandidateService;
use OCA\Vereinsbuchhaltung\Service\ClaimFollowUpTaskService;
use OCA\Vereinsbuchhaltung\Service\ContributionCycleTaskService;
use OCA\Vereinsbuchhaltung\Service\DebitBatchTaskService;
use OCA\Vereinsbuchhaltung\Service\DunningTaskService;
use OCA\Vereinsbuchhaltung\Service\MandateActivationService;
use OCA\Vereinsbuchhaltung\Service\MandateTaskService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\TaskService;
use OCA\Vereinsbuchhaltung\Service\TaskTargetResolver;
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
 * (Spec §3.8/§7 „Anonymisierungs-Vorschlag"). Issue #117 vervollständigt den
 * Katalog aus Spec §7: {@see MandateTaskService} (Mandat im Entwurf auf Papier,
 * gesperrt, erloschen bei weiter gewollter Lastschrift, ohne Nachweis,
 * verfällt bald, ausgetreten mit offenen Forderungen) und
 * {@see ClaimFollowUpTaskService} (aggregiert: Rücklastschrift ohne
 * Wiedereinzug, Forderungen nach Widerruf offen). Persistierte Hinweise
 * kommen über {@see TaskService::findCurrent()} und lösen sich dort von selbst
 * auf.
 *
 * Jede Aufgabe trägt zusätzlich `memberId` (?int) als Sprungziel für die
 * Oberfläche (Aufgaben-Flyout, Issue #99), siehe {@see TaskTargetResolver}.
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
		private MandateTaskService $mandateTasks,
		private ClaimFollowUpTaskService $claimFollowUps,
		private TaskTargetResolver $targets,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function index(): DataResponse {
		$tasks = array_map(static fn ($t) => $t->jsonSerialize(), $this->service->findCurrent());
		foreach ($this->mandateActivation->findStaleElectronicDraftTasks() as $i => $task) {
			// Synthetische, stabile id fuer Frontend-Listenschluessel - diese
			// Eintraege sind nie in vbh_tasks persistiert (siehe Klassendoc).
			$tasks[] = $task + ['id' => 'mandate-activation-' . $task['objectId'] . '-' . $i, 'createdAt' => null];
		}
		// Das allgemeine „kein einzugsfähiges Mandat“ nur dort, wo keine genauere Mandat-Aufgabe
		// die Ursache nennt - sonst stünde dasselbe Problem zweimal in der Liste.
		foreach ($this->contributionCycle->findTasks(null, true) as $i => $task) {
			$tasks[] = $task + ['id' => 'contribution-cycle-' . ($task['objectId'] ?? 'run') . '-' . $i, 'createdAt' => null];
		}
		foreach ($this->mandateTasks->findTasks() as $i => $task) {
			$tasks[] = $task + ['id' => 'mandate-' . $task['objectId'] . '-' . $i, 'createdAt' => null];
		}
		foreach ($this->claimFollowUps->findTasks() as $i => $task) {
			$tasks[] = $task + ['id' => 'claim-follow-up-' . $i, 'createdAt' => null];
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
		return new DataResponse($this->targets->withMemberIds($tasks));
	}
}
