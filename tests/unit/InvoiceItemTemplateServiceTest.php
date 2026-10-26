<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceItemTemplate;
use OCA\Vereinsbuchhaltung\Db\InvoiceItemTemplateMapper;
use OCA\Vereinsbuchhaltung\Service\InvoiceItemTemplateService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** Positionsvorlagen: Pflichtangaben, Einheit, Preis, Steuer und Erlöskonto. */
class InvoiceItemTemplateServiceTest extends TestCase {

	private AccountMapper&MockObject $accounts;

	private function service(): InvoiceItemTemplateService {
		$mapper = $this->createMock(InvoiceItemTemplateMapper::class);
		$mapper->method('insert')->willReturnArgument(0);
		$mapper->method('update')->willReturnArgument(0);
		$this->accounts = $this->createMock(AccountMapper::class);
		return new InvoiceItemTemplateService($mapper, $this->accounts, $this->createMock(IUserSession::class));
	}

	private function account(string $type = 'income', bool $bank = false, bool $active = true): Account {
		$account = new Account();
		$account->setType($type);
		$account->setIsBank($bank);
		$account->setActive($active);
		return $account;
	}

	public function testVorlageMitAllenAngabenWirdAngelegt(): void {
		$s = $this->service();
		$this->accounts->method('find')->willReturn($this->account());

		$template = $s->create(['name' => ' Hallenmiete ', 'description' => 'pro Stunde', 'unitCode' => 'HUR', 'unitPriceCents' => 4500, 'taxCategory' => 's', 'taxRateBp' => 1900, 'accountId' => 4]);

		$this->assertSame('Hallenmiete', $template->getName());
		$this->assertSame('HUR', $template->getUnitCode());
		$this->assertSame(4500, $template->getUnitPriceCents());
		$this->assertSame('S', $template->getTaxCategory());
		$this->assertSame(1900, $template->getTaxRateBp());
		$this->assertSame(4, $template->getAccountId());
	}

	public function testOhneBezeichnungKeineVorlage(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->create(['name' => '  ']);
	}

	public function testBefreiteVorlageHatSatzNull(): void {
		$template = $this->service()->create(['name' => 'Spende', 'taxCategory' => 'E', 'taxRateBp' => 1900]);

		$this->assertSame('E', $template->getTaxCategory());
		$this->assertSame(0, $template->getTaxRateBp());
	}

	/** @dataProvider ungueltig */
	public function testUngueltigeAngabenWerdenAbgelehnt(array $data): void {
		$s = $this->service();
		$this->accounts->method('find')->willReturnCallback(static function (int $id): Account {
			if ($id === 99) {
				throw new DoesNotExistException('x');
			}
			$account = new Account();
			$account->setType($id === 5 ? 'expense' : 'income');
			$account->setIsBank(false);
			$account->setActive(true);
			return $account;
		});

		$this->expectException(\InvalidArgumentException::class);
		$s->create($data + ['name' => 'x']);
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function ungueltig(): array {
		return [
			'Einheit' => [['unitCode' => 'XYZ']],
			'negativer Preis' => [['unitPriceCents' => -1]],
			'Steuerkategorie' => [['taxCategory' => 'Q']],
			'Satz null bei Regelsatz' => [['taxCategory' => 'S', 'taxRateBp' => 0]],
			'Konto fehlt' => [['accountId' => 99]],
			'Aufwandskonto' => [['accountId' => 5]],
		];
	}

	public function testAenderungWirdTeilweiseUebernommen(): void {
		$mapper = $this->createMock(InvoiceItemTemplateMapper::class);
		$existing = new InvoiceItemTemplate();
		$existing->setId(3);
		$existing->setName('Alt');
		$existing->setUnitPriceCents(1000);
		$mapper->method('find')->willReturn($existing);
		$mapper->method('update')->willReturnArgument(0);
		$s = new InvoiceItemTemplateService($mapper, $this->createMock(AccountMapper::class), $this->createMock(IUserSession::class));

		$updated = $s->update(3, ['unitPriceCents' => 2500]);

		$this->assertSame('Alt', $updated->getName());
		$this->assertSame(2500, $updated->getUnitPriceCents());
	}
}
