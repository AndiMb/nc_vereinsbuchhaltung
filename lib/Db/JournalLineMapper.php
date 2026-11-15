<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<JournalLine>
 */
class JournalLineMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_journal_line', JournalLine::class);
	}

	/**
	 * Distinct Journal-IDs, die mindestens eine Zeile auf einem der Konten haben.
	 *
	 * Optional auf einen Datumsbereich eingegrenzt (ISO-Datum, beide Grenzen
	 * inklusive).
	 *
	 * @param int[] $accountIds
	 * @return int[]
	 */
	public function findJournalIdsForAccounts(string $userId, array $accountIds, ?string $from = null, ?string $to = null): array {
		if (count($accountIds) === 0) {
			return [];
		}
		$ids = [];
		foreach (array_chunk($accountIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->selectDistinct('l.journal_id')
				->from($this->getTableName(), 'l')
				->innerJoin('l', 'vbh_journal', 'j', $qb->expr()->eq('l.journal_id', 'j.id'))
				->where($qb->expr()->eq('j.user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->in('l.account_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			if ($from !== null) {
				$qb->andWhere($qb->expr()->gte('j.date', $qb->createNamedParameter($from)));
			}
			if ($to !== null) {
				$qb->andWhere($qb->expr()->lte('j.date', $qb->createNamedParameter($to)));
			}
			$res = $qb->executeQuery();
			while (($row = $res->fetch()) !== false) {
				$ids[] = (int)$row['journal_id'];
			}
			$res->closeCursor();
		}
		return $ids;
	}

	/**
	 * Summe Soll/Haben auf den Konten für alle Buchungen vor einem Stichtag –
	 * der Saldovortrag des Kontoauszugs, ohne die Vorjahre zu laden.
	 *
	 * @param int[] $accountIds
	 * @param string $before exklusive obere Datumsgrenze (ISO-Datum)
	 * @return array{debit:int, credit:int}
	 */
	public function sumForAccountsBefore(string $userId, array $accountIds, string $before): array {
		$debit = 0;
		$credit = 0;
		foreach (array_chunk($accountIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->selectAlias($qb->func()->sum('l.debit_cents'), 'debit')
				->selectAlias($qb->func()->sum('l.credit_cents'), 'credit')
				->from($this->getTableName(), 'l')
				->innerJoin('l', 'vbh_journal', 'j', $qb->expr()->eq('l.journal_id', 'j.id'))
				->where($qb->expr()->eq('j.user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->in('l.account_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->lt('j.date', $qb->createNamedParameter($before)));
			$res = $qb->executeQuery();
			$row = $res->fetch();
			$res->closeCursor();
			if ($row !== false) {
				$debit += (int)$row['debit'];
				$credit += (int)$row['credit'];
			}
		}
		return ['debit' => $debit, 'credit' => $credit];
	}

	/**
	 * Anzahl Buchungszeilen auf einem Konto– die Prüfung, ob ein Konto noch
	 * bebucht ist. Ohne sie hinterließe das Löschen eines Kontos verwaiste
	 * Zeilen, deren Beträge aus allen Auswertungen verschwinden (die iterieren
	 * über die vorhandenen Konten), während sie in der Datenbank stehen bleiben.
	 */
	public function countByAccount(string $userId, int $accountId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName(), 'l')
			->innerJoin('l', 'vbh_journal', 'j', $qb->expr()->eq('l.journal_id', 'j.id'))
			->where($qb->expr()->eq('j.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('l.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
		$res = $qb->executeQuery();
		$count = (int)$res->fetchOne();
		$res->closeCursor();
		return $count;
	}

	/**
	 * Geschäftsjahre, in denen dieses Konto bebucht ist.
	 *
	 * Grundlage der Festschreibungsprüfung an den Konto-Stammdaten (siehe
	 * AccountService::assertEvaluationOpen()): ob eine Änderung am Konto einen
	 * bereits abgeschlossenen Bericht nachträglich verändern würde, hängt genau
	 * daran, ob das Konto in einem abgeschlossenen Zeitraum überhaupt vorkommt.
	 *
	 * @return int[] Perioden-IDs, aufsteigend
	 */
	public function findPeriodIdsForAccount(string $userId, int $accountId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('j.period_id')
			->from($this->getTableName(), 'l')
			->innerJoin('l', 'vbh_journal', 'j', $qb->expr()->eq('l.journal_id', 'j.id'))
			->where($qb->expr()->eq('j.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('l.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
		$res = $qb->executeQuery();
		$periodIds = [];
		while (($row = $res->fetch()) !== false) {
			$periodId = (int)$row['period_id'];
			if ($periodId > 0) {
				$periodIds[$periodId] = true;
			}
		}
		$res->closeCursor();
		$periodIds = array_keys($periodIds);
		sort($periodIds);
		return $periodIds;
	}

	/**
	 * Alle Zeilen der angegebenen Buchungssätze auf einmal – ersetzt das
	 * Nachladen je Buchung (N+1) in Journal-Liste, Kontoauszug und Export.
	 *
	 * @param int[] $journalIds
	 * @return array<int, JournalLine[]> journalId => Zeilen
	 */
	public function findByJournals(array $journalIds): array {
		if ($journalIds === []) {
			return [];
		}
		$out = [];
		foreach (array_chunk(array_values(array_unique($journalIds)), 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->in('journal_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->orderBy('id', 'ASC');
			foreach ($this->findEntities($qb) as $line) {
				$out[$line->getJournalId()][] = $line;
			}
		}
		return $out;
	}

	/**
	 * @return JournalLine[]
	 */
	public function findByJournal(int $journalId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('journal_id', $qb->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	public function deleteAllForUser(string $userId): void {
		// Journal-IDs des Nutzers ermitteln und Zeilen blockweise löschen
		$sel = $this->db->getQueryBuilder();
		$sel->select('id')->from('vbh_journal')
			->where($sel->expr()->eq('user_id', $sel->createNamedParameter($userId)));
		$res = $sel->executeQuery();
		$ids = [];
		while (($row = $res->fetch()) !== false) {
			$ids[] = (int)$row['id'];
		}
		$res->closeCursor();

		foreach (array_chunk($ids, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->in('journal_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
	}

	public function deleteByJournal(int $journalId): void {
		// Die Verteilung auf Kostenstellen (Issue #7) hängt an den Zeilen und
		// geht mit ihnen – hier, damit kein Aufrufer sie vergessen kann.
		// Mehrere Dienste löschen Zeilen direkt (Eröffnungsbuchung, xbuc-Import);
		// ein zurückgebliebener Eintrag hinge an keiner Buchung mehr und
		// verhinderte das Löschen seiner Kostenstelle für immer.
		(new LineAllocationMapper($this->db))->deleteByJournal($journalId);

		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('journal_id', $qb->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Summen Soll/Haben je Konto für die Saldenliste.
	 *
	 * Optional auf einen Datumsbereich (Geschäftsjahr) eingegrenzt. Das Datum
	 * ist eine DATE-Spalte; die Grenzen werden als ISO-Datum (YYYY-MM-DD)
	 * übergeben und von der Datenbank als Datum verglichen.
	 *
	 * @param string|null $from inklusive untere Datumsgrenze (z.B. 2026-01-01)
	 * @param string|null $to inklusive obere Datumsgrenze (z.B. 2026-12-31)
	 * @return array<int, array{debit:int, credit:int}>
	 */
	public function sumByAccount(string $userId, ?string $from = null, ?string $to = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('l.account_id')
			->selectAlias($qb->func()->sum('l.debit_cents'), 'debit')
			->selectAlias($qb->func()->sum('l.credit_cents'), 'credit')
			->from($this->getTableName(), 'l')
			->innerJoin('l', 'vbh_journal', 'j', $qb->expr()->eq('l.journal_id', 'j.id'))
			->where($qb->expr()->eq('j.user_id', $qb->createNamedParameter($userId)))
			->groupBy('l.account_id');
		if ($from !== null) {
			$qb->andWhere($qb->expr()->gte('j.date', $qb->createNamedParameter($from)));
		}
		if ($to !== null) {
			$qb->andWhere($qb->expr()->lte('j.date', $qb->createNamedParameter($to)));
		}
		$result = $qb->executeQuery();
		$out = [];
		while (($row = $result->fetch()) !== false) {
			$out[(int)$row['account_id']] = [
				'debit' => (int)$row['debit'],
				'credit' => (int)$row['credit'],
			];
		}
		$result->closeCursor();
		return $out;
	}

	/**
	 * Wie {@see sumByAccount()}, aber zusätzlich nach Monat getrennt.
	 *
	 * Die Datenbank gruppiert nach Konto und Buchungstag; den Monat bildet
	 * {@see foldByMonth()} in PHP. Eine Teilstring-Funktion im SQL ginge auf
	 * PostgreSQL nicht: `date` ist eine DATE-Spalte, und substr() gibt es dort
	 * nur für Text. Höchstens Konten × Buchungstage einer Periode Zeilen.
	 *
	 * @param string $from inklusive untere Datumsgrenze
	 * @param string $to inklusive obere Datumsgrenze
	 * @return array<string, array<int, array{debit:int, credit:int}>> Monat (`YYYY-MM`) => Konto-ID => Summen
	 */
	public function sumByAccountAndMonth(string $userId, string $from, string $to): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('l.account_id', 'j.date')
			->selectAlias($qb->func()->sum('l.debit_cents'), 'debit')
			->selectAlias($qb->func()->sum('l.credit_cents'), 'credit')
			->from($this->getTableName(), 'l')
			->innerJoin('l', 'vbh_journal', 'j', $qb->expr()->eq('l.journal_id', 'j.id'))
			->where($qb->expr()->eq('j.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->gte('j.date', $qb->createNamedParameter($from)))
			->andWhere($qb->expr()->lte('j.date', $qb->createNamedParameter($to)))
			->groupBy('l.account_id')
			->addGroupBy('j.date');
		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = $row;
		}
		$result->closeCursor();
		return self::foldByMonth($rows);
	}

	/**
	 * Tagessummen je Konto zu Monatssummen je Konto. Der Monat sind die
	 * ersten sieben Zeichen des Datums – gleich, ob der Treiber `2026-03-05`
	 * oder `2026-03-05 00:00:00` liefert. Öffentlich für den Unit-Test.
	 *
	 * @param iterable<array{account_id:int|string, date:string, debit:int|string|null, credit:int|string|null}> $rows
	 * @return array<string, array<int, array{debit:int, credit:int}>> Monat => Konto-ID => Summen
	 */
	public static function foldByMonth(iterable $rows): array {
		$out = [];
		foreach ($rows as $row) {
			$month = substr((string)$row['date'], 0, 7);
			$accountId = (int)$row['account_id'];
			$out[$month][$accountId] ??= ['debit' => 0, 'credit' => 0];
			$out[$month][$accountId]['debit'] += (int)$row['debit'];
			$out[$month][$accountId]['credit'] += (int)$row['credit'];
		}
		return $out;
	}
}
