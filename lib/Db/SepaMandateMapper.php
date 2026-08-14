<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<SepaMandate>
 */
class SepaMandateMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_sepa_mandates', SepaMandate::class);
	}

	/** @return SepaMandate[] */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('status', 'ASC')
			->addOrderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	public function find(int $id): SepaMandate {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	public function findByReference(string $mandateReference): ?SepaMandate {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('mandate_reference', $qb->createNamedParameter($mandateReference)))
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/**
	 * Aktives Mandat zu einer IBAN, falls es eines gibt.
	 *
	 * Gebraucht vom Mitglieder-Import: dieselbe IBAN ein zweites Mal
	 * einzulesen erzeugte sonst ein zweites Mandat – und beim nächsten Einzug
	 * würde der Betrag zweimal abgebucht. Widerrufene Mandate zählen nicht,
	 * ein Kontowechsel und zurück ist ein legitimer Vorgang.
	 */
	public function findActiveByIban(string $iban): ?SepaMandate {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('iban', $qb->createNamedParameter($iban)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')))
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/** @return SepaMandate[] aktive Mandate eines Nextcloud-Kontos (i. d. R. maximal eines). */
	public function findActiveByMemberUid(string $memberUid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('member_uid', $qb->createNamedParameter($memberUid)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')));
		return $this->findEntities($qb);
	}

	/**
	 * Anzahl aller Mandate, unabhaengig vom Status.
	 *
	 * Entscheidet zusammen mit {@see MembershipFeeMapper::count()}, ob der
	 * Reiter „Beitraege" ueberhaupt sichtbar ist (SettingsController::index()):
	 * ein Verein, der bereits Mitglieder angelegt hat, soll ihn auch ohne den
	 * separaten Schalter sehen.
	 */
	public function count(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName());
		$res = $qb->executeQuery();
		$count = (int)$res->fetchOne();
		$res->closeCursor();
		return $count;
	}
}
