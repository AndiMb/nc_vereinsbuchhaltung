<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Report\ReportAsOf;
use PHPUnit\Framework\TestCase;

/**
 * Stand und Zeitanteil des Gliederungsberichts.
 */
class ReportAsOfTest extends TestCase {

	/**
	 * @return array<string, array{string, string, string, ?string, array<string, mixed>}>
	 */
	public static function resolveProvider(): array {
		return [
			'Kalenderjahr, mitten im Jahr' => [
				'2026-01-01', '2026-12-31', '2026-11-10', null,
				['value' => '2026-10', 'until' => '2026-10-31', 'elapsed' => 10, 'total' => 12, 'default' => '2026-10'],
			],
			'current mitten im Jahr' => [
				'2026-01-01', '2026-12-31', '2026-11-10', 'current',
				['value' => 'current', 'until' => '2026-12-31', 'elapsed' => 11, 'total' => 12],
			],
			'current im letzten Monat' => [
				'2026-01-01', '2026-12-31', '2026-12-05', 'current',
				['until' => '2026-12-31', 'elapsed' => 12],
			],
			'Monat gewaehlt' => [
				'2026-01-01', '2026-12-31', '2026-11-10', '2026-02',
				['value' => '2026-02', 'until' => '2026-02-28', 'elapsed' => 2],
			],
			'erster Monat laeuft noch' => [
				'2026-01-01', '2026-12-31', '2026-01-15', null,
				['value' => 'current', 'elapsed' => 1, 'options' => ['2026-01'], 'default' => 'current'],
			],
			'Periode vorbei' => [
				'2025-01-01', '2025-12-31', '2026-11-10', null,
				['value' => '2025-12', 'until' => '2025-12-31', 'elapsed' => 12, 'default' => '2025-12'],
			],
			'Periode vorbei, current' => [
				'2025-01-01', '2025-12-31', '2026-11-10', 'current',
				['value' => 'current', 'until' => '2025-12-31', 'elapsed' => 12],
			],
			'Periode in der Zukunft' => [
				'2027-01-01', '2027-12-31', '2026-11-10', null,
				['value' => 'current', 'elapsed' => 0, 'options' => [], 'total' => 12],
			],
			'abweichendes Geschaeftsjahr' => [
				'2026-07-01', '2027-06-30', '2026-11-10', null,
				['value' => '2026-10', 'until' => '2026-10-31', 'elapsed' => 4, 'total' => 12],
			],
			'Rumpfjahr' => [
				'2026-09-01', '2026-12-31', '2026-11-10', null,
				['value' => '2026-10', 'elapsed' => 2, 'total' => 4, 'options' => ['2026-09', '2026-10', '2026-11']],
			],
			'Periode endet mitten im Monat' => [
				'2026-01-01', '2026-06-15', '2026-11-10', '2026-06',
				['value' => '2026-06', 'until' => '2026-06-15', 'elapsed' => 6, 'total' => 6],
			],
		];
	}

	/**
	 * @dataProvider resolveProvider
	 * @param array<string, mixed> $expected
	 */
	public function testResolve(string $start, string $end, string $today, ?string $asOf, array $expected): void {
		$result = ReportAsOf::resolve($start, $end, $today, $asOf);
		foreach ($expected as $key => $value) {
			$this->assertSame($value, $result[$key], $key);
		}
	}

	public function testOptionsReachUpToCurrentMonth(): void {
		$options = ReportAsOf::resolve('2026-01-01', '2026-12-31', '2026-11-10', null)['options'];
		$this->assertCount(11, $options);
		$this->assertSame('2026-01', $options[0]);
		$this->assertSame('2026-11', $options[10]);
	}

	public function testPastPeriodHasAllMonthsAsOptions(): void {
		$result = ReportAsOf::resolve('2025-01-01', '2025-12-31', '2026-11-10', null);
		$this->assertCount(12, $result['options']);
		$this->assertSame('current', ReportAsOf::CURRENT);
	}

	/**
	 * @return array<string, array{?string}>
	 */
	public static function invalidProvider(): array {
		return [
			'Monat 13' => ['2026-13'],
			'Text' => ['abc'],
			'leer' => [''],
			'vor der Periode' => ['2025-12'],
			'noch nicht erreicht' => ['2026-12'],
		];
	}

	/**
	 * @dataProvider invalidProvider
	 */
	public function testInvalidAsOfThrows(string $asOf): void {
		$this->expectException(\InvalidArgumentException::class);
		ReportAsOf::resolve('2026-01-01', '2026-12-31', '2026-11-10', $asOf);
	}
}
