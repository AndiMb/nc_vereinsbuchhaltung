<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use OCA\Vereinsbuchhaltung\Migration\Version000163Date20261025000000;
use OCA\Vereinsbuchhaltung\Service\FieldCipher;
use OCP\DB\IResult;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Das Verschlüsseln der Bestandswerte beim Update: welche Spalten wachsen,
 * was geschrieben wird. Die Datenbank selbst bleibt dem Lauf gegen eine
 * echte Instanz vorbehalten.
 */
class Version000163MigrationTest extends TestCase {

	private IDBConnection&MockObject $db;
	private IOutput&MockObject $output;
	private FieldCipher $cipher;

	/** @var list<array{table: string, set: array<string, mixed>, id: mixed}> */
	private array $updates = [];
	/** @var array<string, array<string, int>> Tabelle => [Spalte => neue Länge] */
	private array $widened = [];

	protected function setUp(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('instanz-secret');
		$this->cipher = new FieldCipher($config);
		$this->db = $this->createMock(IDBConnection::class);
		$this->output = $this->createMock(IOutput::class);
	}

	private function assertEncrypted(string $expected, mixed $written): void {
		$this->assertIsString($written);
		$this->assertTrue(FieldCipher::isEncrypted($written), 'Klartext statt Chiffrat geschrieben');
		$this->assertSame($expected, $this->cipher->decrypt($written));
	}

	/** @param array<string, list<array<string, mixed>>> $rows Zeilen je Tabelle */
	private function database(array $rows): void {
		$this->db->method('getQueryBuilder')->willReturnCallback(function () use ($rows): IQueryBuilder {
			$state = ['table' => null, 'set' => [], 'eq' => []];
			$qb = $this->createMock(IQueryBuilder::class);
			$expr = $this->createMock(IExpressionBuilder::class);
			$expr->method('eq')->willReturnCallback(function (string $column, mixed $value) use (&$state): string {
				$state['eq'][] = [$column, $value];
				return '';
			});
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturnArgument(0);
			foreach (['select', 'where', 'andWhere', 'orderBy'] as $fluent) {
				$qb->method($fluent)->willReturnSelf();
			}
			foreach (['from', 'update'] as $method) {
				$qb->method($method)->willReturnCallback(function (string $table) use ($qb, &$state): IQueryBuilder {
					$state['table'] = $table;
					return $qb;
				});
			}
			$qb->method('set')->willReturnCallback(function (string $column, mixed $value) use ($qb, &$state): IQueryBuilder {
				$state['set'][$column] = $value;
				return $qb;
			});
			$qb->method('executeQuery')->willReturnCallback(function () use ($rows, &$state): IResult {
				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($rows[$state['table']] ?? []);
				return $result;
			});
			$qb->method('executeStatement')->willReturnCallback(function () use (&$state): int {
				$this->updates[] = ['table' => $state['table'], 'set' => $state['set'], 'id' => $state['eq'][0][1] ?? null];
				return 1;
			});
			return $qb;
		});
	}

	/** @param array<string, array<string, int>> $tables Tabelle => [Spalte => Länge] */
	private function schema(array $tables): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(static fn (string $t): bool => isset($tables[$t]));
		$schema->method('getTable')->willReturnCallback(function (string $name) use ($tables): Table {
			$table = $this->createMock(Table::class);
			$table->method('hasColumn')->willReturnCallback(static fn (string $c): bool => isset($tables[$name][$c]));
			$table->method('getColumn')->willReturnCallback(function (string $c) use ($tables, $name): Column {
				$column = $this->createMock(Column::class);
				$column->method('getLength')->willReturn($tables[$name][$c]);
				return $column;
			});
			$table->method('changeColumn')->willReturnCallback(function (string $c, array $options) use ($name, $table): Table {
				$this->widened[$name][$c] = $options['length'];
				return $table;
			});
			return $table;
		});
		return $schema;
	}

	private function migration(): Version000163Date20261025000000 {
		return new Version000163Date20261025000000($this->db, $this->cipher);
	}

	public function testSpaltenWachsenFuerDasChiffrat(): void {
		$schema = $this->schema([
			'vbh_mandates' => ['iban' => 34],
			'vbh_bank_tx' => ['counterparty_iban' => 40],
			'vbh_rules' => ['match_value' => 255],
			'vbh_debit_items' => ['iban' => 255],
		]);

		$result = $this->migration()->changeSchema($this->output, static fn () => $schema, []);

		$this->assertSame($schema, $result);
		$this->assertSame([
			'vbh_mandates' => ['iban' => 255],
			'vbh_bank_tx' => ['counterparty_iban' => 255],
			'vbh_rules' => ['match_value' => 512],
		], $this->widened);
	}

	public function testOhneZuKurzeSpaltenKeinSchemaEingriff(): void {
		$schema = $this->schema(['vbh_mandates' => ['iban' => 255]]);

		$this->assertNull($this->migration()->changeSchema($this->output, static fn () => $schema, []));
	}

	public function testBestandWirdVerschluesselt(): void {
		$this->database([
			'vbh_mandates' => [
				['id' => '1', 'iban' => 'DE89370400440532013000'],
				['id' => '2', 'iban' => $this->cipher->encrypt('AT611904300234573201')],
				['id' => '3', 'iban' => ''],
			],
			'vbh_bank_tx' => [['id' => '5', 'counterparty_iban' => 'NL91ABNA0417164300']],
			'vbh_rules' => [
				['id' => '6', 'match_value' => 'DE02120300000000202051'],
				['id' => '7', 'match_value' => 'Stadtwerke'],
			],
		]);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->output->expects($this->once())->method('info')->with($this->stringContains('4 Werte'));

		$schema = $this->schema([
			'vbh_mandates' => ['iban' => 255],
			'vbh_bank_tx' => ['counterparty_iban' => 255],
			'vbh_rules' => ['match_value' => 512],
		]);
		$this->migration()->postSchemaChange($this->output, static fn () => $schema, []);

		$this->assertSame(['vbh_mandates', 'vbh_bank_tx', 'vbh_rules', 'vbh_rules'], array_column($this->updates, 'table'));
		$this->assertSame([1, 5, 6, 7], array_column($this->updates, 'id'));
		$this->assertEncrypted('DE89370400440532013000', $this->updates[0]['set']['iban']);
		$this->assertEncrypted('NL91ABNA0417164300', $this->updates[1]['set']['counterparty_iban']);
		$this->assertEncrypted('DE02120300000000202051', $this->updates[2]['set']['match_value']);
		$this->assertEncrypted('Stadtwerke', $this->updates[3]['set']['match_value']);
	}

	public function testZweiterLaufAendertNichts(): void {
		$this->database(['vbh_mandates' => [['id' => '2', 'iban' => $this->cipher->encrypt('DE89370400440532013000')]]]);
		$this->output->expects($this->never())->method('info');

		$schema = $this->schema(['vbh_mandates' => ['iban' => 255]]);
		$this->migration()->postSchemaChange($this->output, static fn () => $schema, []);

		$this->assertSame([], $this->updates);
	}
}
