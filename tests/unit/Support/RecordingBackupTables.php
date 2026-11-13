<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit\Support;

use OCA\Vereinsbuchhaltung\Service\Backup\BackupTableStore;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * {@see BackupTableStore}, der mitschreibt statt SQL abzusetzen: ersetzt
 * genau die drei Stellen, an denen der Dienst die Datenbank berührt.
 */
class RecordingBackupTables extends BackupTableStore {

	/** @var list<array{table: string, values: array<string, mixed>, types: array<string, int|string>}> */
	public array $inserted = [];
	/** @var list<array{after: int, limit: int}> */
	public array $queries = [];
	public int $booleanLookups = 0;
	/** Was die Datenbank beim Einfügen wirft (z. B. „Wert zu lang“). */
	public ?\Throwable $failInsert = null;

	/**
	 * @param list<array<string, mixed>> $table Zeilen, die `rows()` liefert
	 * @param list<string> $booleans Spalten, die auf PostgreSQL boolean sind
	 */
	public function __construct(
		IDBConnection $db,
		IConfig $config,
		IL10N $l10n,
		private array $table = [],
		private array $booleans = [],
	) {
		parent::__construct($db, $config, $l10n);
	}

	protected function fetchBlock(string $table, int $afterId, int $limit): array {
		$this->queries[] = ['after' => $afterId, 'limit' => $limit];
		return array_slice(array_values(array_filter($this->table, static fn (array $r): bool => $r['id'] > $afterId)), 0, $limit);
	}

	protected function insertRow(string $table, array $values, array $types): void {
		if ($this->failInsert !== null) {
			throw $this->failInsert;
		}
		$this->inserted[] = ['table' => $table, 'values' => $values, 'types' => $types];
	}

	protected function loadBooleanColumns(string $table): array {
		$this->booleanLookups++;
		return $this->booleans;
	}
}
