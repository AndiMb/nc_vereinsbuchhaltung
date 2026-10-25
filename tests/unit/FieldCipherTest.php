<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\FieldCipher;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class FieldCipherTest extends TestCase {

	private function cipher(string $secret = 'instanz-secret'): FieldCipher {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('secret')->willReturn($secret);
		return new FieldCipher($config);
	}

	public function testVerschluesselteIbanLaesstSichEntschluesseln(): void {
		$cipher = $this->cipher();
		$encrypted = $cipher->encrypt('DE89370400440532013000');

		$this->assertTrue(FieldCipher::isEncrypted($encrypted));
		$this->assertStringNotContainsString('DE89370400440532013000', $encrypted);
		$this->assertSame('DE89370400440532013000', $cipher->decrypt($encrypted));
	}

	/** Gleiche IBAN, anderes Chiffrat: Ein Dump verrät nicht, wer dasselbe Konto hat. */
	public function testJedesVerschluesselnLiefertEinAnderesChiffrat(): void {
		$cipher = $this->cipher();

		$this->assertNotSame($cipher->encrypt('DE89370400440532013000'), $cipher->encrypt('DE89370400440532013000'));
	}

	public function testKlartextWirdUnveraendertDurchgereicht(): void {
		$this->assertSame('DE89370400440532013000', $this->cipher()->decrypt('DE89370400440532013000'));
		$this->assertFalse(FieldCipher::isEncrypted('DE89370400440532013000'));
	}

	/** Die Spalten wachsen auf 255 (IBAN, Gegenkonto bis 40 Zeichen) bzw. 512 (Regel-Suchwert bis 255). */
	public function testChiffratPasstInDieSpalten(): void {
		$cipher = $this->cipher();

		$this->assertLessThanOrEqual(255, strlen($cipher->encrypt(str_repeat('X', 40))));
		$this->assertLessThanOrEqual(512, strlen($cipher->encrypt(str_repeat('X', 255))));
	}

	public function testAnderesSecretKannNichtEntschluesseln(): void {
		$encrypted = $this->cipher('altes-secret')->encrypt('DE89370400440532013000');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('config.php');
		$this->cipher('neues-secret')->decrypt($encrypted);
	}

	public function testVeraendertesChiffratFaelltAuf(): void {
		$cipher = $this->cipher();
		$raw = base64_decode(substr($cipher->encrypt('DE89370400440532013000'), 5));
		$raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);

		$this->expectException(\RuntimeException::class);
		$cipher->decrypt('vbh1:' . base64_encode($raw));
	}

	public function testKaputtesChiffratFaelltAuf(): void {
		$this->expectException(\RuntimeException::class);
		$this->cipher()->decrypt('vbh1:kein-base64!');
	}

	public function testOhneSecretWirdNichtVerschluesselt(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('secret');
		$this->cipher('')->encrypt('DE89370400440532013000');
	}
}
