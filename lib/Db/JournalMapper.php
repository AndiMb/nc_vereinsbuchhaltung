<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

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
			->andWhere($qb->expr()->gte('date', $qb->createNamedParameter(sprintf('%04d-01-01', $year))))
			->andWhere($qb->expr()->lte('date', $qb->createNamedParameter(sprintf('%04d-12-31', $year))));
		$res = $qb->executeQuery();
		$max = $res->fetchOne();
		$res->closeCursor();
		return (int)$max + 1;
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
	 * Fingerprints aller Buchungen für Duplikatprüfung beim Merge-Import.
	 * Fingerprint-Format: "datum|betragCents|sollKontoId|habenKontoId|belegnummer"
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
				$byId[$id] = ['date' => $row['date'], 'doc' => (string)($row['document_ref'] ?? ''), 'debit' => null, 'credit' => null, 'amount' => 0];
			}
			if ((int)$row['debit_cents'] > 0) {
				$byId[$id]['debit'] = (int)$row['account_id'];
				$byId[$id]['amount'] = (int)$row['debit_cents'];
			}
			if ((int)$row['credit_cents'] > 0) {
				$byId[$id]['credit'] = (int)$row['account_id'];
			}
		}
		$res->closeCursor();

		$fps = [];
		foreach ($byId as $j) {
			if ($j['debit'] !== null && $j['credit'] !== null) {
				$fps[$j['date'] . '|' . $j['amount'] . '|' . $j['debit'] . '|' . $j['credit'] . '|' . $j['doc']] = true;
			}
		}
		return $fps;
	}

	/**
	 * Liste der Geschäftsjahre (Kalenderjahre), in denen Buchungen existieren –
	 * absteigend sortiert.
	 *
	 * @return int[]
	 */
	public function distinctYears(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('date')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$res = $qb->executeQuery();
		$years = [];
		while (($row = $res->fetch()) !== false) {
			$year = (int)substr((string)$row['date'], 0, 4);
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
