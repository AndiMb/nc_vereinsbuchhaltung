<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Tests\Dev\IbanFactory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../dev/lib/autoload.php';

/**
 * Die Test-IBANs des Seeders (tests/dev) müssen eine echte Prüfsumme tragen –
 * die App prüft sie nicht, die Prüftools der Hausbank aber schon.
 */
class DevIbanFactoryTest extends TestCase {

	/** @return array<string, array{string, string, string}> */
	public static function bekannteIbans(): array {
		return [
			'Beispiel-IBAN der Dokumentation' => ['12030000', '202051', 'DE02120300000000202051'],
			'häufig zitierte Commerzbank-IBAN' => ['37040044', '0532013000', 'DE89370400440532013000'],
			'Vereinskonto der Entwicklungsinstanz' => ['50010517', '648489890', 'DE12500105170648489890'],
		];
	}

	/** @dataProvider bekannteIbans */
	public function testErzeugtDieBekanntenIbans(string $bankCode, string $account, string $expected): void {
		$this->assertSame($expected, IbanFactory::german($bankCode, $account));
	}

	public function testKontonummerWirdLinksMitNullenAufgefuellt(): void {
		$this->assertSame('DE02120300000000202051', IbanFactory::german('12030000', '202051'));
		$this->assertSame(22, strlen(IbanFactory::german('12030000', '1')));
	}

	public function testPruefsummeStimmtFuerErzeugteIbans(): void {
		foreach (['10010010' => '987654321', '70070010' => '123456700', '76026000' => '34567890'] as $bank => $account) {
			$this->assertTrue(IbanFactory::isValid(IbanFactory::german((string)$bank, $account)), (string)$bank);
		}
	}

	public function testErkenntFalschePruefsumme(): void {
		$this->assertTrue(IbanFactory::isValid('DE02 1203 0000 0000 2020 51'));
		$this->assertFalse(IbanFactory::isValid('DE03120300000000202051'));
		$this->assertFalse(IbanFactory::isValid('DE02120300000000202052'));
		$this->assertFalse(IbanFactory::isValid('DE12 345'));
		$this->assertFalse(IbanFactory::isValid(''));
	}

	public function testPruefziffernIgnorierenLeerzeichenUndKleinschreibung(): void {
		$this->assertTrue(IbanFactory::isValid('de89 3704 0044 0532 0130 00'));
	}

	public function testAnzeigeformatInVierergruppen(): void {
		$this->assertSame('DE02 1203 0000 0000 2020 51', IbanFactory::format('DE02120300000000202051'));
	}

	public function testLehntFalscheBankleitzahlOderKontonummerAb(): void {
		$this->expectException(\InvalidArgumentException::class);
		IbanFactory::german('1203', '202051');
	}

	public function testLehntZuLangeKontonummerAb(): void {
		$this->expectException(\InvalidArgumentException::class);
		IbanFactory::german('12030000', '12345678901');
	}
}
