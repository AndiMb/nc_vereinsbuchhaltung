<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCA\Vereinsbuchhaltung\Service\ReceiptIndex\ReceiptFacts;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;

/**
 * Index der Belegdateien im Wächter-Ordner (Tabelle vbh_receipt_index): je
 * Datei die gelesenen Merkmale, gültig solange Größe, Änderungszeit und
 * Parser-Version passen. Geht die Tabelle verloren, wird neu gelesen.
 *
 * Keine Entity: es gibt nur Lesen-alles, Schreiben und Aufräumen.
 */
class ReceiptIndexMapper {

	private const TABLE = 'vbh_receipt_index';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/** @return array<int, array{size:int, mtime:int, version:int, facts: ReceiptFacts}> nach Datei-ID */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('file_id', 'size', 'mtime', 'parser_version', 'source', 'status', 'refs', 'name_refs', 'amounts', 'dates', 'invoice_no', 'total_cents', 'issue_date', 'vendor')
			->from(self::TABLE);
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[(int)$row['file_id']] = [
				'size' => (int)$row['size'],
				'mtime' => (int)$row['mtime'],
				'version' => (int)$row['parser_version'],
				'facts' => ReceiptFacts::fromArray([
					'source' => $row['source'],
					'status' => $row['status'],
					'refs' => self::decode($row['refs']),
					'nameRefs' => self::decode($row['name_refs']),
					'amounts' => self::decode($row['amounts']),
					'dates' => self::decode($row['dates']),
					'invoiceNo' => $row['invoice_no'],
					'totalCents' => $row['total_cents'],
					'issueDate' => $row['issue_date'] !== null ? substr((string)$row['issue_date'], 0, 10) : null,
					'vendor' => $row['vendor'],
				]),
			];
		}
		$result->closeCursor();
		return $out;
	}

	public function save(int $fileId, int $size, int $mtime, int $version, ReceiptFacts $facts): void {
		$this->deleteByFileIds([$fileId]);
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
			'size' => $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT),
			'mtime' => $qb->createNamedParameter($mtime, IQueryBuilder::PARAM_INT),
			'parser_version' => $qb->createNamedParameter($version, IQueryBuilder::PARAM_INT),
			'source' => $qb->createNamedParameter($facts->source),
			'status' => $qb->createNamedParameter($facts->status),
			'refs' => $qb->createNamedParameter(json_encode($facts->refs, JSON_THROW_ON_ERROR)),
			'name_refs' => $qb->createNamedParameter(json_encode($facts->nameRefs, JSON_THROW_ON_ERROR)),
			'amounts' => $qb->createNamedParameter(json_encode($facts->amounts, JSON_THROW_ON_ERROR)),
			'dates' => $qb->createNamedParameter(json_encode($facts->dates, JSON_THROW_ON_ERROR)),
			'invoice_no' => $qb->createNamedParameter($facts->invoiceNo !== null ? mb_substr($facts->invoiceNo, 0, 64) : null),
			'total_cents' => $qb->createNamedParameter($facts->totalCents, IQueryBuilder::PARAM_INT),
			'issue_date' => $qb->createNamedParameter($facts->issueDate),
			'vendor' => $qb->createNamedParameter($facts->vendor !== null ? mb_substr($facts->vendor, 0, 120) : null),
			'indexed_at' => $qb->createNamedParameter(new \DateTime(), Types::DATETIME),
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

	/** @return array<mixed> */
	private static function decode(mixed $json): array {
		if (!is_string($json) || $json === '') {
			return [];
		}
		$data = json_decode($json, true);
		return is_array($data) ? $data : [];
	}
}
