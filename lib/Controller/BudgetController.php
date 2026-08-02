<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Service\BudgetSnapshotService;
use OCA\Vereinsbuchhaltung\Service\FiscalYear;
use OCA\Vereinsbuchhaltung\Service\LedgerAggregator;
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
		private AccountMapper $accountMapper,
		private BudgetMapper $budgetMapper,
		private JournalLineMapper $lineMapper,
		private BudgetSnapshotService $snapshotService,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Finanzplan eines Jahres: je Erfolgskonto Plan (Soll) und Ist sowie Differenz.
	 */
	#[NoAdminRequired]
	public function index(?int $year = null): DataResponse {
		$year = FiscalYear::orCurrent($year);
		$userId = $this->userId();
		$from = FiscalYear::start($year);
		$to = FiscalYear::end($year);

		$accounts = $this->accountMapper->findAll($userId);
		$plan = $this->budgetMapper->findByYear($userId, $year);
		$actualSums = $this->lineMapper->sumByAccount($userId, $from, $to);

		$plan = LedgerAggregator::planActual($accounts, $actualSums, $plan);

		$rows = [];
		foreach ($plan['rows'] as $row) {
			$account = $row['account'];
			$rows[] = [
				'accountId' => $account->getId(),
				'number' => $account->getNumber(),
				'name' => $account->getName(),
				'type' => $account->getType(),
				'category' => $account->getCategory(),
				'plan' => $row['planCents'] / 100,
				'note' => $row['note'],
				'actual' => $row['actualCents'] / 100,
				'diff' => ($row['actualCents'] - $row['planCents']) / 100,
			];
		}

		usort($rows, static fn ($a, $b) => strcmp((string)$a['number'], (string)$b['number']));

		return new DataResponse([
			'year' => $year,
			'rows' => $rows,
			'totals' => [
				'planIncome' => $plan['planIncomeCents'] / 100,
				'actualIncome' => $plan['actualIncomeCents'] / 100,
				'planExpense' => $plan['planExpenseCents'] / 100,
				'actualExpense' => $plan['actualExpenseCents'] / 100,
				'planResult' => ($plan['planIncomeCents'] - $plan['planExpenseCents']) / 100,
				'actualResult' => ($plan['actualIncomeCents'] - $plan['actualExpenseCents']) / 100,
			],
		]);
	}

	/**
	 * Planwert eines Kontos für ein Jahr setzen (Betrag in Euro), optional mit
	 * Notiz. Sind Betrag UND Notiz leer, wird der Eintrag entfernt.
	 */
	#[NoAdminRequired]
	public function set(int $accountId, int $year, float $amount = 0, string $note = ''): DataResponse {
		$cents = (int)round($amount * 100);
		$note = mb_substr(trim($note), 0, 1000);
		$this->budgetMapper->upsert($this->userId(), $accountId, $year, $cents, $note);
		return new DataResponse(['accountId' => $accountId, 'year' => $year, 'amount' => $cents / 100, 'note' => $note]);
	}

	/**
	 * Gespeicherte Finanzplan-Stände eines Jahres (neueste zuerst).
	 */
	#[NoAdminRequired]
	public function snapshots(?int $year = null): DataResponse {
		$year = FiscalYear::orCurrent($year);
		return new DataResponse($this->snapshotService->listForYear($this->userId(), $year));
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
	 * Aktuellen Finanzplan eines Jahres als neuen Stand einfrieren.
	 */
	#[NoAdminRequired]
	public function createSnapshot(int $year, string $label = ''): DataResponse {
		$snapshot = $this->snapshotService->create($this->userId(), $year, $label);
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
