<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetSnapshot;
use OCA\Vereinsbuchhaltung\Db\BudgetSnapshotItem;
use OCA\Vereinsbuchhaltung\Db\BudgetSnapshotItemMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetSnapshotMapper;
use OCA\Vereinsbuchhaltung\Exception\PeriodNotFoundException;

/**
 * Finanzplan-Stände: einen aktuellen Plan als benannten, datierten Stand
 * einfrieren, auflisten, im Detail lesen und löschen.
 */
class BudgetSnapshotService {

	public function __construct(
		private BudgetSnapshotMapper $snapshotMapper,
		private BudgetSnapshotItemMapper $itemMapper,
		private BudgetMapper $budgetMapper,
		private AccountMapper $accountMapper,
		private PeriodService $periods,
	) {
	}

	/**
	 * Gespeicherte Stände eines Zeitraums inkl. Kurz-Summen für die Liste.
	 *
	 * @return array<int, array>
	 */
	public function listForPeriod(string $userId, int $periodId): array {
		$out = [];
		foreach ($this->snapshotMapper->findByPeriod($userId, $periodId) as $snap) {
			$items = $this->itemMapper->findBySnapshot((int)$snap->getId());
			$out[] = $this->summarize($userId, $snap, $items);
		}
		return $out;
	}

	/**
	 * Friert den aktuellen Finanzplan eines Zeitraums als neuen Stand ein.
	 */
	public function create(string $userId, int $periodId, string $label): array {
		$label = trim($label);
		if ($label === '') {
			$label = 'Stand ' . date('d.m.Y H:i');
		}
		$label = mb_substr($label, 0, 128);

		$snapshot = new BudgetSnapshot();
		$snapshot->setUserId($userId);
		$snapshot->setPeriodId($periodId);
		$snapshot->setLabel($label);
		$snapshot->setCreatedAt(new \DateTime());
		$snapshot = $this->snapshotMapper->insert($snapshot);
		$snapshotId = (int)$snapshot->getId();

		$plan = $this->budgetMapper->findByPeriod($userId, $periodId);
		$accounts = [];
		foreach ($this->accountMapper->findAll($userId) as $account) {
			$accounts[$account->getId()] = $account;
		}

		$items = [];
		foreach ($plan as $accountId => $entry) {
			// Nur der Betrag wird eingefroren; die Notiz ist Arbeitsstand des
			// laufenden Plans und gehört nicht zum beschlossenen Zahlenwerk.
			$cents = (int)$entry['amount'];
			$account = $accounts[$accountId] ?? null;
			if ($account === null) {
				continue;
			}
			$type = $account->getType();
			if ($type !== 'income' && $type !== 'expense') {
				continue;
			}
			if ($cents === 0) {
				continue;
			}
			$item = new BudgetSnapshotItem();
			$item->setSnapshotId($snapshotId);
			$item->setAccountId((int)$accountId);
			$item->setAccountNumber($account->getNumber() !== null ? mb_substr((string)$account->getNumber(), 0, 32) : null);
			$item->setAccountName(mb_substr((string)$account->getName(), 0, 255));
			$item->setAccountType($type);
			$item->setAmountCents((int)$cents);
			$items[] = $this->itemMapper->insert($item);
		}

		return $this->summarize($userId, $snapshot, $items);
	}

	/**
	 * Ein Stand mit allen eingefrorenen Positionen – oder null, wenn fremd/weg.
	 */
	public function getDetail(string $userId, int $id): ?array {
		$snapshot = $this->snapshotMapper->findForUser($userId, $id);
		if ($snapshot === null) {
			return null;
		}
		$items = $this->itemMapper->findBySnapshot($id);
		$detail = $this->summarize($userId, $snapshot, $items);
		$detail['items'] = array_map(static fn (BudgetSnapshotItem $i) => $i->jsonSerialize(), $items);
		$detail['tree'] = $this->tree($userId, (int)$snapshot->getPeriodId(), $items);
		return $detail;
	}

