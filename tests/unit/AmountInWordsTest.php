<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\DonationReceipt\AmountInWords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Betrag in Buchstaben für die Zuwendungsbestätigung (Issue #10). */
class AmountInWordsTest extends TestCase {

	/** @return array<string, array{0: int, 1: string}> */
	public static function numbers(): array {
		return [
			'null' => [0, 'null'],
			'eins' => [1, 'eins'],
			'sechzehn' => [16, 'sechzehn'],
			'siebzehn' => [17, 'siebzehn'],
			'zwanzig' => [20, 'zwanzig'],
			'einundzwanzig' => [21, 'einundzwanzig'],
			'neunundneunzig' => [99, 'neunundneunzig'],
			'einhundert' => [100, 'einhundert'],
			'einhunderteins' => [101, 'einhunderteins'],
			'BMF-Beispiel' => [1322, 'eintausenddreihundertzweiundzwanzig'],
			'eintausend' => [1000, 'eintausend'],
			'einundzwanzigtausend' => [21000, 'einundzwanzigtausend'],
			'hunderteintausend' => [101001, 'einhunderteintausendeins'],
			'eine Million' => [1_000_000, 'eine Million'],
			'zwei Millionen' => [2_000_300, 'zwei Millionen dreihundert'],
		];
	}

	#[DataProvider('numbers')]
	public function testNumber(int $n, string $expected): void {
		$this->assertSame($expected, AmountInWords::number($n));
	}

	public function testEuros(): void {
		$this->assertSame('null Euro', AmountInWords::euros(0));
		$this->assertSame('ein Euro', AmountInWords::euros(100));
		$this->assertSame('ein Euro und ein Cent', AmountInWords::euros(101));
		$this->assertSame('einhundertzwanzig Euro', AmountInWords::euros(12000));
		$this->assertSame('eintausenddreihundertzweiundzwanzig Euro und fünfzig Cent', AmountInWords::euros(132250));
	}

	public function testNegativeAmountIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		AmountInWords::euros(-1);
	}
}
