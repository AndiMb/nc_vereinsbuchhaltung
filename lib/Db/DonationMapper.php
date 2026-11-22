<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Donation>
 */
class DonationMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_donations', Donation::class);
	}

	public function findByJournal(int $journalId): ?Donation {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('journal_id', $qb->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)));
		$found = $this->findEntities($qb);
		return $found[0] ?? null;
	}

	/**
	 * @param int[] $journalIds
	 * @return array<int, Donation> journalId => Zuordnung
	 */
	public function findByJournals(array $journalIds): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($journalIds)), 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from($this->getTableName())
				->where($qb->expr()->in('journal_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($this->findEntities($qb) as $donation) {
				$out[$donation->getJournalId()] = $donation;
			}
		}
		return $out;
	}

	/** @return list<Donation> */
	public function findByDonor(string $kind, int $id): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq($kind === Donation::DONOR_MEMBER ? 'member_id' : 'debtor_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return array_values($this->findEntities($qb));
	}

	/** Reset: die Zuordnungen hängen an den gelöschten Buchungen. */
	public function deleteAll(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())->executeStatement();
	}

	/** @return list<Donation> */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
		return array_values($this->findEntities($qb));
	}
}
