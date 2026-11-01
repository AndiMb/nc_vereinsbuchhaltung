<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCA\Vereinsbuchhaltung\Service\FieldCipher;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends EncryptingMapper<Reimbursement>
 */
class ReimbursementMapper extends EncryptingMapper {
	public function __construct(IDBConnection $db, FieldCipher $cipher) {
		parent::__construct($db, 'vbh_reimbursements', Reimbursement::class, $cipher);
	}

	protected function encryptedProperties(): array {
		return ['iban'];
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException wenn es den Antrag nicht gibt
	 */
	public function find(int $id): Reimbursement {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return Reimbursement[] neueste zuerst */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/** @return Reimbursement[] neueste zuerst */
	public function findByMember(int $memberId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('member_id', $qb->createNamedParameter($memberId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Anträge des Mitglieds, die noch nicht abgeschlossen sind. Sie blockieren die
	 * Anonymisierung (Design §11).
	 */
	public function countPendingByMember(int $memberId): int {
		return $this->count($memberId, Reimbursement::STATUSES_PENDING);
	}

	public function countByMember(int $memberId): int {
		return $this->count($memberId);
	}

	public function countAll(): int {
		return $this->count();
	}

	/**
	 * @param list<string>|null $statuses nur Anträge in diesen Status
	 */
	private function count(?int $memberId = null, ?array $statuses = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from($this->getTableName());
		if ($memberId !== null) {
			$qb->andWhere($qb->expr()->eq('member_id', $qb->createNamedParameter($memberId, IQueryBuilder::PARAM_INT)));
		}
		if ($statuses !== null) {
			$qb->andWhere($qb->expr()->in('status', $qb->createNamedParameter($statuses, IQueryBuilder::PARAM_STR_ARRAY)));
		}
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * Löscht alle Zeilen. Nur für den Reset der Buchhaltung; im Normalbetrieb
	 * gibt es keinen Weg, die Historie eines Antrags zu entfernen.
	 */
	public function deleteAll(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())->executeStatement();
	}
}
