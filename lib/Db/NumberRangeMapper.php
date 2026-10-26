<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<NumberRange>
 */
class NumberRangeMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_number_ranges', NumberRange::class);
	}

	/** @return NumberRange[] */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @throws DoesNotExistException */
	public function findByKind(string $kind): NumberRange {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('kind', $qb->createNamedParameter($kind)));
		return $this->findEntity($qb);
	}

	/**
	 * Zieht den Zähler nur dann weiter, wenn er noch auf dem gelesenen Stand
	 * steht (Compare-and-Swap). Gewinnt eine gleichzeitige Festschreibung, gibt
	 * diese Methode `false` zurück und der Aufrufer liest neu. Zusammen mit dem
	 * eindeutigen Index auf der Rechnungsnummer ist damit ausgeschlossen, dass
	 * zwei Rechnungen dieselbe Nummer bekommen; läuft der Aufruf in der
	 * Transaktion der Festschreibung, rollt ein Fehler auch den Zähler zurück
	 * und die Nummern bleiben lückenlos.
	 *
	 * @return bool Ob der Zähler von $expectedNextNo auf $newNextNo gesetzt wurde
	 */
	public function advance(int $id, int $expectedNextNo, int $newNextNo, ?string $counterKey, string $issuedAt): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('next_no', $qb->createNamedParameter($newNextNo, IQueryBuilder::PARAM_INT))
			->set('counter_key', $qb->createNamedParameter($counterKey))
			->set('last_issued_at', $qb->createNamedParameter($issuedAt))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('next_no', $qb->createNamedParameter($expectedNextNo, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement() === 1;
	}

	/** @throws DoesNotExistException */
	public function find(int $id): NumberRange {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}
}
