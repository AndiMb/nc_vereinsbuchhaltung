<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<MandateAmendment>
 */
class MandateAmendmentMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_mandate_amendments', MandateAmendment::class);
	}

	public function find(int $id): MandateAmendment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return MandateAmendment[] neueste zuerst */
	public function findByMandate(int $mandateId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('mandate_id', $qb->createNamedParameter($mandateId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/** @return MandateAmendment[] offene (noch nicht übermittelte) Amendments eines Mandats */
	public function findOpenByMandate(int $mandateId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('mandate_id', $qb->createNamedParameter($mandateId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(MandateAmendment::STATUS_OPEN)))
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Setzt alle übermittelten Amendments auf `open` zurück und löst ihren
	 * Verweis auf den Einzugsposten. Beim Zurücksetzen (siehe
	 * {@see \OCA\Vereinsbuchhaltung\Service\ContributionResetService}) sind die
	 * Posten weg – und `transmitted` heißt laut Spec §2.2 „steckt in einem
	 * eingereichten Einzugsposten". Ohne Posten stimmt das nicht mehr; `open`
	 * lässt die Änderung beim nächsten Einzug noch einmal mitlaufen (SMNDA, Spec
	 * §8 empfiehlt es für jeden Kontowechsel), ein fälschlich als gemeldet
	 * geltendes Amendment bliebe dagegen unbemerkt liegen.
	 *
	 * @return int Anzahl betroffener Amendments
	 */
	public function reopenAllTransmitted(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter(MandateAmendment::STATUS_OPEN))
			->set('debit_item_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->orX(
				$qb->expr()->eq('status', $qb->createNamedParameter(MandateAmendment::STATUS_TRANSMITTED)),
				$qb->expr()->isNotNull('debit_item_id'),
			));
		return $qb->executeStatement();
	}
}
