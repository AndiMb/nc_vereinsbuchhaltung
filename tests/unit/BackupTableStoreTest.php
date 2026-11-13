<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\RecordingBackupTables;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Tabellenzugriff der Sicherung (Issue #111). Die eigentlichen Abfragen
 * laufen im E2E-Test gegen eine echte Datenbank; hier geht es um das, was
 * zwischen den Datenbanksystemen verschieden ist: Parametertypen,
 * Wahrheitswerte auf PostgreSQL, die Sequenz nach dem Einfügen mit festen IDs.
 */
class BackupTableStoreTest extends TestCase {

	/**
	 * @param list<array<string, mixed>> $table
	 * @param list<string> $booleans
	 */
	private function store(string $platform, array $table = [], array $booleans = []): RecordingBackupTables {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn($platform);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(static fn (string $key, string $default = ''): string => $key === 'dbtableprefix' ? 'oc_' : $default);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => $params === [] ? $text : vsprintf($text, $params));

		return new RecordingBackupTables($db, $config, $l10n, $table, $booleans);
	}

	public function testRowsLiestBlockweiseInSchluesselreihenfolge(): void {
		$table = array_map(static fn (int $id): array => ['id' => $id, 'name' => 'Zeile ' . $id], range(1, 2500));
		$store = $this->store(IDBConnection::PLATFORM_SQLITE, $table);

		$ids = [];
		foreach ($store->rows('vbh_accounts') as $row) {
			$ids[] = $row['id'];
		}

		$this->assertSame(range(1, 2500), $ids);
		$this->assertSame([0, 1000, 2000], array_column($store->queries, 'after'));
		$this->assertSame([1000, 1000, 1000], array_column($store->queries, 'limit'));
	}

	public function testRowsLeereTabelle(): void {
		$store = $this->store(IDBConnection::PLATFORM_SQLITE, []);
		$this->assertSame([], iterator_to_array($store->rows('vbh_accounts'), false));
		$this->assertCount(1, $store->queries);
	}

	public function testInsertBindetNachWert(): void {
		$store = $this->store(IDBConnection::PLATFORM_SQLITE);

		$count = $store->insert('vbh_accounts', [
			['id' => 7, 'name' => 'Bank', 'is_bank' => 1, 'iban' => null, 'rate' => 1.5, 'active' => true],
			['id' => 8, 'name' => 'Kasse', 'is_bank' => 0, 'iban' => null, 'rate' => 0.0, 'active' => false],
		]);

		$this->assertSame(2, $count);
		$this->assertSame('vbh_accounts', $store->inserted[0]['table']);
		$this->assertSame(['id' => 7, 'name' => 'Bank', 'is_bank' => 1, 'iban' => null, 'rate' => '1.5', 'active' => true], $store->inserted[0]['values']);
		$this->assertSame([
			'id' => IQueryBuilder::PARAM_INT,
			'name' => IQueryBuilder::PARAM_STR,
			'is_bank' => IQueryBuilder::PARAM_INT,
			'iban' => IQueryBuilder::PARAM_NULL,
			'rate' => IQueryBuilder::PARAM_STR,
			'active' => IQueryBuilder::PARAM_BOOL,
		], $store->inserted[0]['types']);
		$this->assertSame(0, $store->booleanLookups, 'Nur PostgreSQL braucht die Wahrheitswert-Spalten.');
	}

	/**
	 * Umzug von SQLite/MySQL nach PostgreSQL: die Quelle liefert 0/1, die
	 * Zielspalte ist ein echter Wahrheitswert und nimmt keine Zahl.
	 */
	public function testPostgresBindetWahrheitswertSpaltenAlsBool(): void {
		$store = $this->store(IDBConnection::PLATFORM_POSTGRES, [], ['is_bank', 'archived']);

		$store->insert('vbh_accounts', [
			['id' => 7, 'is_bank' => 1, 'archived' => 0, 'sort' => 1],
			['id' => 8, 'is_bank' => '0', 'archived' => null, 'sort' => 0],
		]);

		$this->assertSame(['id' => 7, 'is_bank' => true, 'archived' => false, 'sort' => 1], $store->inserted[0]['values']);
		$this->assertSame(IQueryBuilder::PARAM_BOOL, $store->inserted[0]['types']['is_bank']);
		$this->assertSame(IQueryBuilder::PARAM_INT, $store->inserted[0]['types']['sort']);
		$this->assertSame(['id' => 8, 'is_bank' => false, 'archived' => null, 'sort' => 0], $store->inserted[1]['values']);
		$this->assertSame(IQueryBuilder::PARAM_NULL, $store->inserted[1]['types']['archived']);
		$this->assertSame(1, $store->booleanLookups, 'Die Spalten werden je Tabelle einmal ermittelt.');
	}

	/** Umzug von PostgreSQL nach MySQL/SQLite: true/false landen als 1/0. */
	public function testWahrheitswerteAusPostgresWerdenAnderswoZuZahlen(): void {
		$store = $this->store(IDBConnection::PLATFORM_MYSQL);
		$store->insert('vbh_accounts', [['id' => 7, 'is_bank' => true]]);
		$this->assertSame(IQueryBuilder::PARAM_BOOL, $store->inserted[0]['types']['is_bank']);
		$this->assertTrue($store->inserted[0]['values']['is_bank']);
	}

	public function testSpaltennameAusDerDateiWirdGeprueft(): void {
		$store = $this->store(IDBConnection::PLATFORM_SQLITE);
		foreach (['na me', 'x; DROP TABLE y', 'Name', '', 'a`b'] as $column) {
			try {
				$store->insert('vbh_accounts', [['id' => 1, $column => 'x']]);
				$this->fail('Spaltenname hätte abgelehnt werden müssen: ' . $column);
			} catch (UserFacingException $e) {
				$this->assertStringContainsString('vbh_accounts', $e->getMessage());
			}
		}
		$this->assertSame([], $store->inserted);
	}

	public function testSequenzAnweisungNurFuerPostgres(): void {
		$this->assertSame(
			["SELECT setval(pg_get_serial_sequence('oc_vbh_journal', 'id'), 4711)"],
			$this->store(IDBConnection::PLATFORM_POSTGRES)->sequenceStatements('vbh_journal', 4711),
		);
		$this->assertSame([], $this->store(IDBConnection::PLATFORM_POSTGRES)->sequenceStatements('vbh_journal', 0));
		$this->assertSame([], $this->store(IDBConnection::PLATFORM_MYSQL)->sequenceStatements('vbh_journal', 4711));
		$this->assertSame([], $this->store(IDBConnection::PLATFORM_MARIADB)->sequenceStatements('vbh_journal', 4711));
		$this->assertSame([], $this->store(IDBConnection::PLATFORM_SQLITE)->sequenceStatements('vbh_journal', 4711));
	}

	public function testTabellennameWirdGeprueft(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->store(IDBConnection::PLATFORM_POSTGRES)->sequenceStatements("x'); DROP TABLE y; --", 1);
	}

	/**
	 * Was SQLite durchließ, lehnt eine andere Datenbank womöglich ab (Text zu
	 * lang für die Spalte, Zeichen außerhalb des Zeichensatzes). Die Meldung
	 * nennt dann Tabelle und Datensatz – ohne den Wert.
	 */
	public function testDatenbankfehlerBeimEinfuegenNenntTabelleUndDatensatz(): void {
		$store = $this->store(IDBConnection::PLATFORM_MYSQL);
		$store->failInsert = new \RuntimeException("SQLSTATE[22001]: Data too long for column 'name' — DE89370400440532013000");

		try {
			$store->insert('vbh_members', [['id' => 7, 'name' => 'A'], ['id' => 8, 'name' => 'B']]);
			$this->fail('Das Einfügen hätte scheitern müssen.');
		} catch (UserFacingException $e) {
			$this->assertSame('Der Datensatz 7 der Tabelle vbh_members lässt sich in dieser Datenbank nicht speichern.', $e->getMessage());
			$this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
		}
	}
}
