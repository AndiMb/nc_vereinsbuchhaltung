<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Zwischenspeicher der Prüfsummen von Dateien im Wächter-Ordner, für die
 * Dublettensuche. Ein Eintrag gilt, solange Größe und Änderungszeit der
 * Datei passen; geht die Tabelle verloren, wird eben neu gerechnet.
 *
 * Keine Entity: es gibt nur Lesen-alles, Schreiben und Aufräumen.
 */
class ReceiptHashMapper {

	private const TABLE = 'vbh_receipt_hashes';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/** @return array<int, array{size:int, mtime:int, hash:string}> nach Datei-ID */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('file_id', 'size', 'mtime', 'hash')->from(self::TABLE);
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[(int)$row['file_id']] = ['size' => (int)$row['size'], 'mtime' => (int)$row['mtime'], 'hash' => (string)$row['hash']];
		}
		$result->closeCursor();
		return $out;
	}

	public function save(int $fileId, int $size, int $mtime, string $hash): void {
		$this->deleteByFileIds([$fileId]);
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
			'size' => $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT),
			'mtime' => $qb->createNamedParameter($mtime, IQueryBuilder::PARAM_INT),
			'hash' => $qb->createNamedParameter($hash),
		])->executeStatement();
	}

	/** @param list<int> $fileIds */
	public function deleteByFileIds(array $fileIds): void {
		foreach (array_chunk($fileIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete(self::TABLE)
				->where($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
	}
}
