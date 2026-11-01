<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Invoice>
 */
class InvoiceMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_invoices', Invoice::class);
	}

	public function find(int $id): Invoice {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Alle Rechnungen, neueste zuerst; optional nach Status, Jahr des
	 * Rechnungsdatums (Entwürfe ohne Datum zählen nach Anlagedatum) und
	 * Empfänger gefiltert.
	 *
	 * @return Invoice[]
	 */
	public function findFiltered(?string $status = null, ?int $year = null, ?int $debtorId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('id', 'DESC');
		if ($status !== null && $status !== '') {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}
		if ($year !== null) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->like('issue_date', $qb->createNamedParameter($year . '-%')),
				$qb->expr()->andX(
					$qb->expr()->isNull('issue_date'),
					$qb->expr()->like('created_at', $qb->createNamedParameter($year . '-%')),
				),
			));
		}
		if ($debtorId !== null) {
			$qb->andWhere($qb->expr()->eq('debtor_id', $qb->createNamedParameter($debtorId, IQueryBuilder::PARAM_INT)));
		}
		return $this->findEntities($qb);
	}

	/**
	 * Festgeschriebene Rechnungen und Stornorechnungen, deren Rechnungsdatum im Zeitraum liegt
	 * (ohne Angabe: alle), nach Nummer geordnet. Entwürfe fehlen.
	 *
	 * @return Invoice[]
	 */
	public function findIssuedBetween(?string $from, ?string $to): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->isNotNull('issued_at'))
			->orderBy('issue_date', 'ASC')
			->addOrderBy('number', 'ASC');
		if ($from !== null) {
			$qb->andWhere($qb->expr()->gte('issue_date', $qb->createNamedParameter($from)));
		}
		if ($to !== null) {
			$qb->andWhere($qb->expr()->lte('issue_date', $qb->createNamedParameter($to)));
		}
		return $this->findEntities($qb);
	}

	public function findByNumber(string $number): ?Invoice {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('number', $qb->createNamedParameter($number)))
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	public function findByOpenItem(int $openItemId): ?Invoice {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('open_item_id', $qb->createNamedParameter($openItemId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/**
	 * Sichert sich das Festschreiben eines Entwurfs: setzt `issued_at` nur,
	 * wenn die Rechnung noch nicht festgeschrieben ist. Zwei gleichzeitige
	 * Aufrufe können so nicht beide durchlaufen; der zweite bekommt `false`
	 * und bricht ab, bevor er eine Nummer verbraucht.
	 */
	public function claimIssue(int $id, string $issuedAt): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('issued_at', $qb->createNamedParameter($issuedAt))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('issued_at'))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Invoice::STATUS_DRAFT)));
		return $qb->executeStatement() === 1;
	}

	/** Stornorechnung zu einer Rechnung, falls schon vorhanden. */
	public function findCreditFor(int $invoiceId): ?Invoice {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('preceding_invoice_id', $qb->createNamedParameter($invoiceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter(Invoice::KIND_CREDIT)))
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/** @param list<int> $ids @return array<int, Invoice> */
	public function findByIds(array $ids): array {
		$found = [];
		foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from($this->getTableName())
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($this->findEntities($qb) as $invoice) {
				$found[(int)$invoice->getId()] = $invoice;
			}
		}
		return $found;
	}

	/** Löscht alle Zeilen. Gedacht für den Reset des Buchungsbestands. */
	/** @return Invoice[] die Rechnungen eines Sammelrechnungs-Stapels (#243) */
	public function findByBatch(int $batchId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('batch_id', $qb->createNamedParameter($batchId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteAll(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName());
		$qb->executeStatement();
	}
}
