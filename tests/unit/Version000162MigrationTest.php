<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Migration\Version000162Date20261024000000;
use OCP\DB\IResult;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Die Reparatur gespeicherter Texte beim Update (Issue #112): was geschrieben
 * und protokolliert wird. Welche Texte betroffen sind, prüft
 * EntityRepairPlannerTest; die Datenbank selbst bleibt dem Lauf gegen eine
 * echte Instanz vorbehalten.
 */
class Version000162MigrationTest extends TestCase {

	private IDBConnection&MockObject $db;
	private IOutput&MockObject $output;

	/** @var list<array{table: string, set: array<string, mixed>}> */
	private array $updates = [];
	/** @var list<array<string, mixed>> */
	private array $audits = [];

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->output = $this->createMock(IOutput::class);
	}

	/** @param list<string> $tables */
	private function schema(array $tables): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(static fn (string $t): bool => in_array($t, $tables, true));
		return $schema;
	}

	/** @param array<string, list<array<string, mixed>>> $candidates Kandidaten je Tabelle */
	private function database(array $candidates): void {
		$this->db->method('getQueryBuilder')->willReturnCallback(function () use ($candidates): IQueryBuilder {
			$state = ['table' => null, 'set' => [], 'insert' => null, 'values' => []];
			$qb = $this->createMock(IQueryBuilder::class);
			$expr = $this->createMock(IExpressionBuilder::class);
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturnArgument(0);
			foreach (['select', 'where', 'orderBy'] as $fluent) {
				$qb->method($fluent)->willReturnSelf();
			}
			$qb->method('from')->willReturnCallback(function (string $table) use ($qb, &$state): IQueryBuilder {
				$state['table'] = $table;
				return $qb;
			});
			$qb->method('update')->willReturnCallback(function (string $table) use ($qb, &$state): IQueryBuilder {
				$state['table'] = $table;
				return $qb;
			});
			$qb->method('set')->willReturnCallback(function (string $column, mixed $value) use ($qb, &$state): IQueryBuilder {
				$state['set'][$column] = $value;
				return $qb;
			});
			$qb->method('insert')->willReturnCallback(function (string $table) use ($qb, &$state): IQueryBuilder {
				$state['insert'] = $table;
				return $qb;
			});
			$qb->method('values')->willReturnCallback(function (array $values) use ($qb, &$state): IQueryBuilder {
				$state['values'] = $values;
				return $qb;
			});
			$qb->method('executeQuery')->willReturnCallback(function () use ($candidates, &$state): IResult {
				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($candidates[$state['table']] ?? []);
				return $result;
			});
			$qb->method('executeStatement')->willReturnCallback(function () use (&$state): int {
				if ($state['insert'] !== null) {
					$this->audits[] = $state['values'];
				} else {
					$this->updates[] = ['table' => $state['table'], 'set' => $state['set']];
				}
				return 1;
			});
			return $qb;
		});
	}

	private function migrate(ISchemaWrapper $schema): void {
		(new Version000162Date20261024000000($this->db))->postSchemaChange($this->output, static fn () => $schema, []);
	}

	public function testReparierteTexteWerdenGeschriebenUndProtokolliert(): void {
		$this->database([
			'vbh_journal' => [
				['id' => '5', 'description' => 'Beitrag M&amp;#252;ller', 'document_ref' => 'B1', 'entry_no' => '12', 'date' => '2024-03-01'],
				['id' => '6', 'description' => 'Rechnung #12;', 'document_ref' => null, 'entry_no' => '13', 'date' => '2024-03-02'],
			],
			'vbh_accounts' => [
				['id' => '3', 'name' => 'Spenden f&#252;r Jugend', 'number' => '4100'],
			],
		]);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->output->expects($this->once())->method('info')->with($this->stringContains('2 Texte'));

		$this->migrate($this->schema(['vbh_journal', 'vbh_accounts', 'vbh_audit_log']));

		$this->assertCount(2, $this->updates);
		$this->assertSame('vbh_journal', $this->updates[0]['table']);
		$this->assertSame('Beitrag Müller', $this->updates[0]['set']['description']);
		$this->assertArrayHasKey('updated_at', $this->updates[0]['set']);
		$this->assertSame(['name' => 'Spenden für Jugend'], $this->updates[1]['set']);

		$this->assertCount(2, $this->audits);
		$this->assertSame('Sonderzeichen repariert', $this->audits[0]['action']);
		$this->assertSame('system', $this->audits[0]['user_id']);
		$this->assertSame('journal', $this->audits[0]['object_type']);
		$this->assertSame(5, $this->audits[0]['object_id']);
		$this->assertSame(
			['entryNo' => 12, 'date' => '2024-03-01', 'feld' => 'description', 'vorher' => 'Beitrag M&amp;#252;ller', 'nachher' => 'Beitrag Müller'],
			json_decode($this->audits[0]['details'], true),
		);
		$this->assertSame('account', $this->audits[1]['object_type']);
		$this->assertSame('4100', json_decode($this->audits[1]['details'], true)['konto']);
	}

	public function testOhneBetroffeneTexteKeineTransaktion(): void {
		$this->database(['vbh_journal' => [['id' => '6', 'description' => 'Rechnung #12;', 'document_ref' => null, 'entry_no' => null, 'date' => '2024-03-02']]]);
		$this->db->expects($this->never())->method('beginTransaction');
		$this->output->expects($this->never())->method('info');

		$this->migrate($this->schema(['vbh_journal', 'vbh_accounts', 'vbh_audit_log']));

		$this->assertSame([], $this->updates);
		$this->assertSame([], $this->audits);
	}

	public function testOhneProtokollTabelleWirdNichtsAbgefragt(): void {
		$this->db->expects($this->never())->method('getQueryBuilder');

		$this->migrate($this->schema(['vbh_journal', 'vbh_accounts']));
	}

	public function testKeinSchemaEingriff(): void {
		$step = new Version000162Date20261024000000($this->db);
		$this->assertNull($step->changeSchema($this->output, fn () => $this->schema([]), []));
	}
}
