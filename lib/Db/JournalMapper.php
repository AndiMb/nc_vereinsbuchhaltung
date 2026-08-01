<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCA\Vereinsbuchhaltung\Service\FiscalYear;
use OCA\Vereinsbuchhaltung\Service\Statement\RowNormalizer;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Journal>
 */
class JournalMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_journal', Journal::class);
	}

	public function find(int $id, string $userId): Journal {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	public const OPENING_REF = 'EB';

	public function deleteAllForUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/**
	 * Nächste fortlaufende Buchungsnummer für den Nutzer (global, für Migrationen).
	 */
	public function getNextEntryNo(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->max('entry_no'), 'm')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$res = $qb->executeQuery();
		$max = $res->fetchOne();
		$res->closeCursor();
		return (int)$max + 1;
	}

	/**
	 * Nächste fortlaufende Buchungsnummer innerhalb eines Kalenderjahres.
	 * Buchungsnummern starten je Jahr bei 1.
	 */
	public function getNextEntryNoForYear(string $userId, int $year): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->max('entry_no'), 'm')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)));
		$res = $qb->executeQuery();
		$max = $res->fetchOne();
		$res->closeCursor();
		return (int)$max + 1;
	}

	/**
	 * ID und aktuelle Buchungsnummer aller Buchungen eines Geschäftsjahres,
	 * aufsteigend nach bisheriger Nummer (dann ID) sortiert – Grundlage der
	 * Nachnummerierung in {@see \OCA\Vereinsbuchhaltung\Service\EntryNumberService}.
	 *
	 * Sortiert wird in PHP: bei Alt-Daten kann entry_no NULL sein, und die
	 * Datenbanken ordnen NULL unterschiedlich ein (MySQL zuerst, PostgreSQL
	 * zuletzt).
	 *
	 * @return array<int, array{id:int, entryNo:int}>
	 */
	public function findEntryNosForYear(string $userId, int $year): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'entry_no')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)));
		$res = $qb->executeQuery();
		$rows = [];
		while (($row = $res->fetch()) !== false) {
			$rows[] = ['id' => (int)$row['id'], 'entryNo' => (int)($row['entry_no'] ?? 0)];
		}
		$res->closeCursor();

		usort($rows, static fn (array $a, array $b): int => [$a['entryNo'], $a['id']] <=> [$b['entryNo'], $b['id']]);
		return $rows;
	}

	/** Setzt die Buchungsnummer eines einzelnen Buchungssatzes. */
	public function setEntryNo(int $id, int $entryNo): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('entry_no', $qb->createNamedParameter($entryNo, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Findet die Eröffnungsbuchung (document_ref = 'EB'), die das angegebene
	 * Konto berührt – falls vorhanden.
	 */
	public function findOpeningForAccount(string $userId, int $accountId): ?Journal {
		$qb = $this->db->getQueryBuilder();
		$qb->select('j.*')
			->from($this->getTableName(), 'j')
			->innerJoin('j', 'vbh_journal_line', 'l', $qb->expr()->eq('l.journal_id', 'j.id'))
			->where($qb->expr()->eq('j.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('j.document_ref', $qb->createNamedParameter(self::OPENING_REF)))
			->andWhere($qb->expr()->eq('l.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/**
	 * Distinct Journal-IDs, die in einem Kalenderjahr mindestens eine Zeile auf
	 * einem der angegebenen Konten haben (z.B. Eigenkapital-/EB-Konten, um
	 * Eröffnungsbuchungen eines Jahres zu finden).
	 *
	 * @param int[] $accountIds
	 * @return int[]
	 */
	public function findBookingIdsTouchingAccountsInYear(string $userId, array $accountIds, int $year): array {
		if (count($accountIds) === 0) {
			return [];
		}
		$from = FiscalYear::start($year);
		$to = FiscalYear::end($year);
		$ids = [];
		foreach (array_chunk($accountIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->selectDistinct('j.id')
				->from($this->getTableName(), 'j')
				->innerJoin('j', 'vbh_journal_line', 'l', $qb->expr()->eq('l.journal_id', 'j.id'))
				->where($qb->expr()->eq('j.user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->gte('j.date', $qb->createNamedParameter($from)))
				->andWhere($qb->expr()->lte('j.date', $qb->createNamedParameter($to)))
				->andWhere($qb->expr()->in('l.account_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$res = $qb->executeQuery();
			while (($row = $res->fetch()) !== false) {
				$ids[] = (int)$row['id'];
			}
			$res->closeCursor();
		}
		return $ids;
	}

	/**
	 * Mehrere Buchungssätze auf einmal – ersetzt das Nachladen je ID (N+1)
	 * im Kontoauszug.
	 *
	 * @param int[] $ids
	 * @return array<int, Journal> id => Buchungssatz
	 */
	public function findByIds(string $userId, array $ids): array {
		if ($ids === []) {
			return [];
		}
		$out = [];
		foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($this->findEntities($qb) as $journal) {
				$out[$journal->getId()] = $journal;
			}
		}
		return $out;
	}

	/**
	 * @return Journal[]
	 */
	public function findAll(string $userId, int $limit = 500, int $offset = 0, ?string $from = null, ?string $to = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('date', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		if ($from !== null) {
			$qb->andWhere($qb->expr()->gte('date', $qb->createNamedParameter($from)));
		}
		if ($to !== null) {
			$qb->andWhere($qb->expr()->lte('date', $qb->createNamedParameter($to)));
		}
		return $this->findEntities($qb);
	}

	/**
	 * Fingerprints aller Buchungen für die Duplikatprüfung beim Merge-Import.
	 * Fingerprint-Format: "datum|betragCents|sollKontoIds|habenKontoIds|belegnummer"
	 *
	 * @return array<string, true>
	 */
	public function findFingerprintsForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('j.id', 'j.date', 'j.document_ref', 'l.account_id', 'l.debit_cents', 'l.credit_cents')
			->from($this->getTableName(), 'j')
			->innerJoin('j', 'vbh_journal_line', 'l', $qb->expr()->eq('l.journal_id', 'j.id'))
			->where($qb->expr()->eq('j.user_id', $qb->createNamedParameter($userId)));
		$res = $qb->executeQuery();

		$byId = [];
		while (($row = $res->fetch()) !== false) {
			$id = (int)$row['id'];
			if (!isset($byId[$id])) {
				$byId[$id] = ['date' => (string)$row['date'], 'doc' => (string)($row['document_ref'] ?? ''), 'debits' => [], 'credits' => [], 'amount' => 0];
			}
			$accountId = (int)$row['account_id'];
			if ((int)$row['debit_cents'] > 0) {
				$byId[$id]['debits'][] = $accountId;
				// Der Buchungsbetrag ist die Summe der Sollseite, nicht der
				// Betrag einer einzelnen Zeile (siehe fingerprint()).
				$byId[$id]['amount'] += (int)$row['debit_cents'];
			}
			if ((int)$row['credit_cents'] > 0) {
				$byId[$id]['credits'][] = $accountId;
			}
		}
		$res->closeCursor();

		$fps = [];
		foreach ($byId as $j) {
			// Dieselbe Formel wie auf der eingehenden Seite (XbucImportService),
			// siehe RowNormalizer::journalFingerprint().
			$fp = RowNormalizer::journalFingerprint($j['date'], $j['amount'], $j['debits'], $j['credits'], $j['doc']);
			if ($fp !== null) {
				$fps[$fp] = true;
			}
		}
		return $fps;
	}

	/**
	 * Datum, Betrag (absolut, Cent) und Beschreibung aller Buchungen OHNE
	 * verknüpfte Bankbuchung (bank_tx_id IS NULL) – also XBUC-/manuell erfasste.
	 * Dient dem CSV-Import als zusätzliche Dublettenprüfung: eine CSV-Zeile, die
	 * bereits als solche Buchung existiert, wird beim Import übersprungen.
	 *
	 * @return array<int, array{date:string, amount:int, description:string}>
	 */
	public function findManualBookingKeys(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('j.id', 'j.date', 'j.description', 'l.debit_cents')
			->from($this->getTableName(), 'j')
			->innerJoin('j', 'vbh_journal_line', 'l', $qb->expr()->eq('l.journal_id', 'j.id'))
			->where($qb->expr()->eq('j.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('j.bank_tx_id'));
		$res = $qb->executeQuery();

		$byId = [];
		while (($row = $res->fetch()) !== false) {
			$id = (int)$row['id'];
			if (!isset($byId[$id])) {
				$byId[$id] = ['date' => (string)$row['date'], 'description' => (string)($row['description'] ?? ''), 'amount' => 0];
			}
			$byId[$id]['amount'] += (int)$row['debit_cents'];
		}
		$res->closeCursor();
		return array_values($byId);
	}

	/**
	 * Liste der Geschäftsjahre (Kalenderjahre), in denen Buchungen existieren –
	 * absteigend sortiert.
	 *
	 * @return int[]
	 */
	public function distinctYears(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('year')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$res = $qb->executeQuery();
		$years = [];
		while (($row = $res->fetch()) !== false) {
			$year = (int)$row['year'];
			if ($year > 0) {
				$years[$year] = true;
			}
		}
		$res->closeCursor();
		$years = array_keys($years);
		rsort($years);
		return $years;
	}
}
