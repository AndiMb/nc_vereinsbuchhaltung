<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\TaskController;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\DunningNoticeMapper;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\Task;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\AnonymizationCandidateService;
use OCA\Vereinsbuchhaltung\Service\ClaimFollowUpTaskService;
use OCA\Vereinsbuchhaltung\Service\ContributionCycleTaskService;
use OCA\Vereinsbuchhaltung\Service\DebitBatchTaskService;
use OCA\Vereinsbuchhaltung\Service\DunningSettings;
use OCA\Vereinsbuchhaltung\Service\DunningTaskService;
use OCA\Vereinsbuchhaltung\Service\MandateActivationService;
use OCA\Vereinsbuchhaltung\Service\MandateTaskService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\TaskService;
use OCA\Vereinsbuchhaltung\Service\TaskTargetResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Verdrahtung von `GET /api/tasks` (Issue #117): alle Quellen des Aufgaben-
 * Katalogs landen in EINER Liste, jede Zeile hat einen eindeutigen Schlüssel
 * (die Oberfläche nutzt ihn als Listenschlüssel), und die Rechtelinie bleibt
 * `buchhalter` – die Meldungen nennen Mitglieder.
 */
class TaskControllerTest extends TestCase {

	private TaskService&MockObject $persisted;
	private MandateActivationService&MockObject $mandateActivation;
	private ContributionCycleTaskService&MockObject $contributionCycle;
	private DebitBatchTaskService&MockObject $debitBatch;
	private AnonymizationCandidateService&MockObject $anonymization;
	private MandateTaskService&MockObject $mandateTasks;
	private ClaimFollowUpTaskService&MockObject $claimFollowUps;
	private MemberMapper&MockObject $members;

	protected function setUp(): void {
		$this->persisted = $this->createMock(TaskService::class);
		$this->mandateActivation = $this->createMock(MandateActivationService::class);
		$this->contributionCycle = $this->createMock(ContributionCycleTaskService::class);
		$this->debitBatch = $this->createMock(DebitBatchTaskService::class);
		$this->anonymization = $this->createMock(AnonymizationCandidateService::class);
		$this->mandateTasks = $this->createMock(MandateTaskService::class);
		$this->claimFollowUps = $this->createMock(ClaimFollowUpTaskService::class);
		$this->members = $this->createMock(MemberMapper::class);
	}

	private function controller(): TaskController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-10-04 12:00:00'));
		// Beide Klassen sind final und werden deshalb mit gemockten Zuträgern gebaut.
		$dunning = new DunningTaskService($this->createMock(OpenItemMapper::class), $this->createMock(DunningNoticeMapper::class), $this->members, new DunningSettings($this->createMock(IConfig::class)), $time, $l10n);
		$targets = new TaskTargetResolver($this->members, $this->createMock(MandateMapper::class), $this->createMock(AssignmentMapper::class), $this->createMock(OpenItemMapper::class));

		return new TaskController(
			$this->createMock(IRequest::class),
			$this->persisted,
			$this->mandateActivation,
			$this->contributionCycle,
			$this->debitBatch,
			$dunning,
			$this->anonymization,
			$this->mandateTasks,
			$this->claimFollowUps,
			$targets,
		);
	}

	private function declaredRole(string $method): ?string {
		$attributes = (new \ReflectionMethod(TaskController::class, $method))->getAttributes(RequiresRole::class);
		return $attributes === [] ? null : $attributes[0]->newInstance()->role;
	}

	public function testDieAufgabenlisteBleibtAbBuchhalterLesbar(): void {
		$this->assertSame(PermissionService::ROLE_WRITE, $this->declaredRole('index'));
	}

	public function testAlleQuellenDesKatalogsLandenInEinerListeMitEindeutigenSchluesseln(): void {
		$persisted = new Task();
		$persisted->setId(5);
		$persisted->setSeverity(Task::SEVERITY_HINT);
		$persisted->setMessage('NC-Konto gelöscht');
		$persisted->setObjectType('member');
		$persisted->setObjectId(7);
		$persisted->setCreatedAt('2026-10-01 08:00:00');
		$this->persisted->method('findCurrent')->willReturn([$persisted]);
		$this->members->method('findOrNull')->willReturnCallback(static function (int $id): Member {
			$member = new Member();
			$member->setId($id);
			return $member;
		});

		$this->mandateActivation->method('findStaleElectronicDraftTasks')->willReturn([
			['severity' => Task::SEVERITY_HINT, 'message' => 'E-Link', 'objectType' => 'mandate', 'objectId' => 3],
		]);
		$this->contributionCycle->method('findTasks')->willReturn([
			['severity' => Task::SEVERITY_HINT, 'message' => 'Überweiser', 'objectType' => null, 'objectId' => null],
		]);
		// Zwei Aufgaben zum selben Mandat: der Schlüssel darf nicht kollidieren.
		$this->mandateTasks->method('findTasks')->willReturn([
			['severity' => Task::SEVERITY_ACTION_REQUIRED, 'message' => 'Entwurf', 'objectType' => 'mandate', 'objectId' => 3, 'memberId' => 7],
			['severity' => Task::SEVERITY_HINT, 'message' => 'Nachweis', 'objectType' => 'mandate', 'objectId' => 3, 'memberId' => 7],
		]);
		$this->claimFollowUps->method('findTasks')->willReturn([
			['severity' => Task::SEVERITY_HINT, 'message' => 'Rücklastschrift', 'objectType' => 'claims', 'objectId' => null, 'memberId' => null],
		]);
		$this->debitBatch->method('findTasks')->willReturn([
			['severity' => Task::SEVERITY_ACTION_REQUIRED, 'message' => 'Freigabe', 'objectType' => null, 'objectId' => null],
		]);
		$this->anonymization->method('findTasks')->willReturn([
			['severity' => Task::SEVERITY_HINT, 'message' => 'reif', 'objectType' => 'member', 'objectId' => 9],
		]);

		$tasks = $this->controller()->index()->getData();

		$this->assertSame(
			['NC-Konto gelöscht', 'E-Link', 'Überweiser', 'Entwurf', 'Nachweis', 'Rücklastschrift', 'Freigabe', 'reif'],
			array_column($tasks, 'message'),
		);
		$ids = array_column($tasks, 'id');
		$this->assertCount(count($ids), array_unique($ids), 'Listenschlüssel müssen eindeutig sein');
		// Jede Zeile trägt memberId (null, wo kein Mitglied betroffen ist).
		foreach ($tasks as $task) {
			$this->assertArrayHasKey('memberId', $task);
		}
		// Persistierter Hinweis wird aufgelöst, die Mandat-Aufgaben bringen ihre memberId selbst mit.
		$this->assertSame(7, $tasks[0]['memberId']);
		$this->assertSame([7, 7], [$tasks[3]['memberId'], $tasks[4]['memberId']]);
		$this->assertNull($tasks[5]['memberId']);
		// Der Anonymisierungs-Hinweis kennt nur das Mitglied und wird wie bisher aufgelöst.
		$this->assertSame(9, $tasks[7]['memberId']);
	}

	public function testPersistierteHinweiseKommenAusDerAufgeloestenListe(): void {
		// Nicht alle Zeilen der Tabelle: abgelaufene Ereignis-Hinweise sollen nicht mehr erscheinen.
		$this->persisted->expects($this->once())->method('findCurrent')->willReturn([]);

		$this->assertSame([], $this->controller()->index()->getData());
	}

	public function testDieAufgabenlisteUnterdruecktDieAllgemeineKeinMandatZeileWoEineGenauereAufgabeExistiert(): void {
		// Sonst stünde derselbe Entwurf/dieselbe Sperre zweimal in der Liste und doppelt im Badge.
		$this->contributionCycle->expects($this->once())->method('findTasks')->with(null, true)->willReturn([]);

		$this->controller()->index();
	}
}
