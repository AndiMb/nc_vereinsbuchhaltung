<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Invoice\SenderLine;
use PHPUnit\Framework\TestCase;

/**
 * Absenderzeile über dem Anschriftenfeld (#238): sie bleibt einzeilig, damit
 * sie im Fenster des Umschlags steht. Ein langer Name verkleinert erst die
 * Schrift, dann entfällt die Straße.
 */
final class SenderLineTest extends TestCase {
	private const ISSUER = ['name' => 'TSV Beispiel e. V.', 'street' => 'Hauptstr. 1', 'postalCode' => '12345', 'city' => 'Beispielstadt'];

	/** Jedes Zeichen ist bei 1 pt einen halben Millimeter breit. */
	private static function measure(): \Closure {
		return static fn (string $text, float $size): float => mb_strlen($text) * $size * 0.5 / 6.5;
	}

	public function testKurzerNamePasstInDerNormalenGroesse(): void {
		[$text, $size] = SenderLine::fit(self::ISSUER, self::measure(), 85);

		$this->assertSame('TSV Beispiel e. V. · Hauptstr. 1 · 12345 Beispielstadt', $text);
		$this->assertSame(SenderLine::SIZE, $size);
	}

	public function testEtwasLaengererNameVerkleinertDieSchrift(): void {
		$issuer = ['name' => str_repeat('n', 140)] + self::ISSUER;

		[$text, $size] = SenderLine::fit($issuer, self::measure(), 85);

		$this->assertStringContainsString('Hauptstr. 1', $text);
		$this->assertLessThan(SenderLine::SIZE, $size);
		$this->assertGreaterThanOrEqual(SenderLine::MIN_SIZE, $size);
		$this->assertLessThanOrEqual(85, (self::measure())($text, $size));
	}

	public function testSehrLangerNameLaesstDieStrasseWeg(): void {
		$issuer = ['name' => str_repeat('n', 110)] + self::ISSUER;

		[$text, $size] = SenderLine::fit($issuer, static fn (string $t, float $s): float => mb_strlen($t) * $s * 0.125, 85);

		$this->assertSame(str_repeat('n', 110) . ' · 12345 Beispielstadt', $text);
		$this->assertGreaterThanOrEqual(SenderLine::MIN_SIZE, $size);
	}

	public function testWasGarNichtPasstBleibtBeiDerKleinstenSchrift(): void {
		$issuer = ['name' => str_repeat('n', 400)] + self::ISSUER;

		[, $size] = SenderLine::fit($issuer, self::measure(), 85);

		$this->assertSame(SenderLine::MIN_SIZE, $size);
	}

	public function testLeereAngabenFallenWeg(): void {
		[$text] = SenderLine::fit(['name' => 'Verein', 'street' => '', 'postalCode' => '', 'city' => 'Ort'], self::measure(), 85);

		$this->assertSame('Verein · Ort', $text);
	}
}
