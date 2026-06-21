<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\CamtCsvParser;
use PHPUnit\Framework\TestCase;

class CamtCsvParserTest extends TestCase {
	private CamtCsvParser $parser;

	protected function setUp(): void {
		$this->parser = new CamtCsvParser();
	}

	public function testParsesSampleFile(): void {
		$content = file_get_contents(__DIR__ . '/../fixtures/beispiel-camt.csv');
		$rows = $this->parser->parse($content);

		$this->assertCount(5, $rows);
		$this->assertSame('2026-01-02', $rows[0]['bookingDate']);
		$this->assertSame(6000, $rows[0]['amountCents']);
		$this->assertSame('Max Mustermann', $rows[0]['counterparty']);
		$this->assertSame(-25000, $rows[1]['amountCents']);
		$this->assertSame(125050, $rows[2]['amountCents']); // 1.250,50
	}

	public function testHashIsStableAndUnique(): void {
		$content = file_get_contents(__DIR__ . '/../fixtures/beispiel-camt.csv');
		$first = array_column($this->parser->parse($content), 'hash');
		$second = array_column($this->parser->parse($content), 'hash');

		$this->assertSame($first, $second, 'Hash muss bei erneutem Parsen identisch sein');
		$this->assertCount(count($first), array_unique($first), 'Beispielzeilen müssen eindeutige Hashes haben');
	}

	public function testRejectsFileWithoutRows(): void {
		$this->expectException(\RuntimeException::class);
		$this->parser->parse("nur eine zeile");
	}
}
