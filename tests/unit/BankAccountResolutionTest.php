<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

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
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportSettingsService;
use OCA\Vereinsbuchhaltung\Service\SepaDebtorAccountService;
use OCA\Vereinsbuchhaltung\Service\Statement\BankAccountMatcher;
use OCA\Vereinsbuchhaltung\Service\Statement\RowNormalizer;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Welches Geldkonto ein importierter Umsatz bebucht (Issue #154).
 *
 * Gemeldet wurde: ein MT940-Auszug nennt das eigene Konto als
 * "BLZ/Kontonummer", am Geldkonto steht aber nur die IBAN. Es gab keinen
 * Treffer, und die Zuordnung buchte still auf das erste Bankkonto – bei
 * mehreren Bankkonten womöglich das falsche. Die IBAN enthält BLZ und
 * Kontonummer; daran lässt sich das Konto auch ohne IBAN im Auszug erkennen.
 * Was sich nicht eindeutig erkennen lässt, scheitert jetzt mit Erklärung.
 */
class BankAccountResolutionTest extends TestCase {

	// BLZ 50010517, Kontonummer 0648489890
	private const GIRO_IBAN = 'DE12500105170648489890';
	// BLZ 12030000, Kontonummer 0000202051
	private const TAGESGELD_IBAN = 'DE02120300000000202051';

	public function testDieIbanEntscheidetWieBisher(): void {
		$service = $this->service($giro = $this->bank(1, self::GIRO_IBAN), $tagesgeld = $this->bank(2, self::TAGESGELD_IBAN));

		$this->assertSame($tagesgeld, $service->resolveBankAccount('book', 'DE02 1203 0000 0000 2020 51'));
		$this->assertSame($giro, $service->resolveBankAccount('book', self::GIRO_IBAN));
	}

	/** Der gemeldete Fall: MT940 :25: "50010517/0648489890", der Parser behält die Kontonummer. */
	public function testDieKontonummerFindetDasKontoUeberDessenIban(): void {
		$service = $this->service($this->bank(1, self::TAGESGELD_IBAN), $giro = $this->bank(2, self::GIRO_IBAN));

		$this->assertSame($giro, $service->resolveBankAccount('book', '0648489890'));
	}

	public function testKontonummerOhneFuehrendeNullenUndMitWaehrung(): void {
		$service = $this->service($this->bank(1, self::GIRO_IBAN), $tagesgeld = $this->bank(2, self::TAGESGELD_IBAN));

		$this->assertSame($tagesgeld, $service->resolveBankAccount('book', '202051'));
		$this->assertSame($tagesgeld, $service->resolveBankAccount('book', '202051EUR'));
	}

	public function testNurDieBlzGenuegtWennSieEindeutigIst(): void {
		$service = $this->service($this->bank(1, self::GIRO_IBAN), $tagesgeld = $this->bank(2, self::TAGESGELD_IBAN));

		$this->assertSame($tagesgeld, $service->resolveBankAccount('book', '12030000'));
	}

	public function testDieKontonummerGehtVorDerBlz(): void {
		// 12030000 ist die BLZ des Tagesgeldkontos und zugleich die Kontonummer des zweiten Kontos.
		$service = $this->service($this->bank(1, self::TAGESGELD_IBAN), $zweites = $this->bank(2, 'DE89500105170012030000'));

		$this->assertSame($zweites, $service->resolveBankAccount('book', '12030000'));
	}

	public function testUnbekanntesKontoBeiMehrerenBankkontenScheitertMitErklaerung(): void {
		$service = $this->service($this->bank(1, self::GIRO_IBAN), $this->bank(2, self::TAGESGELD_IBAN));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('9999999999');
		$service->resolveBankAccount('book', '9999999999');
	}

	public function testZweiKontenMitDerselbenBlzSindNichtEindeutig(): void {
		$service = $this->service($this->bank(1, self::GIRO_IBAN), $this->bank(2, 'DE89500105170012030000'));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('1200 Bank 1, 1200 Bank 2');
		$service->resolveBankAccount('book', '50010517');
	}

	public function testMitNurEinemBankkontoBleibtEsBeiDiesem(): void {
		$service = $this->service($this->other(5), $giro = $this->bank(1, self::TAGESGELD_IBAN));

		$this->assertSame($giro, $service->resolveBankAccount('book', '0648489890'));
	}

	/** Ohne Kontoangabe – oder mit bloßer Bezeichnung wie „Girokonto" – gibt es nichts zu verwechseln. */
	public function testOhneKontoangabeNimmtDieAppDasErsteBankkonto(): void {
		$service = $this->service($giro = $this->bank(1, self::GIRO_IBAN), $this->bank(2, self::TAGESGELD_IBAN));

		$this->assertSame($giro, $service->resolveBankAccount('book', null));
		$this->assertSame($giro, $service->resolveBankAccount('book', 'Girokonto'));
	}

	public function testGeldkontoOhneIbanIstKeinKandidat(): void {
		$service = $this->service($this->bank(1, null), $giro = $this->bank(2, self::GIRO_IBAN));

		$this->assertSame($giro, $service->resolveBankAccount('book', '648489890'));
	}

	public function testOhneBankkontoMeldetDieAppDasFehlende(): void {
		$service = $this->service($this->other(5));

		$this->expectException(DoesNotExistException::class);
		$service->resolveBankAccount('book', self::GIRO_IBAN);
	}

	public function testAuslaendischeIbanTrifftNurGenau(): void {
		$matcher = new BankAccountMatcher();
		$at = $this->bank(1, 'AT611904300234573201');

		$this->assertSame([$at], $matcher->candidates('AT611904300234573201', [$at]));
		$this->assertSame([], $matcher->candidates('00234573201', [$at]));
	}

	private function bank(int $id, ?string $iban): Account {
		$account = new Account();
		$account->setId($id);
		$account->setNumber('1200');
		$account->setName('Bank ' . $id);
		$account->setIsBank(true);
		$account->setIban($iban);
		return $account;
	}

	private function other(int $id): Account {
		$account = new Account();
		$account->setId($id);
		$account->setNumber('4000');
		$account->setName('Beiträge');
		$account->setIsBank(false);
		return $account;
	}

	private function service(Account ...$accounts): AccountService {
		$mapper = $this->createMock(AccountMapper::class);
		$mapper->method('findAll')->willReturn($accounts);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new AccountService(
			$mapper,
			$this->createMock(JournalLineMapper::class),
			$this->createMock(RuleMapper::class),
			$this->createMock(BudgetMapper::class),
			$this->createMock(TransactionRunner::class),
			new RowNormalizer(),
			$this->createMock(CostCenterService::class),
			$this->createMock(PeriodService::class),
			$this->createMock(AuditService::class),
			$this->createMock(IbanValidator::class),
			$this->createMock(SepaDebtorAccountService::class),
			$this->createMock(SepaImportSettingsService::class),
			$l10n,
		);
	}
}
