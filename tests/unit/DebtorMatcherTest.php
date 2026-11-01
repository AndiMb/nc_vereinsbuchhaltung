<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Debtor;
use OCA\Vereinsbuchhaltung\Service\InvoiceBatch\DebtorMatcher;
use PHPUnit\Framework\TestCase;

class DebtorMatcherTest extends TestCase {

	private function debtor(int $id, string $name, ?string $plz = null, ?string $email = null, ?string $customerNo = null, ?string $street = null): Debtor {
		$d = new Debtor();
		$d->setId($id);
		$d->setName($name);
		$d->setPostalCode($plz);
		$d->setEmail($email);
		$d->setCustomerNo($customerNo);
		$d->setStreet($street);
		$d->setCity('Musterstadt');
		return $d;
	}

	/** @return array<string, string|null> */
	private function recipient(array $values): array {
		return $values + ['kind' => 'person', 'name' => '', 'name2' => null, 'street' => null, 'postalCode' => null, 'city' => null, 'country' => 'DE', 'email' => null, 'customerNo' => null];
	}

	public function testKundennummerGehtVor(): void {
		$matcher = new DebtorMatcher([$this->debtor(1, 'Anna', '12345'), $this->debtor(2, 'Anna B.', null, null, 'k-9')]);

		$match = $matcher->match($this->recipient(['name' => 'Anna', 'postalCode' => '12345', 'customerNo' => 'K-9']));

		$this->assertSame(2, $match['debtor']?->getId());
	}

	public function testNameUndEmailOderNameUndPlz(): void {
		$matcher = new DebtorMatcher([$this->debtor(1, 'Anna Beispiel', '12345', 'anna@example.org'), $this->debtor(2, 'Bernd', '99999')]);

		$this->assertSame(1, $matcher->match($this->recipient(['name' => ' anna  beispiel', 'email' => 'ANNA@example.org']))['debtor']?->getId());
		$this->assertSame(2, $matcher->match($this->recipient(['name' => 'Bernd', 'postalCode' => '99999']))['debtor']?->getId());
		$this->assertNull($matcher->match($this->recipient(['name' => 'Bernd', 'postalCode' => '11111']))['debtor']);
		$this->assertNull($matcher->match($this->recipient(['name' => 'Anna Beispiel']))['debtor']);
	}

	public function testAbweichungenWerdenGenannt(): void {
		$matcher = new DebtorMatcher([$this->debtor(1, 'Anna', '12345', 'alt@example.org', null, 'Altweg 1')]);

		$match = $matcher->match($this->recipient(['name' => 'Anna', 'postalCode' => '12345', 'street' => 'Neuweg 2', 'email' => 'neu@example.org', 'city' => 'Musterstadt']));

		$this->assertSame(['street', 'email'], $match['differences']);
		$this->assertSame([], $match['fill']);
	}

	public function testLeereFelderDesDebitorsWerdenErgaenzt(): void {
		$matcher = new DebtorMatcher([$this->debtor(1, 'Anna', '12345')]);

		$match = $matcher->match($this->recipient(['name' => 'Anna', 'postalCode' => '12345', 'email' => 'anna@example.org', 'street' => 'Weg 1']));

		$this->assertSame([], $match['differences']);
		$this->assertSame(['street' => 'Weg 1', 'email' => 'anna@example.org'], $match['fill']);
	}
}
