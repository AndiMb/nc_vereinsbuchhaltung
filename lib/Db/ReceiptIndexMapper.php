<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCA\Vereinsbuchhaltung\Service\ReceiptIndex\ReceiptFacts;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IParameter;
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

	/**
	 * Aktualisiert die Zeile oder legt sie an. Erst Update, damit es kein
	 * Zeitfenster ohne Zeile gibt; legt ein zweiter Lauf die Zeile zwischen
	 * Update und Insert an, fällt der Insert auf ein Update zurück.
	 */
	public function save(int $fileId, int $size, int $mtime, int $version, ReceiptFacts $facts): void {
		if ($this->update($fileId, $size, $mtime, $version, $facts) > 0) {
			return;
		}
		try {
			$this->insert($fileId, $size, $mtime, $version, $facts);
		} catch (DbException $e) {
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			$this->update($fileId, $size, $mtime, $version, $facts);
		}
	}

	/** @return array<string, mixed> Spaltenwerte ohne file_id */
	private static function columns(int $size, int $mtime, int $version, ReceiptFacts $facts): array {
		return [
			'size' => $size,
			'mtime' => $mtime,
			'parser_version' => $version,
			'source' => $facts->source,
			'status' => $facts->status,
			'refs' => json_encode($facts->refs, JSON_THROW_ON_ERROR),
			'name_refs' => json_encode($facts->nameRefs, JSON_THROW_ON_ERROR),
			'amounts' => json_encode($facts->amounts, JSON_THROW_ON_ERROR),
			'dates' => json_encode($facts->dates, JSON_THROW_ON_ERROR),
			'invoice_no' => $facts->invoiceNo !== null ? mb_substr($facts->invoiceNo, 0, 64) : null,
			'total_cents' => $facts->totalCents,
			'issue_date' => $facts->issueDate,
			'vendor' => $facts->vendor !== null ? mb_substr($facts->vendor, 0, 120) : null,
		];
	}

	private function bind(IQueryBuilder $qb, string $column, mixed $value): IParameter {
		return match ($column) {
			'size', 'mtime', 'parser_version', 'total_cents' => $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT),
			default => $qb->createNamedParameter($value),
		};
	}

	private function update(int $fileId, int $size, int $mtime, int $version, ReceiptFacts $facts): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE);
		foreach (self::columns($size, $mtime, $version, $facts) as $column => $value) {
			$qb->set($column, $this->bind($qb, $column, $value));
		}
		$qb->set('indexed_at', $qb->createNamedParameter(new \DateTime(), Types::DATETIME))
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	private function insert(int $fileId, int $size, int $mtime, int $version, ReceiptFacts $facts): void {
		$qb = $this->db->getQueryBuilder();
		$values = ['file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)];
		foreach (self::columns($size, $mtime, $version, $facts) as $column => $value) {
			$values[$column] = $this->bind($qb, $column, $value);
		}
		$values['indexed_at'] = $qb->createNamedParameter(new \DateTime(), Types::DATETIME);
		$qb->insert(self::TABLE)->values($values)->executeStatement();
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
