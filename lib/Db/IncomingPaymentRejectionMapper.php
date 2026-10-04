<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<IncomingPaymentRejection>
 */
class IncomingPaymentRejectionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_incoming_pay_rejects', IncomingPaymentRejection::class);
	}

	/**
	 * Alle abgelehnten Paare als Nachschlage-Menge: Schlüssel `bankTxId:openItemId`.
	 * Eine Abfrage für die ganze Liste statt einer je Vorschlag.
	 *
	 * @return array<string,true>
	 */
	public function findAllKeys(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('bank_tx_id', 'open_item_id')->from($this->getTableName());
		$keys = [];
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			$keys[self::key((int)$row['bank_tx_id'], (int)$row['open_item_id'])] = true;
		}
		$result->closeCursor();
		return $keys;
	}

	public function exists(int $bankTxId, int $openItemId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('bank_tx_id', $qb->createNamedParameter($bankTxId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('open_item_id', $qb->createNamedParameter($openItemId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$found = $result->fetch() !== false;
		$result->closeCursor();
		return $found;
	}

	/** Beim Zurücksetzen des Buchungsbestands (siehe ResetService): die Umsätze, auf die sie zeigen, sind weg. */
	public function deleteAll(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())->executeStatement();
	}

	public static function key(int $bankTxId, int $openItemId): string {
		return $bankTxId . ':' . $openItemId;
	}
}
