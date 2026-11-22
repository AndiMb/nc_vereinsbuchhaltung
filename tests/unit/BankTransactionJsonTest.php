<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use PHPUnit\Framework\TestCase;

/**
 * Die Listen der Bankumsätze gehen auch an Kassenprüfer. Die IBAN des
 * Zahlungspartners zeigt die Oberfläche nirgends, also verlässt sie den
 * Server nur maskiert; Regeln und SEPA-Abgleich lesen sie über den Getter.
 */
class BankTransactionJsonTest extends TestCase {

	public function testIbanDesZahlungspartnersIstInDerAntwortMaskiert(): void {
		$tx = new BankTransaction();
		$tx->setCounterpartyIban('DE89370400440532013000');

		$json = $tx->jsonSerialize();

		$this->assertStringNotContainsString('3704', (string)$json['counterpartyIban']);
		$this->assertStringEndsWith('3000', (string)$json['counterpartyIban']);
		$this->assertSame('DE89370400440532013000', $tx->getCounterpartyIban());
	}

	public function testOhneIbanBleibtDasFeldLeer(): void {
		$this->assertNull((new BankTransaction())->jsonSerialize()['counterpartyIban']);
	}
}
