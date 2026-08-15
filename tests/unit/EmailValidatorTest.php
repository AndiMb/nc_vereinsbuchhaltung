<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\EmailValidator;
use PHPUnit\Framework\TestCase;

class EmailValidatorTest extends TestCase {

	/** @return array<string, array{0:string}> */
	public static function gueltigeAdressen(): array {
		return [
			'einfache Adresse' => ['k.brunner@example.org'],
			'Umlaut im lokalen Teil' => ['m.müller@gmx.de'],
			'weiterer Umlaut, anderer Anbieter' => ['j.krüger@web.de'],
			'Punkt und Zahl im lokalen Teil' => ['anna.mueller1@t-online.de'],
			'Plus-Adressierung' => ['verein+kasse@example.org'],
		];
	}

	/** @dataProvider gueltigeAdressen */
	public function testGueltigeAdressenWerdenAkzeptiert(string $email): void {
		$this->assertTrue(EmailValidator::isValid($email), "$email hätte gültig sein müssen");
	}

	/** @return array<string, array{0:string|null}> */
	public static function ungueltigeAdressen(): array {
		return [
			'kein @' => ['keine-adresse'],
			'leer' => [''],
			'null' => [null],
			'kein Domain-Teil' => ['katrin@'],
			'kein lokaler Teil' => ['@example.org'],
			'Leerzeichen' => ['katrin brunner@example.org'],
			'doppelter Punkt im lokalen Teil' => ['katrin..brunner@example.org'],
			'Domain ohne Punkt' => ['katrin@localhost'],
		];
	}

	/** @dataProvider ungueltigeAdressen */
	public function testUngueltigeAdressenWerdenAbgelehnt(?string $email): void {
		$this->assertFalse(EmailValidator::isValid($email), "$email hätte ungültig sein müssen");
	}
}
