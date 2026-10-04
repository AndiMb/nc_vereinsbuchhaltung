<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaSettingsAccountValidator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Konten-Einstellungen der Rücklastschrift-Verbuchung (Spec §3.10, Issue
 * #101): Gebührenkonto = Aufwandskonto, Standard-Erlöskonto = Ertragskonto,
 * nie ein Geldkonto, nie ein inaktives oder fehlendes Konto.
 */
class SepaSettingsAccountValidatorTest extends TestCase {

	private AccountMapper&MockObject $accounts;

	protected function setUp(): void {
		$this->accounts = $this->createMock(AccountMapper::class);
	}

	private function validator(): SepaSettingsAccountValidator {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf($text, $params));
		return new SepaSettingsAccountValidator($this->accounts, $l10n);
	}

	private function account(string $type, bool $isBank = false, bool $active = true, string $number = '4100', string $name = 'Testkonto'): Account {
		$account = new Account();
		$account->setNumber($number);
		$account->setName($name);
		$account->setType($type);
		$account->setIsBank($isBank);
		$account->setActive($active);
		return $account;
	}

	public function testAufwandskontoIstAlsGebuehrenkontoGueltig(): void {
		$this->accounts->method('find')->with(7, Application::BOOK)->willReturn($this->account('expense'));

		$this->assertNull($this->validator()->returnFeeAccountError(7));
	}

	public function testErtragskontoIstAlsStandardErloeskontoGueltig(): void {
		$this->accounts->method('find')->willReturn($this->account('income'));

		$this->assertNull($this->validator()->contributionRevenueAccountError(7));
	}

	public function testFehlendesKontoWirdBenannt(): void {
		$this->accounts->method('find')->willThrowException(new DoesNotExistException('weg'));

		$error = $this->validator()->returnFeeAccountError(99);

		$this->assertNotNull($error);
		$this->assertStringContainsString('Konto für Rücklastschriftgebühren', $error);
		$this->assertStringContainsString('nicht gefunden', $error);
	}

	public function testGeldkontoWirdAbgelehnt(): void {
		$this->accounts->method('find')->willReturn($this->account('expense', isBank: true, number: '1200', name: 'Girokonto'));

		$error = $this->validator()->returnFeeAccountError(7);

		$this->assertNotNull($error);
		$this->assertStringContainsString('kein Geldkonto', $error);
		$this->assertStringContainsString('1200 Girokonto', $error);
	}

	public function testInaktivesKontoWirdAbgelehnt(): void {
		$this->accounts->method('find')->willReturn($this->account('income', active: false));

		$error = $this->validator()->contributionRevenueAccountError(7);

		$this->assertNotNull($error);
		$this->assertStringContainsString('inaktiv', $error);
	}

	public function testErtragskontoAlsGebuehrenkontoWirdMitBeidenKontoartenBenannt(): void {
		$this->accounts->method('find')->willReturn($this->account('income', number: '4000', name: 'Mitgliedsbeiträge'));

		$error = $this->validator()->returnFeeAccountError(7);

		$this->assertNotNull($error);
		$this->assertStringContainsString('muss ein Aufwandskonto sein', $error);
		$this->assertStringContainsString('4000 Mitgliedsbeiträge ist ein Ertragskonto', $error);
	}

	public function testAufwandskontoAlsErloeskontoWirdAbgelehnt(): void {
		$this->accounts->method('find')->willReturn($this->account('expense'));

		$error = $this->validator()->contributionRevenueAccountError(7);

		$this->assertNotNull($error);
		$this->assertStringContainsString('muss ein Ertragskonto sein', $error);
	}
}
