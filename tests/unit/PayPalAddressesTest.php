<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Service\Statement\PayPalAddresses;
use PHPUnit\Framework\TestCase;

/**
 * Die PayPal-Adressen eines Geldkontos (Issue #375): Normalform und Kennung.
 */
class PayPalAddressesTest extends TestCase {

	public function testNormalisiertMehrereAdressen(): void {
		$this->assertSame(
			'kasse@example.org,spender@example.com',
			PayPalAddresses::normalize(' Kasse@Example.org , spender@example.com,kasse@example.org '),
		);
	}

	public function testLeerIstNull(): void {
		$this->assertNull(PayPalAddresses::normalize(''));
		$this->assertNull(PayPalAddresses::normalize(' , '));
		$this->assertNull(PayPalAddresses::normalize(null));
	}

	public function testUngueltigeAdresseWirft(): void {
		$this->expectException(UserFacingException::class);
		PayPalAddresses::normalize('kasse.example.org');
	}

	public function testDoppeltesAtWirft(): void {
		$this->expectException(UserFacingException::class);
		PayPalAddresses::normalize('a@@b.de');
	}

	public function testKeys(): void {
		$this->assertSame(
			['PAYPALKASSEEXAMPLEORG', 'PAYPALSPENDEREXAMPLECOM'],
			PayPalAddresses::keys('kasse@example.org,spender@example.com'),
		);
		$this->assertSame([], PayPalAddresses::keys(null));
	}

	public function testKennungIstAuf40ZeichenBegrenzt(): void {
		$key = PayPalAddresses::key(str_repeat('a', 60) . '@example.org');
		$this->assertSame(40, strlen((string)$key));
		$this->assertStringStartsWith(PayPalAddresses::PREFIX, (string)$key);
	}
}
