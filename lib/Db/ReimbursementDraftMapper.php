<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCA\Vereinsbuchhaltung\Service\FieldCipher;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Entwürfe von Auslagenanträgen (Issue #356). Gelesen wird nur je Mitglied –
 * eine Abfrage über alle Mitglieder gibt es bewusst nicht, damit kein
 * Verwalter-Pfad Entwürfe zu sehen bekommt.
 *
 * @extends EncryptingMapper<ReimbursementDraft>
 */
class ReimbursementDraftMapper extends EncryptingMapper {
	public function __construct(IDBConnection $db, FieldCipher $cipher) {
		parent::__construct($db, 'vbh_reimb_drafts', ReimbursementDraft::class, $cipher);
	}

	protected function encryptedProperties(): array {
		return ['iban'];
	}

	/**
	 * Ein Entwurf dieses Mitglieds; ein fremder sieht aus wie ein nicht vorhandener.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 */
	public function findForMember(int $id, int $memberId): ReimbursementDraft {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('member_id', $qb->createNamedParameter($memberId, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return ReimbursementDraft[] zuletzt bearbeitete zuerst */
	public function findByMember(int $memberId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('member_id', $qb->createNamedParameter($memberId, IQueryBuilder::PARAM_INT)))
			->orderBy('updated_at', 'DESC')
			->addOrderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/** @return list<int> IDs aller Entwürfe, für Reset und „Bestand leeren“ */
	public function allIds(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName());
		$result = $qb->executeQuery();
		$ids = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return array_values($ids);
	}
}
