<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<SepaBatchItem>
 */
class SepaBatchItemMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_sepa_batch_items', SepaBatchItem::class);
	}

	public function find(int $id): SepaBatchItem {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return SepaBatchItem[] */
	public function findByBatch(int $batchId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('batch_id', $qb->createNamedParameter($batchId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Offene Posten, die bereits in einem eingereichten, aber noch nicht
	 * abgeschlossenen Einzug stecken – sie dürfen kein zweites Mal eingezogen
	 * werden.
	 *
	 * Bewusst nur `pending`: bei `settled` ist der Posten ohnehin bezahlt und
	 * fällt schon deshalb aus der Auswahl; holt ihn jemand von Hand zurück in
	 * den Status „offen", will er genau das – einen erneuten Einzug. Und eine
	 * zurückgebuchte Zeile soll den Posten erst recht wieder freigeben.
	 *
	 * @return int[]
	 */
	public function findPendingOpenItemIds(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('open_item_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('pending')));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return array_map(static fn (array $row): int => (int)$row['open_item_id'], $rows);
	}

	/**
	 * Sammeleinzug-Zeilen, die im Zeitfenster [$from, $until] fällig werden und
	 * noch keine Vorankündigung bekommen haben.
	 * Genutzt vom {@see \OCA\Vereinsbuchhaltung\BackgroundJob\SepaPreNotificationJob}.
	 *
	 * Ein Fenster statt eines Stichtags: vorher wurde `execution_date` exakt
	 * mit "heute + 14 Tage" verglichen, wodurch jeder Einzug mit kürzerem
	 * Vorlauf nie eine Ankündigung bekam – siehe SepaNotificationService.
	 *
	 * @param string $from  ab wann (i. d. R. heute – Vergangenes ist erledigt)
	 * @param string $until bis wann (i. d. R. heute + Vorlaufzeit)
	 * @return SepaBatchItem[]
	 */
	public function findDueForNotification(string $from, string $until): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('i.*')
			->from($this->getTableName(), 'i')
			->innerJoin('i', 'vbh_sepa_batches', 'b', $qb->expr()->eq('i.batch_id', 'b.id'))
			->where($qb->expr()->eq('i.status', $qb->createNamedParameter('pending')))
			->andWhere($qb->expr()->isNull('i.notified_at'))
			->andWhere($qb->expr()->gte('b.execution_date', $qb->createNamedParameter($from)))
			->andWhere($qb->expr()->lte('b.execution_date', $qb->createNamedParameter($until)))
			->orderBy('b.execution_date', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Alle Zeilen eines Mandats, unabhängig vom Status – die Frage lautet hier
	 * „wird das Mandat noch gebraucht?", und dafür zählt auch eine längst
	 * zurückgebuchte Zeile (siehe SepaMandateService::delete()).
	 *
	 * @return SepaBatchItem[]
	 */
	public function findByMandate(int $mandateId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('mandate_id', $qb->createNamedParameter($mandateId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/**
	 * Zeilen eines Mandats, für die noch eine Rücklastschrift eintreffen kann
	 * – also alles außer bereits zurückgebuchten. Ausdrücklich einschließlich
	 * `settled`: die Rückgabe erreicht den Verein regelmäßig erst, nachdem er
	 * den Einzug als ausgeführt verbucht hat (siehe SepaBatchItem::OPEN_STATUSES).
	 *
	 * @return SepaBatchItem[]
	 */
	public function findUnreturnedByMandate(int $mandateId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('mandate_id', $qb->createNamedParameter($mandateId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(SepaBatchItem::OPEN_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)));
		return $this->findEntities($qb);
	}

	/**
	 * Noch nicht zurückgebuchte Zeilen mit genau diesem Betrag – Fallback-
	 * Zuordnung für die Rücklastschrift-Erkennung, wenn im Verwendungszweck
	 * keine Mandats- oder End-to-End-Referenz zu finden war.
	 *
	 * @return SepaBatchItem[]
	 */
	public function findUnreturnedByAmount(int $amountCents): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('amount_cents', $qb->createNamedParameter($amountCents, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(SepaBatchItem::OPEN_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)));
		return $this->findEntities($qb);
	}

	/**
	 * Jüngste Fälligkeit, zu der dieses Mandat tatsächlich noch in einem
	 * Sammeleinzug steckt. Wird gebraucht, um `last_used_date` am Mandat auf
	 * den Stand zu bringen, der durch die verbliebenen Einzüge gedeckt ist –
	 * sonst gälte das Mandat weiter als benutzt und der nächste Einzug liefe
	 * als RCUR statt als FRST.
	 *
	 * Zurückgebuchte Zeilen zählen dabei **nicht**: eine Lastschrift, die die
	 * Bank zurückgegeben hat, war kein Einzug. Ein zurückgegebener Ersteinzug
	 * muss deshalb erneut als FRST eingereicht werden. Nach dem Verwerfen
	 * eines Einzugs (SepaBatchService::deleteBatch()) macht das keinen
	 * Unterschied – dort kann es zurückgebuchte Zeilen gar nicht geben.
	 */
	public function findLastExecutionDateByMandate(int $mandateId): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('b.execution_date'))
			->from($this->getTableName(), 'i')
			->innerJoin('i', 'vbh_sepa_batches', 'b', $qb->expr()->eq('i.batch_id', 'b.id'))
			->where($qb->expr()->eq('i.mandate_id', $qb->createNamedParameter($mandateId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('i.status', $qb->createNamedParameter('returned')));
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();
		return $value === false || $value === null || $value === '' ? null : (string)$value;
	}

	/** Alle Zeilen eines Einzugs löschen (siehe SepaBatchService::deleteBatch()). */
	public function deleteByBatch(int $batchId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('batch_id', $qb->createNamedParameter($batchId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function findByEndToEndId(string $endToEndId): ?SepaBatchItem {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('end_to_end_id', $qb->createNamedParameter($endToEndId)))
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}
}
