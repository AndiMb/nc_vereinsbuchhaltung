<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Migration\Version000158Date20261010000000;
use OCP\DB\IResult;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Die Übernahme der Alt-Daten (Issue #107, Nachtrag): hier der Schema-Teil. Beim ersten
 * Lauf in der CI lief `changeSchema()` gegen eine frische Installation und fragte eine
 * Tabelle ab, die Nextcloud dort nur im Schema im Speicher führt – „no such table".
 * Der Datenteil (Planung in LegacyContributionPlannerTest) ist gegen eine echte Instanz
 * mit Alt-Tabellen geprüft; die Datenbank selbst bleibt dem Lauf dort vorbehalten.
 */
class Version000158MigrationTest extends TestCase {

	private const LEGACY = ['vbh_sepa_batch_items', 'vbh_sepa_batches', 'vbh_membership_fees', 'vbh_sepa_mandates'];

	private IDBConnection&MockObject $db;
	private IOutput&MockObject $output;

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->output = $this->createMock(IOutput::class);
	}

	/**
	 * @param list<string> $inSchema Tabellen im Schema
	 * @param list<string> $dropped hier landen die aus dem Schema genommenen Tabellen
	 */
	private function schema(array $inSchema, array &$dropped): ISchemaWrapper&MockObject {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(static fn (string $table): bool => in_array($table, $inSchema, true));
		$schema->method('dropTable')->willReturnCallback(static function (string $table) use (&$dropped): void {
			$dropped[] = $table;
		});
		return $schema;
	}

	/**
	 * @param list<string> $physical Tabellen, die es in der Datenbank wirklich gibt
	 * @param list<string> $withRows davon die mit mindestens einer Zeile
	 * @param list<string> $queried hier landen die abgefragten Tabellen
	 */
	private function database(array $physical, array $withRows, array &$queried): void {
		$this->db->method('tableExists')->willReturnCallback(static fn (string $table): bool => in_array($table, $physical, true));
		$this->db->method('getQueryBuilder')->willReturnCallback(function () use ($withRows, &$queried): IQueryBuilder {
			$qb = $this->createMock(IQueryBuilder::class);
			$qb->method('select')->willReturnSelf();
			$qb->method('setMaxResults')->willReturnSelf();
			$qb->method('from')->willReturnCallback(function (string $table) use ($qb, $withRows, &$queried): IQueryBuilder {
				$queried[] = $table;
				$result = $this->createMock(IResult::class);
				$result->method('fetchOne')->willReturn(in_array($table, $withRows, true) ? '1' : false);
				$qb->method('executeQuery')->willReturn($result);
				return $qb;
			});
			return $qb;
		});
	}

	private function changeSchema(ISchemaWrapper $schema): ?ISchemaWrapper {
		$step = new Version000158Date20261010000000($this->db);
		return $step->changeSchema($this->output, static fn () => $schema, []);
	}

	/**
	 * Neuinstallation: alle Migrationen laufen nur schemaseitig, die Alt-Tabellen gibt es nur im Speicher.
	 * Nichts darf abgefragt werden; die Tabellen fallen aus dem Schema, werden also nie angelegt.
	 */
	public function testNeuinstallationFragtNichtsAbUndNimmtDieAltTabellenAusDemSchema(): void {
		$dropped = [];
		$queried = [];
		$this->database([], [], $queried);

		$result = $this->changeSchema($this->schema(self::LEGACY, $dropped));

		$this->assertNotNull($result);
		$this->assertSame([], $queried, 'Eine nur im Speicher geführte Tabelle darf nicht abgefragt werden.');
		$this->assertEqualsCanonicalizing(self::LEGACY, $dropped);
	}

	public function testLeereTabellenInDerDatenbankWerdenEntfernt(): void {
		$dropped = [];
		$queried = [];
		$this->database(self::LEGACY, [], $queried);

		$result = $this->changeSchema($this->schema(self::LEGACY, $dropped));

		$this->assertNotNull($result);
		$this->assertEqualsCanonicalizing(self::LEGACY, $dropped);
	}

	/** Mandate und Sammeleinzüge mit Zeilen bleiben stehen; die leeren Schwestern fallen weg. */
	public function testTabellenMitZeilenBleibenStehen(): void {
		$dropped = [];
		$queried = [];
		$this->database(self::LEGACY, ['vbh_sepa_mandates', 'vbh_sepa_batches'], $queried);

		$result = $this->changeSchema($this->schema(self::LEGACY, $dropped));

		$this->assertNotNull($result);
		$this->assertEqualsCanonicalizing(['vbh_sepa_batch_items', 'vbh_membership_fees'], $dropped);
	}

	public function testSindAlleTabellenGefuelltGibtEsKeinenSchemaEingriff(): void {
		$dropped = [];
		$queried = [];
		$this->database(self::LEGACY, self::LEGACY, $queried);

		$result = $this->changeSchema($this->schema(self::LEGACY, $dropped));

		$this->assertNull($result);
		$this->assertSame([], $dropped);
	}

	/** Gibt es die Alt-Tabellen im Schema gar nicht (Modul nie benutzt, schon aufgeräumt), ist der Schritt leer. */
	public function testOhneAltTabellenImSchemaIstDerSchrittLeer(): void {
		$dropped = [];
		$queried = [];
		$this->database([], [], $queried);

		$result = $this->changeSchema($this->schema(['vbh_mandates', 'vbh_members'], $dropped));

		$this->assertNull($result);
		$this->assertSame([], $dropped);
		$this->assertSame([], $queried);
	}

	/** Fehlen die Tabellen des neuen Modells, übernimmt der Datenteil nichts und fasst die Datenbank nicht an. */
	public function testDatenteilOhneNeuesModellTutNichts(): void {
		$dropped = [];
		$this->db->expects($this->never())->method('getQueryBuilder');
		$schema = $this->schema(self::LEGACY, $dropped);

		$step = new Version000158Date20261010000000($this->db);
		$step->postSchemaChange($this->output, static fn () => $schema, []);

		$this->assertSame([], $dropped);
	}
}
