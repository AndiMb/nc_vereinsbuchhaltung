<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Backup;

use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Roher Tabellenzugriff der Sicherung (Issue #111): Zeilen einer Tabelle
 * lesen, wie die Datenbank sie führt, und mit unveränderten
 * Primärschlüsseln wieder einfügen.
 *
 * Bewusst an den Mappern vorbei: die Sicherung soll jede Spalte tragen, auch
 * solche, die keine Entity mehr kennt, und beim Einspielen darf keine
 * Fachlogik (Festschreibung, Nummernkreise, Protokoll) dazwischenfunken.
 * Was die Mapper sonst leisten – Verschlüsselung einzelner Spalten –,
 * erledigen Export und Import selbst ({@see BackupSchema}).
 *
 * Tabellennamen werden ohne Präfix übergeben und kommen ausschließlich aus
 * {@see BackupSchema}; Spaltennamen kommen beim Einfügen aus der
 * Sicherungsdatei und werden deshalb geprüft.
 */
class BackupTableStore {

	public const BLOCK = 1000;

	/** @var array<string, list<string>> */
	private array $booleanColumns = [];

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
		private IL10N $l10n,
	) {
	}

	public function exists(string $table): bool {
		return $this->db->tableExists($table);
	}

	public function count(string $table): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($table);
		$res = $qb->executeQuery();
		$count = (int)$res->fetchOne();
		$res->closeCursor();
		return $count;
	}

	/** Größter Primärschlüssel, 0 bei leerer Tabelle. */
	public function maxId(string $table): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max(BackupSchema::PK))->from($table);
		$res = $qb->executeQuery();
		$max = (int)$res->fetchOne();
		$res->closeCursor();
		return $max;
	}

	/**
	 * Alle Zeilen in Schlüsselreihenfolge, blockweise geholt.
	 *
	 * @return \Generator<int, array<string, mixed>>
	 */
	public function rows(string $table): \Generator {
		$last = 0;
		do {
			$block = $this->fetchBlock($table, $last, self::BLOCK);
			foreach ($block as $row) {
				$last = (int)$row[BackupSchema::PK];
				yield $row;
			}
		} while (count($block) === self::BLOCK);
	}

	/**
	 * Fügt Zeilen mit allen ihren Spalten ein, auch dem Primärschlüssel.
	 *
	 * @param iterable<array<string, mixed>> $rows
	 * @return int Anzahl eingefügter Zeilen
	 * @throws UserFacingException wenn die Zeilen nicht zur Tabelle passen können
	 */
	public function insert(string $table, iterable $rows): int {
		$booleans = $this->isPostgres() ? $this->booleanColumns($table) : [];
		$count = 0;
		foreach ($rows as $row) {
			$values = [];
			$types = [];
			foreach ($row as $column => $value) {
				if (!is_string($column) || preg_match('/^[a-z][a-z0-9_]*$/', $column) !== 1) {
					throw new UserFacingException($this->l10n->t('Die Sicherung passt nicht zu dieser App-Version (Tabelle %1$s, Spalte %2$s).', [$table, (string)$column]));
				}
				if ($value !== null && in_array($column, $booleans, true)) {
					// SQLite und MySQL liefern 0/1, PostgreSQL verlangt für boolean einen Wahrheitswert.
					$value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
				}
				if (is_float($value) || is_array($value)) {
					$value = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (string)$value;
				}
				$values[$column] = $value;
				$types[$column] = match (true) {
					$value === null => IQueryBuilder::PARAM_NULL,
					is_bool($value) => IQueryBuilder::PARAM_BOOL,
					is_int($value) => IQueryBuilder::PARAM_INT,
					default => IQueryBuilder::PARAM_STR,
				};
			}
			if ($values === []) {
				continue;
			}
			$this->insertRow($table, $values, $types);
			$count++;
		}
		return $count;
	}

	/** Leert die Tabelle. `DELETE` statt `TRUNCATE`, damit es in der Transaktion zurückrollbar bleibt. */
	public function truncate(string $table): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)->executeStatement();
	}

	/**
	 * Nach dem Einfügen mit festen IDs kennt PostgreSQL den Stand seiner
	 * Sequenz nicht – die nächste neue Zeile bekäme eine vergebene ID. MySQL,
	 * MariaDB und SQLite ziehen den Zähler selbst nach.
	 *
	 * @return list<string>
	 */
	public function sequenceStatements(string $table, int $max): array {
		if (preg_match('/^[a-z][a-z0-9_]*$/', $table) !== 1) {
			throw new \InvalidArgumentException('Ungültiger Tabellenname.');
		}
		if (!$this->isPostgres() || $max < 1) {
			return [];
		}
		return [sprintf("SELECT setval(pg_get_serial_sequence('%s', '%s'), %d)", $this->prefix() . $table, BackupSchema::PK, $max)];
	}

	public function syncSequence(string $table): void {
		foreach ($this->sequenceStatements($table, $this->maxId($table)) as $sql) {
			$this->db->executeQuery($sql)->closeCursor();
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	protected function fetchBlock(string $table, int $afterId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($table)
			->where($qb->expr()->gt(BackupSchema::PK, $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
			->orderBy(BackupSchema::PK, 'ASC')
			->setMaxResults($limit);
		$res = $qb->executeQuery();
		$rows = $res->fetchAll();
		$res->closeCursor();
		return array_values($rows);
	}

	/**
	 * @param array<string, mixed> $values
	 * @param array<string, IQueryBuilder::PARAM_NULL|IQueryBuilder::PARAM_BOOL|IQueryBuilder::PARAM_INT|IQueryBuilder::PARAM_STR> $types
	 */
	protected function insertRow(string $table, array $values, array $types): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($table);
		foreach ($values as $column => $value) {
			$qb->setValue($column, $qb->createNamedParameter($value, $types[$column]));
		}
		$qb->executeStatement();
	}

	/**
	 * Spalten vom Typ boolean (nur PostgreSQL).
	 *
	 * @return list<string>
	 */
	protected function loadBooleanColumns(string $table): array {
		$res = $this->db->executeQuery(
			"SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND data_type = 'boolean'",
			[$this->prefix() . $table],
		);
		$columns = array_map(static fn (array $row): string => (string)$row['column_name'], $res->fetchAll());
		$res->closeCursor();
		return array_values($columns);
	}

	/** @return list<string> */
	private function booleanColumns(string $table): array {
		return $this->booleanColumns[$table] ??= $this->loadBooleanColumns($table);
	}

	private function isPostgres(): bool {
		return $this->db->getDatabaseProvider() === IDBConnection::PLATFORM_POSTGRES;
	}

	private function prefix(): string {
		return $this->config->getSystemValueString('dbtableprefix', 'oc_');
	}
}
