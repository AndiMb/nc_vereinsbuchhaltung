<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCA\Vereinsbuchhaltung\Service\FieldCipher;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends EncryptingMapper<BankTransaction>
 */
class BankTransactionMapper extends EncryptingMapper {
	public function __construct(IDBConnection $db, FieldCipher $cipher) {
		parent::__construct($db, 'vbh_bank_tx', BankTransaction::class, $cipher);
	}

	protected function encryptedProperties(): array {
		return ['counterpartyIban'];
	}

	public function find(int $id, string $userId): BankTransaction {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	/**
	 * Felder aller vorhandenen Bankbuchungen für den weichen Dublettenabgleich.
	 *
	 * Gebraucht, weil der Hash quellenabhängig ist: dieselbe Buchung trägt im
	 * CSV-Export der Bank oft nur eine Kontonummer als eigenes Konto, im
	 * CAMT.053 und über FinTS dagegen die IBAN. Die Hashes weichen dann
	 * zwangsläufig ab, und ohne diesen zweiten Abgleich läge derselbe Umsatz
	 * beim Formatwechsel doppelt in „Zuzuordnen".
	 *
	 * @return array<int, array{date:string, valueDate:?string, amount:int, text:string}>
	 */
	public function findDedupKeys(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('booking_date', 'value_date', 'amount_cents', 'counterparty', 'purpose')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$out = [];
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			$out[] = [
				'date' => (string)$row['booking_date'],
				'valueDate' => $row['value_date'] !== null ? (string)$row['value_date'] : null,
				'amount' => (int)$row['amount_cents'],
				'text' => (string)($row['counterparty'] ?? '') . (string)($row['purpose'] ?? ''),
			];
		}
		$result->closeCursor();
		return $out;
	}

	/**
	 * @param string[] $hashes
	 * @return string[] vorhandene Hashes
	 */
	public function findExistingHashes(string $userId, array $hashes): array {
		if (count($hashes) === 0) {
			return [];
		}
		$found = [];
		// in Blöcken abfragen, um Parameter-Limits zu vermeiden
		foreach (array_chunk($hashes, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('hash')
				->from($this->getTableName())
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->in('hash', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
			$result = $qb->executeQuery();
			while (($row = $result->fetch()) !== false) {
				$found[] = $row['hash'];
			}
			$result->closeCursor();
		}
		return $found;
	}

	/**
	 * Mehrere Umsätze in einer Abfrage (Bankabgleich, Issue #105).
	 *
	 * @param list<int> $ids
	 * @return array<int,BankTransaction> Umsatz-ID => Umsatz (fehlende IDs fehlen auch hier)
	 */
	public function findByIds(string $userId, array $ids): array {
		$found = [];
		foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($this->findEntities($qb) as $tx) {
				$found[(int)$tx->getId()] = $tx;
			}
		}
		return $found;
	}

	public function deleteAllForUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/**
	 * Summe aller importierten Umsätze (in Cent), optional je Status.
	 */
	public function sumAmount(string $userId, ?string $status = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->sum('amount_cents'), 'total')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		if ($status !== null && $status !== '') {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}
		$result = $qb->executeQuery();
		$total = $result->fetchOne();
		$result->closeCursor();
		return (int)$total;
	}

	/**
	 * Wie oft wurde je Zahlungspartner welches Gegenkonto gewählt? Grundlage der
	 * Zuordnungsvorschläge (Issue #255): statt aller zugeordneten Umsätze samt
	 * Entschlüsselung der IBAN reichen der Name und der Zähler. Bewusst ohne
	 * Entity-Mapping.
	 *
	 * @return list<array{counterparty:string, contraAccountId:int, count:int}>
	 */
	public function counterpartyHistory(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('counterparty', 'contra_account_id')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('assigned')))
			->andWhere($qb->expr()->isNotNull('contra_account_id'))
			->andWhere($qb->expr()->neq('counterparty', $qb->createNamedParameter('')))
			->groupBy('counterparty', 'contra_account_id');

		$out = [];
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			if ($row['counterparty'] === null) {
				continue;
			}
			$out[] = [
				'counterparty' => (string)$row['counterparty'],
				'contraAccountId' => (int)$row['contra_account_id'],
				'count' => (int)$row['cnt'],
			];
		}
		$result->closeCursor();
		return $out;
	}

	/**
	 * Anzahl aller Umsätze und der zugeordneten davon (Fortschrittsbalken).
	 *
	 * @return array{total:int, assigned:int}
	 */
	public function statusCounts(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('status')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->groupBy('status');

		$total = 0;
		$assigned = 0;
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			$total += (int)$row['cnt'];
			if ($row['status'] === 'assigned') {
				$assigned += (int)$row['cnt'];
			}
		}
		$result->closeCursor();
		return ['total' => $total, 'assigned' => $assigned];
	}

	/**
	 * Zugeordnete Umsätze, deren Buchung im Zeitraum liegt (Filter auf das
	 * Buchungsdatum des Journals, nicht auf das Bankdatum). Ohne Grenzen alle.
	 *
	 * @return list<BankTransaction>
	 */
	public function findAssignedInRange(string $userId, ?string $from, ?string $to): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.*')
			->from($this->getTableName(), 't')
			->innerJoin('t', 'vbh_journal', 'j', $qb->expr()->eq('j.id', 't.journal_id'))
			->where($qb->expr()->eq('t.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('t.status', $qb->createNamedParameter('assigned')));
		if ($from !== null) {
			$qb->andWhere($qb->expr()->gte('j.date', $qb->createNamedParameter($from)));
		}
		if ($to !== null) {
			$qb->andWhere($qb->expr()->lte('j.date', $qb->createNamedParameter($to)));
		}
		return $this->findEntities($qb);
	}

	/**
	 * @return BankTransaction[]
	 */
	public function findFiltered(string $userId, ?string $status = null, int $limit = 500, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		if ($status !== null && $status !== '') {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}
		$qb->orderBy('booking_date', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		return $this->findEntities($qb);
	}
}
