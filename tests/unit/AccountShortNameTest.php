<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Controller\AccountController;
use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\RuleMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\AccountService;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\CostCenterService;
use OCA\Vereinsbuchhaltung\Service\IbanValidator;
use OCA\Vereinsbuchhaltung\Service\OpeningBalanceService;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportSettingsService;
use OCA\Vereinsbuchhaltung\Service\SepaDebtorAccountService;
use OCA\Vereinsbuchhaltung\Service\Statement\RowNormalizer;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Kurzname und Beschreibung eines Kontos (#201).
 *
 * Der Kurzname ersetzt in den schmalen Auswahllisten den langen Namen; die
 * Beschreibung erklärt neuen Kassenwarten direkt am Konto, wofür es da ist.
 * Sie ist Markdown und wird serverseitig zu HTML – escaped, ohne Skripte und
 * ohne andere Links als http(s).
 */
class AccountShortNameTest extends TestCase {
	private const BOOK = Application::BOOK;

	private function account(): Account {
		$account = new Account();
		$account->setId(7);
		$account->setUserId(self::BOOK);
		$account->setNumber('4300');
		$account->setName('Einnahmen aus Veranstaltungen der Jugendabteilung');
		$account->setType('income');
		$account->setIsBank(false);
		$account->setActive(true);
		return $account;
	}

	private function service(Account $account): AccountService {
		$mapper = $this->createMock(AccountMapper::class);
		$mapper->method('find')->willReturn($account);
		$mapper->method('update')->willReturnArgument(0);
		$mapper->method('insert')->willReturnArgument(0);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $params = []) => vsprintf($text, (array)$params));

		return new AccountService(
			$mapper,
			$this->createMock(JournalLineMapper::class),
			$this->createMock(RuleMapper::class),
			$this->createMock(BudgetMapper::class),
			$this->createMock(TransactionRunner::class),
			$this->createMock(RowNormalizer::class),
			$this->createMock(CostCenterService::class),
			$this->createMock(PeriodService::class),
			$this->createMock(AuditService::class),
			$this->createMock(IbanValidator::class),
			$this->createMock(SepaDebtorAccountService::class),
			$this->createMock(SepaImportSettingsService::class),
			$l10n,
		);
	}

	public function testKurznameUndBeschreibungWerdenGetrimmtGespeichert(): void {
		$saved = $this->service($this->account())->update(7, self::BOOK, [
			'shortName' => '  Jugend-Events  ',
			'description' => "  Hier landen **alle** Einnahmen der Jugendfahrten.\n  ",
		]);

		$this->assertSame('Jugend-Events', $saved->getShortName());
		$this->assertSame('Hier landen **alle** Einnahmen der Jugendfahrten.', $saved->getDescription());
	}

	public function testLeereAngabenLoeschenKurznameUndBeschreibung(): void {
		$account = $this->account();
		$account->setShortName('Jugend');
		$account->setDescription('Alt');

		$saved = $this->service($account)->update(7, self::BOOK, ['shortName' => ' ', 'description' => '']);

		$this->assertNull($saved->getShortName());
		$this->assertNull($saved->getDescription());
	}

	public function testNichtGesendeteFelderBleibenStehen(): void {
		$account = $this->account();
		$account->setShortName('Jugend');

		$saved = $this->service($account)->update(7, self::BOOK, ['name' => 'Anders']);

		$this->assertSame('Jugend', $saved->getShortName());
	}

	public function testZuLangerKurznameWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service($this->account())->update(7, self::BOOK, ['shortName' => str_repeat('x', 65)]);
	}

	public function testZuLangeBeschreibungWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service($this->account())->update(7, self::BOOK, ['description' => str_repeat('x', 10001)]);
	}

	public function testBeimAnlegenGehenKurznameUndBeschreibungMit(): void {
		$created = $this->service($this->account())->create(self::BOOK, '4310', 'Lange Bezeichnung', 'income', null, false, audit: false, shortName: ' Kurz ', description: ' Text ');

		$this->assertSame('Kurz', $created->getShortName());
		$this->assertSame('Text', $created->getDescription());
	}

	public function testBeschreibungKommtAlsSicheresHtml(): void {
		$account = $this->account();
		$account->setShortName('Jugend');
		$account->setDescription("**Wichtig:** <script>alert(1)</script>\n\n- [Satzung](https://verein.example/satzung)\n- [böse](javascript:alert(1))");

		$json = $account->jsonSerialize();

		$this->assertSame('Jugend', $json['shortName']);
		$html = $json['descriptionHtml'];
		$this->assertStringContainsString('<strong>Wichtig:</strong>', $html);
		$this->assertStringNotContainsString('<script>', $html);
		$this->assertStringContainsString('&lt;script&gt;', $html);
		$this->assertStringContainsString('<a href="https://verein.example/satzung" target="_blank" rel="noopener noreferrer">Satzung</a>', $html);
		$this->assertStringNotContainsString('javascript:', $html);
	}

	public function testOhneBeschreibungKeinHtml(): void {
		$this->assertNull($this->account()->jsonSerialize()['descriptionHtml']);
	}

	public function testControllerReichtLeerenKurznamenWeiter(): void {
		$service = $this->createMock(AccountService::class);
		$service->expects($this->once())
			->method('update')
			->with(7, self::BOOK, $this->callback(static fn (array $d): bool => $d['shortName'] === '' && $d['description'] === 'Text'))
			->willReturn(new Account());

		(new AccountController(
			$this->createMock(IRequest::class),
			$service,
			$this->createMock(OpeningBalanceService::class),
			$this->createMock(AuditService::class),
			$this->createMock(IL10N::class),
		))->update(7, shortName: '', description: 'Text');
	}
}
