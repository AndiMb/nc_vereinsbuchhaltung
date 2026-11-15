<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wer die Zeilen eines Buchungssatzes löscht, räumt auch deren Verteilung auf
 * Kostenstellen ab (Issue #7) – und zwar im Mapper selbst, nicht beim
 * Aufrufer. Mehrere Dienste löschen Zeilen direkt (Eröffnungsbuchung neu
 * schreiben, xbuc-Import); vergäße einer davon die Verteilung, blieben
 * Einträge zurück, die an keiner Buchung mehr hängen und das Löschen ihrer
 * Kostenstelle für immer verhindern.
 *
 * Aufbau wie in ResetServiceTest: eine Datenbank, die nichts speichert,
 * sondern jedes ausgeführte DELETE mit seiner Tabelle mitschreibt.
 */
class JournalLineAllocationCleanupTest extends TestCase {

	/** @var list<string> */
	private array $log = [];
	private ?string $table = null;

	/** @param list<int> $lineIds die Zeilen, die der Buchungssatz in der Datenbank hat */
	private function connection(array $lineIds): IDBConnection&MockObject {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('createNamedParameter')->willReturn('param');
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('delete')->willReturnCallback(function (string $table) use ($qb) {
			$this->table = $table;
			return $qb;
		});
		$qb->method('executeStatement')->willReturnCallback(function (): int {
			$this->log[] = 'DELETE ' . $this->table;
			return 0;
		});
		$qb->method('executeQuery')->willReturnCallback(function () use ($lineIds): IResult {
			$rows = array_map(static fn (int $id): array => ['id' => $id], $lineIds);
			$result = $this->createMock(IResult::class);
			$result->method('fetch')->willReturnCallback(static function () use (&$rows) {
				return array_shift($rows) ?? false;
			});
			return $result;
		});

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}

	public function testVerteilungGehtVorDenZeilen(): void {
		(new JournalLineMapper($this->connection([77, 78])))->deleteByJournal(40);

		$this->assertSame(['DELETE vbh_line_alloc', 'DELETE vbh_journal_line'], $this->log);
	}

	public function testOhneZeilenWirdNurDieZeilentabelleAngefasst(): void {
		(new JournalLineMapper($this->connection([])))->deleteByJournal(40);

		$this->assertSame(['DELETE vbh_journal_line'], $this->log);
	}
}
