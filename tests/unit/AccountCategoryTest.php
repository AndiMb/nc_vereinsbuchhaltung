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
use OCA\Vereinsbuchhaltung\Service\SepaDebtorAccountService;
use OCA\Vereinsbuchhaltung\Service\Statement\RowNormalizer;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Die Kategorie eines Kontos lässt sich wieder leeren (Issue #59).
 *
 * Gemeldet wurde: löscht man den Text im Feld „Kategorie", bleibt die alte
 * Kategorie stehen – mit einem Leerzeichen im Feld funktioniert es. Ursache
 * war das Zusammenspiel zweier für sich richtiger Entscheidungen: das Formular
 * schickte für ein leeres Feld null, und der Controller verwirft null-Werte,
 * weil null dort „Feld nicht mitgesendet = unverändert" bedeutet. Ein
 * Leerzeichen ist dagegen ein Wert und kam durch.
 *
 * Getestet werden deshalb beide Seiten des Wegs: der Controller muss den
 * Leerstring durchlassen, und der Dienst muss ihn als „keine Kategorie"
 * speichern – als NULL, damit ein geleertes Konto denselben Wert trägt wie ein
 * neu angelegtes ohne Kategorie.
 */
class AccountCategoryTest extends TestCase {

	/** Der gemeinsame Datenbestand aus BookContext::userId(). */
	private const BOOK = Application::BOOK;

	/**
	 * Der Weg aus dem Formular: leeres Feld heißt jetzt Leerstring, und der
	 * darf nicht im array_filter() des Controllers hängen bleiben.
	 */
	public function testControllerReichtLeereKategorieWeiter(): void {
		$service = $this->createMock(AccountService::class);
		$service->expects($this->once())
			->method('update')
			->with(
				7,
				self::BOOK,
				$this->callback(static fn (array $data): bool => array_key_exists('category', $data) && $data['category'] === ''),
			)
			->willReturn(new Account());

		$controller = new AccountController(
			$this->createMock(IRequest::class),
			$service,
			$this->createMock(OpeningBalanceService::class),
			$this->createMock(AuditService::class),
			$this->createMock(IL10N::class),
		);

		$controller->update(7, category: '');
	}

	/** Ein weggelassenes Feld bleibt unverändert – das ist der Grund für den array_filter(). */
	public function testControllerLaesstNichtGesendeteKategorieAus(): void {
		$service = $this->createMock(AccountService::class);
		$service->expects($this->once())
			->method('update')
			->with(
				7,
				self::BOOK,
				$this->callback(static fn (array $data): bool => !array_key_exists('category', $data)),
			)
			->willReturn(new Account());

		$controller = new AccountController(
			$this->createMock(IRequest::class),
			$service,
			$this->createMock(OpeningBalanceService::class),
			$this->createMock(AuditService::class),
			$this->createMock(IL10N::class),
		);

		$controller->update(7, name: 'Nur der Name');
	}

	public function testLeereKategorieWirdBeimAendernGeloescht(): void {
		$account = $this->existingAccount('Ausgaben');
		$saved = $this->service($account)->update(7, self::BOOK, ['category' => '']);
		$this->assertNull($saved->getCategory());
	}

	/** Nur Leerzeichen sind ebenfalls keine Kategorie – der gemeldete Umweg. */
	public function testLeerzeichenKategorieWirdBeimAendernGeloescht(): void {
		$account = $this->existingAccount('Ausgaben');
		$saved = $this->service($account)->update(7, self::BOOK, ['category' => '   ']);
		$this->assertNull($saved->getCategory());
	}

	public function testKategorieWirdBeimAendernGetrimmtUebernommen(): void {
		$account = $this->existingAccount(null);
		$saved = $this->service($account)->update(7, self::BOOK, ['category' => '  Einnahmen  ']);
		$this->assertSame('Einnahmen', $saved->getCategory());
	}

	/** Ohne 'category' im Datensatz bleibt die bestehende Kategorie stehen. */
	public function testFehlendeKategorieLaesstBestehendeStehen(): void {
		$account = $this->existingAccount('Ausgaben');
		$saved = $this->service($account)->update(7, self::BOOK, ['name' => 'Neuer Name']);
		$this->assertSame('Ausgaben', $saved->getCategory());
	}

	/**
	 * Dieselbe Normalisierung beim Anlegen: sonst trüge ein neues Konto ohne
	 * Kategorie '' und ein nachträglich geleertes NULL, obwohl beides dasselbe
	 * meint.
	 */
	public function testLeereKategorieWirdBeimAnlegenZuNull(): void {
		$service = $this->service($this->existingAccount(null));
		$created = $service->create(self::BOOK, '5999', 'Sonstiges', 'expense', '', false, audit: false);
		$this->assertNull($created->getCategory());
	}

	public function testKategorieWirdBeimAnlegenGetrimmtUebernommen(): void {
		$service = $this->service($this->existingAccount(null));
		$created = $service->create(self::BOOK, '5999', 'Sonstiges', 'expense', '  Ausgaben  ', false, audit: false);
		$this->assertSame('Ausgaben', $created->getCategory());
	}

	private function existingAccount(?string $category): Account {
		$account = new Account();
		$account->setId(7);
		$account->setUserId(self::BOOK);
		$account->setNumber('5900');
		$account->setName('Sonstige Ausgaben');
		$account->setType('expense');
		$account->setCategory($category);
		$account->setIsBank(false);
		$account->setActive(true);
		return $account;
	}

	/**
	 * Ein Dienst, dessen Mapper genau dieses Konto liefert und beim Speichern
	 * unverändert zurückgibt – geprüft wird allein, was update()/create() am
	 * Konto setzen.
	 */
	private function service(Account $account): AccountService {
		$mapper = $this->createMock(AccountMapper::class);
		$mapper->method('find')->willReturn($account);
		$mapper->method('update')->willReturnArgument(0);
		$mapper->method('insert')->willReturnArgument(0);

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
			$this->createMock(IL10N::class),
		);
	}
}
