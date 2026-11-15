<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Sepa\Bic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Die BIC in der Form, die pain.008 verlangt (`BICIdentifier`). */
class BicTest extends TestCase {

	public function testGueltigeBicWirdGrossUndOhneLeerzeichenGeliefert(): void {
		$this->assertSame('COBADEFFXXX', Bic::normalize(' coba de ff xxx '));
		$this->assertSame('BYLADEM1', Bic::normalize('BYLADEM1'));
	}

	/** @return array<string, array{?string}> */
	public static function ungueltig(): array {
		return [
			'nichts' => [null],
			'leer' => ['  '],
			'Wort' => ['not-a-bic'],
			'zu kurz' => ['COBADEF'],
			'neun Zeichen' => ['COBADEFFX'],
			'Ziffer im Bankcode' => ['C0BADEFFXXX'],
			'Markup' => ['<b>COBA'],
		];
	}

	#[DataProvider('ungueltig')]
	public function testAllesAndereIstKeineBic(?string $bic): void {
		$this->assertNull(Bic::normalize($bic));
	}
}
