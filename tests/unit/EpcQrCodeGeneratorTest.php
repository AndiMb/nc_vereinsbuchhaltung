<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\EpcQrCodeGenerator;
use PHPUnit\Framework\TestCase;

/**
 * GiroCode-Payload (EPC069-12, Spec §3.11 T32, Issue #73) – Schwerpunkt: das
 * zeilenweise Format ist formatkritisch (ein Fehler fiele erst beim Scan in
 * der Banking-App auf), deshalb hier Zeile für Zeile geprüft statt nur grob
 * auf "irgendein PNG kam raus".
 */
class EpcQrCodeGeneratorTest extends TestCase {

	private function generator(): EpcQrCodeGenerator {
		return new EpcQrCodeGenerator();
	}

	private function lines(string $payload): array {
		return explode("\n", $payload);
	}

	public function testPayloadHatDieElfPflichtfelderInDerRichtigenReihenfolge(): void {
		$lines = $this->lines($this->generator()->buildPayload('Musterverein e.V.', 'DE02120300000000202051', 'BYLADEM1001', 4599, 'Beitrag 2026-Q1'));

		$this->assertSame('BCD', $lines[0]);
		$this->assertSame('002', $lines[1]);
		$this->assertSame('1', $lines[2]);
		$this->assertSame('SCT', $lines[3]);
		$this->assertSame('BYLADEM1001', $lines[4]);
		$this->assertSame('Musterverein e.V.', $lines[5]);
		$this->assertSame('DE02120300000000202051', $lines[6]);
		$this->assertSame('EUR45.99', $lines[7]);
		$this->assertSame('', $lines[8]); // Purpose
		$this->assertSame('', $lines[9]); // strukturierte Referenz
		$this->assertSame('Beitrag 2026-Q1', $lines[10]);
	}

	public function testBicDarfLeerBleiben(): void {
		$lines = $this->lines($this->generator()->buildPayload('Musterverein e.V.', 'DE02120300000000202051', null, 100, 'x'));
		$this->assertSame('', $lines[4]);
	}

	public function testIbanWirdNormalisiertGrossOhneLeerzeichen(): void {
		$lines = $this->lines($this->generator()->buildPayload('V', 'de02 1203 0000 0000 2020 51', null, 100, 'x'));
		$this->assertSame('DE02120300000000202051', $lines[6]);
	}

	public function testBetragWirdMitPunktAlsDezimaltrennerFormatiert(): void {
		$lines = $this->lines($this->generator()->buildPayload('V', 'DE02120300000000202051', null, 100000, 'x'));
		$this->assertSame('EUR1000.00', $lines[7]);
	}

	public function testNameUndVerwendungszweckWerdenGekuerzt(): void {
		$langerName = str_repeat('A', 100);
		$langerZweck = str_repeat('B', 200);
		$lines = $this->lines($this->generator()->buildPayload($langerName, 'DE02120300000000202051', null, 100, $langerZweck));
		$this->assertSame(70, mb_strlen($lines[5]));
		$this->assertSame(140, mb_strlen($lines[10]));
	}

	public function testNichtPositiverBetragWirft(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->generator()->buildPayload('V', 'DE02120300000000202051', null, 0, 'x');
	}

	public function testLeereIbanWirft(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->generator()->buildPayload('V', '  ', null, 100, 'x');
	}

	/** Ende-zu-Ende: die eigentliche PNG-Erzeugung ueber chillerlan/php-qrcode funktioniert und liefert echte Bilddaten (kein Data-URI). */
	public function testGeneratePngLiefertEchteBinaereBilddaten(): void {
		$png = $this->generator()->generatePng('Musterverein e.V.', 'DE02120300000000202051', 'BYLADEM1001', 4599, 'Beitrag 2026-Q1');

		$this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png, 'Erwartet die PNG-Magic-Bytes, kein base64-Data-URI');
		$this->assertGreaterThan(100, strlen($png));
	}
}
