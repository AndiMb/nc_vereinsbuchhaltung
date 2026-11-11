<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use PHPUnit\Framework\TestCase;

/**
 * Monatssummen je Konto aus den Tagessummen der Datenbank
 * ({@see JournalLineMapper::sumByAccountAndMonth()}): der Monat entsteht in
 * PHP aus dem Buchungsdatum, nicht per SQL-Teilstring (PostgreSQL kennt kein
 * substr() auf einer DATE-Spalte).
 */
class JournalLineMapperMonthTest extends TestCase {

	public function testTagessummenWerdenJeMonatUndKontoAddiert(): void {
		$rows = [
			['account_id' => '7', 'date' => '2026-03-05', 'debit' => '1000', 'credit' => '0'],
			// Manche Treiber liefern DATE mit Uhrzeit.
			['account_id' => '7', 'date' => '2026-03-28 00:00:00', 'debit' => '250', 'credit' => '50'],
			['account_id' => '8', 'date' => '2026-03-28', 'debit' => null, 'credit' => '300'],
			['account_id' => 7, 'date' => '2026-04-01', 'debit' => 10, 'credit' => 0],
		];

		$this->assertSame([
			'2026-03' => [
				7 => ['debit' => 1250, 'credit' => 50],
				8 => ['debit' => 0, 'credit' => 300],
			],
			'2026-04' => [
				7 => ['debit' => 10, 'credit' => 0],
			],
		], JournalLineMapper::foldByMonth($rows));
	}

	public function testOhneZeilenLeer(): void {
		$this->assertSame([], JournalLineMapper::foldByMonth([]));
	}
}
