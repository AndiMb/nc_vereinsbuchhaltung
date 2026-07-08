<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetSnapshot;
use OCA\Vereinsbuchhaltung\Db\BudgetSnapshotItem;
use OCA\Vereinsbuchhaltung\Db\BudgetSnapshotItemMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetSnapshotMapper;

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
	) {
	}

	/**
	 * Gespeicherte Stände eines Jahres inkl. Kurz-Summen für die Liste.
	 *
	 * @return array<int, array>
	 */
	public function listForYear(string $userId, int $year): array {
		$out = [];
		foreach ($this->snapshotMapper->findByYear($userId, $year) as $snap) {
			$items = $this->itemMapper->findBySnapshot((int)$snap->getId());
			$out[] = $this->summarize($snap, $items);
		}
		return $out;
	}

	/**
	 * Friert den aktuellen Finanzplan eines Jahres als neuen Stand ein.
	 */
	public function create(string $userId, int $year, string $label): array {
		$label = trim($label);
		if ($label === '') {
			$label = 'Stand ' . date('d.m.Y H:i');
		}
		$label = mb_substr($label, 0, 128);

		$snapshot = new BudgetSnapshot();
		$snapshot->setUserId($userId);
		$snapshot->setYear($year);
		$snapshot->setLabel($label);
		$snapshot->setCreatedAt(new \DateTime());
		$snapshot = $this->snapshotMapper->insert($snapshot);
		$snapshotId = (int)$snapshot->getId();

		$plan = $this->budgetMapper->findByYear($userId, $year);
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

		return $this->summarize($snapshot, $items);
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
		$detail = $this->summarize($snapshot, $items);
		$detail['items'] = array_map(static fn (BudgetSnapshotItem $i) => $i->jsonSerialize(), $items);
		return $detail;
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
	private function summarize(BudgetSnapshot $snapshot, array $items): array {
		$income = 0;
		$expense = 0;
		foreach ($items as $item) {
			if ($item->getAccountType() === 'income') {
				$income += $item->getAmountCents();
			} else {
				$expense += $item->getAmountCents();
			}
		}
		return [
			'id' => (int)$snapshot->getId(),
			'year' => $snapshot->getYear(),
			'label' => $snapshot->getLabel(),
			'createdAt' => $snapshot->getCreatedAt()?->format(\DateTime::ATOM),
			'count' => count($items),
			'planIncome' => $income / 100,
			'planExpense' => $expense / 100,
			'planResult' => ($income - $expense) / 100,
		];
	}
}
