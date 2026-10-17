<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Service\BudgetOverviewService;
use OCA\Vereinsbuchhaltung\Service\BudgetSnapshotService;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

class BudgetController extends Controller {

	use BookContext;

	public function __construct(
		IRequest $request,
		private BudgetMapper $budgetMapper,
		private BudgetOverviewService $overview,
		private BudgetSnapshotService $snapshotService,
		private PeriodService $periods,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Finanzplan eines Geschäftsjahres als Planbaum: je Erfolgskonto Plan und
	 * Ist – eigen und über die Unterkonten summiert (Σ) – samt Sperren und
	 * Auffälligkeiten (siehe BudgetTree). `diff` ist Ist Σ − Plan Σ, oder null
	 * unter einem Planposten.
	 */
	#[NoAdminRequired]
	public function index(?int $period = null): DataResponse {
		$data = $this->overview->build($this->userId(), $period);
		$p = $data['period'];
		$accounts = $data['accounts'];

		$rows = [];
		foreach ($data['nodes'] as $node) {
			$account = $accounts[$node['id']];
			$locked = $node['planLockedBy'] !== null ? ($accounts[$node['planLockedBy']] ?? null) : null;
			$rows[] = [
				'accountId' => $node['id'],
				'number' => $account->getNumber(),
				'name' => $account->getName(),
				'type' => $account->getType(),
				'category' => $account->getCategory(),
				'plan' => $node['own']['plan'] / 100,
				'note' => $data['notes'][$node['id']] ?? '',
				'actual' => $node['own']['actual'] / 100,
				'planSum' => $node['sum']['plan'] / 100,
				'actualSum' => $node['sum']['actual'] / 100,
				// null: Unterkonto eines Planpostens, verglichen wird dort.
				'diff' => $node['comparable'] ? ($node['sum']['actual'] - $node['sum']['plan']) / 100 : null,
				'treeParentId' => $node['treeParentId'],
				'depth' => $node['depth'],
				'hasChildren' => $node['hasChildren'],
				'isPlanItem' => $node['isPlanItem'],
				'planBelow' => $node['firstPlanBelow'] !== null,
				'expanded' => $node['expanded'],
				'planLockedBy' => $locked === null ? null : [
					'accountId' => (int)$locked->getId(),
					'number' => $locked->getNumber(),
					'name' => $locked->getName(),
				],
				'flags' => $node['flags'],
			];
		}

		$t = $data['totals'];
		return new DataResponse([
			'periodId' => (int)$p->getId(),
			'label' => $p->getLabel(),
			'startDate' => $p->getStartDate(),
			'endDate' => $p->getEndDate(),
			'rows' => $rows,
			'conflictCount' => $data['conflictCount'],
			'totals' => [
				'planIncome' => $t['planIncome'] / 100,
				'actualIncome' => $t['actualIncome'] / 100,
				'planExpense' => $t['planExpense'] / 100,
				'actualExpense' => $t['actualExpense'] / 100,
				'planResult' => ($t['planIncome'] - $t['planExpense']) / 100,
				'actualResult' => ($t['actualIncome'] - $t['actualExpense']) / 100,
			],
		]);
	}

	/**
	 * Planwert eines Kontos für einen Zeitraum setzen (Betrag in Euro),
	 * optional mit Notiz. Sind Betrag UND Notiz leer, wird der Eintrag entfernt.
	 *
	 * Je Zweig gibt es eine Planungsebene: Ist auf einem Vor- oder Nachfahren
	 * schon geplant, wird ein Betrag ≠ 0 mit 409 abgelehnt. 0 geht immer (so
	 * lassen sich Altkonflikte bereinigen), ebenso eine reine Notizänderung
	 * bei unverändertem Betrag.
	 */
	#[NoAdminRequired]
	public function set(int $accountId, int $period, float $amount = 0, string $note = ''): DataResponse {
		// Der einzige Schreibpfad, der eine Perioden-ID aus dem Request
		// übernimmt: ohne diese Prüfung entstünde bei einer veralteten oder
		// erfundenen ID ein Planwert, den keine Ansicht je zeigt und den weder
		// das Entfernen eines Zeitraums noch eine Umstellung wiederfindet.
		// Eine unbekannte ID endet als 404 (PeriodNotFoundException).
		$userId = $this->userId();
		$this->periods->find($userId, $period);
		$cents = (int)round($amount * 100);
		$note = mb_substr(trim($note), 0, 1000);

		if ($cents !== 0) {
			$current = (int)($this->budgetMapper->findByPeriod($userId, $period)[$accountId]['amount'] ?? 0);
			$blocking = $cents !== $current ? $this->overview->blockingPlan($userId, $period, $accountId) : null;
			if ($blocking !== null) {
				return new DataResponse([
					'message' => $this->l10n->t('In diesem Zweig ist bereits auf %1$s %2$s geplant. Je Zweig gibt es nur eine Planungsebene.', [$blocking->getNumber(), $blocking->getName()]),
					'blockingAccountId' => (int)$blocking->getId(),
				], Http::STATUS_CONFLICT);
			}
		}

		$this->budgetMapper->upsert($userId, $accountId, $period, $cents, $note);
		return new DataResponse(['accountId' => $accountId, 'periodId' => $period, 'amount' => $cents / 100, 'note' => $note]);
	}

	/**
	 * Gespeicherte Finanzplan-Stände eines Zeitraums (neueste zuerst).
	 */
	#[NoAdminRequired]
	public function snapshots(?int $period = null): DataResponse {
		$userId = $this->userId();
		$p = $this->periods->selectedOrCurrent($userId, $period);
		return new DataResponse($this->snapshotService->listForPeriod($userId, (int)$p->getId()));
	}

	/**
	 * Ein Stand mit allen eingefrorenen Positionen.
	 */
	#[NoAdminRequired]
	public function snapshot(int $id): DataResponse {
		$detail = $this->snapshotService->getDetail($this->userId(), $id);
		if ($detail === null) {
			return new DataResponse(['message' => $this->l10n->t('Stand nicht gefunden.')], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse($detail);
	}

	/**
	 * Aktuellen Finanzplan eines Zeitraums als neuen Stand einfrieren.
	 */
	#[NoAdminRequired]
	public function createSnapshot(?int $period = null, string $label = ''): DataResponse {
		$userId = $this->userId();
		$p = $this->periods->selectedOrCurrent($userId, $period);
		$snapshot = $this->snapshotService->create($userId, (int)$p->getId(), $label);
		return new DataResponse($snapshot);
	}

	/**
	 * Einen Stand löschen.
	 */
	#[NoAdminRequired]
	public function deleteSnapshot(int $id): DataResponse {
		if (!$this->snapshotService->delete($this->userId(), $id)) {
			return new DataResponse(['message' => $this->l10n->t('Stand nicht gefunden.')], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse(['id' => $id]);
	}
}