	/**
	 * Stand und aktueller Plan als Planbaum (siehe BudgetTree), damit ein
	 * Stand genauso gelesen wird wie der Finanzplan selbst.
	 *
	 * Die Hierarchie kommt aus dem heutigen Kontenplan – ein Stand friert nur
	 * Beträge ein. Positionen inzwischen gelöschter (oder nicht mehr
	 * budgetierbarer) Konten stehen mit eingefrorener Nummer und Bezeichnung
	 * als eigene Zeilen am Ende. Zweige, die in beiden Spalten leer sind,
	 * entfallen.
	 *
	 * @param BudgetSnapshotItem[] $items
	 * @return list<array<string, mixed>>
	 */
	private function tree(string $userId, int $periodId, array $items): array {
		$accounts = [];
		foreach ($this->accountMapper->findAll($userId) as $account) {
			$accounts[(int)$account->getId()] = $account;
		}
		$snapshotCents = [];
		foreach ($items as $item) {
			$snapshotCents[(int)$item->getAccountId()] = (int)$item->getAmountCents();
		}
		$currentCents = [];
		foreach ($this->budgetMapper->findByPeriod($userId, $periodId) as $accountId => $entry) {
			$currentCents[(int)$accountId] = (int)$entry['amount'];
		}

		$nodes = BudgetTree::prune(
			BudgetTree::annotate(
				BudgetTree::build(BudgetTree::describe($accounts), ['snapshot' => $snapshotCents, 'current' => $currentCents]),
				'snapshot',
			),
			['snapshot', 'current'],
		);

		$rows = [];
		$inTree = [];
		foreach ($nodes as $n) {
			$inTree[$n['id']] = true;
			$account = $accounts[$n['id']];
			$rows[] = [
				'accountId' => $n['id'],
				'number' => $account->getNumber(),
				'name' => $account->getName(),
				'type' => $n['type'],
				'treeParentId' => $n['treeParentId'],
				'depth' => $n['depth'],
				'hasChildren' => $n['hasChildren'],
				// Aufgeklappt, solange darunter in einer der beiden Spalten
				// noch etwas steht.
				'expanded' => $n['below']['snapshot'] || $n['below']['current'],
				'deleted' => false,
				'snapshotSum' => $n['sum']['snapshot'] / 100,
				'currentSum' => $n['sum']['current'] / 100,
			];
		}
		foreach ($items as $item) {
			$accountId = (int)$item->getAccountId();
			if (isset($inTree[$accountId])) {
				continue;
			}
			$rows[] = [
				'accountId' => $accountId,
				'number' => $item->getAccountNumber(),
				'name' => $item->getAccountName(),
				'type' => $item->getAccountType(),
				'treeParentId' => null,
				'depth' => 0,
				'hasChildren' => false,
				'expanded' => false,
				'deleted' => !isset($accounts[$accountId]),
				'snapshotSum' => (int)$item->getAmountCents() / 100,
				'currentSum' => ($currentCents[$accountId] ?? 0) / 100,
			];
		}
		return $rows;
	}

	/**
	 * Löscht einen Stand samt Positionen. Gibt false zurück, wenn fremd/weg.
	 */
	public function delete(string $userId, int $id): bool {
		$snapshot = $this->snapshotMapper->findForUser($userId, $id);
		if ($snapshot === null) {
			return false;
		}
		$this->itemMapper->deleteBySnapshot($id);
		$this->snapshotMapper->delete($snapshot);
		return true;
	}

	public function deleteAllForUser(string $userId): void {
		$snapshots = $this->snapshotMapper->findAllForUser($userId);
		$ids = array_map(static fn (BudgetSnapshot $s) => (int)$s->getId(), $snapshots);
		$this->itemMapper->deleteBySnapshotIds($ids);
		$this->snapshotMapper->deleteAllForUser($userId);
	}

	/**
	 * @param BudgetSnapshotItem[] $items
	 */
	private function summarize(string $userId, BudgetSnapshot $snapshot, array $items): array {
		$income = 0;
		$expense = 0;
		foreach ($items as $item) {
			if ($item->getAccountType() === 'income') {
				$income += $item->getAmountCents();
			} else {
				$expense += $item->getAmountCents();
			}
		}
		$periodId = (int)$snapshot->getPeriodId();
		return [
			'id' => (int)$snapshot->getId(),
			'periodId' => $periodId,
			'periodLabel' => $this->periodLabel($userId, $periodId),
			'label' => $snapshot->getLabel(),
			'createdAt' => $snapshot->getCreatedAt()?->format(\DateTime::ATOM),
			'count' => count($items),
			'planIncome' => $income / 100,
			'planExpense' => $expense / 100,
			'planResult' => ($income - $expense) / 100,
		];
	}

	/**
	 * Bezeichnung des Zeitraums, zu dem der Stand gehört.
	 *
	 * Bewusst fehlertolerant: ein Stand überlebt das Löschen seines Zeitraums
	 * als Datensatz, und eine Liste eingefrorener Pläne darf daran nicht
	 * scheitern – ohne Bezeichnung fehlt nur die Beschriftung, die Zahlen
	 * bleiben lesbar.
	 */
	private function periodLabel(string $userId, int $periodId): string {
		try {
			return $this->periods->find($userId, $periodId)->getLabel();
		} catch (PeriodNotFoundException) {
			return '';
		}
	}
}
