<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Migration\Version000172Date20261102000000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Die Indizes für periodenbegrenzte Listen (#255): werden angelegt, ein zweiter
 * Lauf ändert nichts. Gegen einen gemockten Schema-Wrapper geprüft.
 */
class Version000172MigrationTest extends TestCase {

	/**
	 * @param array<string, list<string>> $existingIndexes Tabelle => vorhandene Indexnamen
	 * @param list<array{string, string, list<string>}> $added hier landen (Tabelle, Index, Spalten)
	 */
	private function migrate(array $existingIndexes, array &$added): ?ISchemaWrapper {
		$tables = [];
		$schema = $this->createMock(ISchemaWrapper::class);
		foreach ($existingIndexes as $name => $indexes) {
			$table = $this->createMock(\Doctrine\DBAL\Schema\Table::class);
			$table->method('hasIndex')->willReturnCallback(static fn (string $i): bool => in_array($i, $indexes, true));
			$table->method('addIndex')->willReturnCallback(static function (array $columns, string $index) use (&$added, $name, $table) {
				$added[] = [$name, $index, $columns];
				return $table;
			});
			$tables[$name] = $table;
		}
		$schema->method('hasTable')->willReturnCallback(static fn (string $t): bool => isset($tables[$t]));
		$schema->method('getTable')->willReturnCallback(static fn (string $t) => $tables[$t]);

		return (new Version000172Date20261102000000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
	}

	public function testLegtBeideIndizesAn(): void {
		$added = [];
		$result = $this->migrate(['vbh_attachments' => [], 'vbh_journal_line' => []], $added);

		$this->assertNotNull($result);
		$this->assertSame([
			['vbh_attachments', 'vbh_attach_user_journal', ['user_id', 'journal_id']],
			['vbh_journal_line', 'vbh_jl_journal_account', ['journal_id', 'account_id']],
		], $added);
	}

	public function testZweiterLaufAendertNichts(): void {
		$added = [];
		$result = $this->migrate([
			'vbh_attachments' => ['vbh_attach_user_journal'],
			'vbh_journal_line' => ['vbh_jl_journal_account'],
		], $added);

		$this->assertNull($result);
		$this->assertSame([], $added);
	}

	public function testFehlendeTabelleWirdUebersprungen(): void {
		$added = [];
		$result = $this->migrate(['vbh_journal_line' => []], $added);

		$this->assertNotNull($result);
		$this->assertSame([['vbh_journal_line', 'vbh_jl_journal_account', ['journal_id', 'account_id']]], $added);
	}
}
