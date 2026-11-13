<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit\Support;

use OCA\Vereinsbuchhaltung\Service\Backup\BackupSchema;
use OCA\Vereinsbuchhaltung\Service\Backup\BackupTableStore;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * {@see BackupTableStore} über einem Array statt einer Datenbank – für die
 * Tests von Export, Import und Leeren. Die Prüf- und Umwandlungslogik von
 * `insert()` bleibt die echte; ersetzt sind nur die Stellen, die SQL absetzen.
 *
 * Transaktionen bildet {@see snapshot()}/{@see restore()} nach: die Attrappe
 * der Datenbankverbindung ruft sie bei BEGIN und ROLLBACK auf.
 */
class InMemoryBackupTables extends BackupTableStore {

	/** @var array<string, array<int, array<string, mixed>>> Tabelle → id → Zeile */
	public array $data = [];
	/** @var list<string> */
	public array $synced = [];
	/** @var array<string, array<int, array<string, mixed>>>|null */
	private ?array $snapshot = null;
	public IDBConnection $connection;

	/**
	 * @param array<string, list<array<string, mixed>>> $rows Anfangsbestand; alle Tabellen des Verzeichnisses existieren (leer)
	 */
	public static function create(TestCase $t, array $rows = []): self {
		$mock = new \ReflectionMethod($t, 'createMock');
		$db = $mock->invoke($t, IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_SQLITE);
		$l10n = $mock->invoke($t, IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => $params === [] ? $text : vsprintf($text, $params));

		$store = new self($db, $mock->invoke($t, IConfig::class), $l10n);
		$store->connection = $db;
		$db->method('beginTransaction')->willReturnCallback(static function () use ($store): void {
			$store->snapshot();
		});
		$db->method('rollBack')->willReturnCallback(static function () use ($store): void {
			$store->restore();
		});
		foreach (array_merge(array_keys(BackupSchema::tables()), BackupSchema::excludedTables()) as $table) {
			$store->data[$table] = [];
		}
		foreach ($rows as $table => $list) {
			foreach ($list as $row) {
				$store->data[$table][(int)$row['id']] = $row;
			}
		}
		return $store;
	}

	public function snapshot(): void {
		$this->snapshot = $this->data;
	}

	public function restore(): void {
		if ($this->snapshot !== null) {
			$this->data = $this->snapshot;
		}
	}

	/** @return list<array<string, mixed>> */
	public function all(string $table): array {
		$rows = $this->data[$table] ?? [];
		ksort($rows);
		return array_values($rows);
	}

	public function exists(string $table): bool {
		return array_key_exists($table, $this->data);
	}

	public function count(string $table): int {
		return count($this->data[$table] ?? []);
	}

	public function maxId(string $table): int {
		return ($this->data[$table] ?? []) === [] ? 0 : max(array_keys($this->data[$table]));
	}

	public function truncate(string $table): void {
		$this->data[$table] = [];
	}

	public function syncSequence(string $table): void {
		$this->synced[] = $table;
	}

	protected function fetchBlock(string $table, int $afterId, int $limit): array {
		return array_slice(array_values(array_filter($this->all($table), static fn (array $r): bool => (int)$r['id'] > $afterId)), 0, $limit);
	}

	protected function insertRow(string $table, array $values, array $types): void {
		$id = (int)$values['id'];
		if (isset($this->data[$table][$id])) {
			throw new \RuntimeException('UNIQUE constraint failed: ' . $table . '.id');
		}
		$this->data[$table][$id] = $values;
	}

	protected function loadBooleanColumns(string $table): array {
		return [];
	}
}
