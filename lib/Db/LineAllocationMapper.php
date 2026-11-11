<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Verteilung von Buchungszeilen auf Kostenstellen (Issue #7).
 *
 * Die Tabelle hängt über `line_id` an `vbh_journal_line`. Fremdschlüssel
 * gibt es in dieser App nicht; wer Zeilen löscht, räumt die Verteilung
 * vorher ab (siehe LineAllocationWriter).
 *
 * @extends QBMapper<LineAllocation>
 */
class LineAllocationMapper extends QBMapper {
	private const CHUNK = 500;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_line_alloc', LineAllocation::class);
	}

	/**
	 * @return array<int, list<array{costCenterId:int, amountCents:int}>> Zeilen-ID => Anteile
	 */
	public function findByJournal(int $journalId): array {
		return $this->findByJournals([$journalId]);
	}

	/**
	 * Verteilungen vieler Buchungssätze in einem Rutsch (Journal-Liste, Export).
	 *
	 * @param int[] $journalIds
	 * @return array<int, list<array{costCenterId:int, amountCents:int}>> Zeilen-ID => Anteile
	 */
	public function findByJournals(array $journalIds): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($journalIds)), self::CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('a.line_id', 'a.cost_center_id', 'a.amount_cents')
				->from($this->getTableName(), 'a')
				->innerJoin('a', 'vbh_journal_line', 'l', $qb->expr()->eq('a.line_id', 'l.id'))
				->where($qb->expr()->in('l.journal_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->orderBy('a.id', 'ASC');
			$result = $qb->executeQuery();
			while (($row = $result->fetch()) !== false) {
				$out[(int)$row['line_id']][] = [
					'costCenterId' => (int)$row['cost_center_id'],
					'amountCents' => (int)$row['amount_cents'],
				];
			}
			$result->closeCursor();
		}
		return $out;
	}

	public function insertFor(int $lineId, int $costCenterId, int $amountCents): void {
		$allocation = new LineAllocation();
		$allocation->setLineId($lineId);
		$allocation->setCostCenterId($costCenterId);
		$allocation->setAmountCents($amountCents);
		$this->insert($allocation);
	}

	/**
	 * Verteilung aller Zeilen eines Buchungssatzes löschen. Muss laufen,
	 * solange die Zeilen noch da sind – über sie wird die Verteilung gefunden.
	 */
	public function deleteByJournal(int $journalId): void {
		$sel = $this->db->getQueryBuilder();
		$sel->select('id')->from('vbh_journal_line')
			->where($sel->expr()->eq('journal_id', $sel->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)));
		$result = $sel->executeQuery();
		$lineIds = [];
		while (($row = $result->fetch()) !== false) {
			$lineIds[] = (int)$row['id'];
		}
		$result->closeCursor();

		foreach (array_chunk($lineIds, self::CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->in('line_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
	}

	public function deleteByLine(int $lineId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('line_id', $qb->createNamedParameter($lineId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function countByCostCenter(int $costCenterId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('cost_center_id', $qb->createNamedParameter($costCenterId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/** Beim Zurücksetzen des Buchungsbestands (siehe ResetService): die Zeilen, zu denen sie gehören, sind weg. */
	public function deleteAll(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName());
		$qb->executeStatement();
	}

	/**
	 * Verteilte Beträge je Kostenstelle und Konto, getrennt nach der Seite
	 * der Buchungszeile.
	 *
	 * Zwei Abfragen (Soll-Zeilen, Haben-Zeilen) statt einer mit CASE: so
	 * bleibt es bei SUM und GROUP BY, die auf MySQL, PostgreSQL und SQLite
	 * dasselbe liefern.
	 *
	 * @param string|null $from inklusive untere Datumsgrenze (YYYY-MM-DD)
	 * @param string|null $to inklusive obere Datumsgrenze
	 * @return list<array{costCenterId:int, accountId:int, debit:int, credit:int}>
	 */
	public function sumByCostCenterAndAccount(string $userId, ?string $from = null, ?string $to = null): array {
		$sums = [];
		foreach (['debit' => 'l.debit_cents', 'credit' => 'l.credit_cents'] as $side => $column) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('a.cost_center_id', 'l.account_id')
				->selectAlias($qb->func()->sum('a.amount_cents'), 'amount')
				->from($this->getTableName(), 'a')
				->innerJoin('a', 'vbh_journal_line', 'l', $qb->expr()->eq('a.line_id', 'l.id'))
				->innerJoin('l', 'vbh_journal', 'j', $qb->expr()->eq('l.journal_id', 'j.id'))
				->where($qb->expr()->eq('j.user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->gt($column, $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
				->groupBy('a.cost_center_id')
				->addGroupBy('l.account_id');
			if ($from !== null) {
				$qb->andWhere($qb->expr()->gte('j.date', $qb->createNamedParameter($from)));
			}
			if ($to !== null) {
				$qb->andWhere($qb->expr()->lte('j.date', $qb->createNamedParameter($to)));
			}
			$result = $qb->executeQuery();
			while (($row = $result->fetch()) !== false) {
				$key = (int)$row['cost_center_id'] . ':' . (int)$row['account_id'];
				$sums[$key] ??= [
					'costCenterId' => (int)$row['cost_center_id'],
					'accountId' => (int)$row['account_id'],
					'debit' => 0,
					'credit' => 0,
				];
				$sums[$key][$side] = (int)$row['amount'];
			}
			$result->closeCursor();
		}
		return array_values($sums);
	}
}
