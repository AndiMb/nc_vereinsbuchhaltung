<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\Rule;
use OCA\Vereinsbuchhaltung\Db\RuleMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\AccountService;
use OCA\Vereinsbuchhaltung\Service\BookingService;
use OCA\Vereinsbuchhaltung\Service\ImportService;
use OCA\Vereinsbuchhaltung\Service\InvoiceMatchingService;
use OCA\Vereinsbuchhaltung\Service\InvoicePaymentService;
use OCA\Vereinsbuchhaltung\Service\InvoiceSettings;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportExtractionService;
use OCA\Vereinsbuchhaltung\Service\Statement\RowNormalizer;
use OCA\Vereinsbuchhaltung\Service\Statement\StatementParserRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Der Commit-Ablauf des Kontoauszugs-Imports, soweit er die SEPA-Erkennung
 * betrifft (Issue #72/#107): jede neue Zeile geht samt ihren Rohdetails an den
 * Extraktionsdienst, die als Rücklastschrift gekennzeichneten Detailzeilen
 * zählt der Import als `sepaReturnsDetected` zusammen – die einzige Quelle für den
 * Hinweis „mögliche Rücklastschrift erkannt“ (Import-Dialog, Wachordner-Protokoll)
 * seit die Text-Heuristik des flachen Alt-Moduls entfallen ist. Die Dublettenregeln
 * selbst deckt der Import-Teil der E2E-Specs ab.
 */
class ImportServiceTest extends TestCase {

	private BankTransactionMapper&MockObject $txMapper;
	private SepaImportExtractionService&MockObject $sepaDetails;
	private RowNormalizer&MockObject $normalizer;

	protected function setUp(): void {
		$this->txMapper = $this->createMock(BankTransactionMapper::class);
		$this->txMapper->method('findExistingHashes')->willReturn([]);
		$this->txMapper->method('findDedupKeys')->willReturn([]);
		$this->txMapper->method('insert')->willReturnArgument(0);

		$this->normalizer = $this->createMock(RowNormalizer::class);
		$this->normalizer->method('softKey')->willReturn(null);
		$this->normalizer->method('matchTexts')->willReturn([]);

		$this->sepaDetails = $this->createMock(SepaImportExtractionService::class);
	}

	private function service(?StatementParserRegistry $parsers = null, ?AccountService $accounts = null): ImportService {
		$rules = $this->createMock(RuleMapper::class);
		$rules->method('findAll')->willReturn([]);
		$journals = $this->createMock(JournalMapper::class);
		$journals->method('findManualBookingKeys')->willReturn([]);
		$transaction = $this->createMock(TransactionRunner::class);
		$transaction->method('run')->willReturnCallback(static fn (callable $work) => $work());

		return new ImportService(
			$parsers ?? $this->createMock(StatementParserRegistry::class),
			$this->normalizer,
			$this->txMapper,
			$rules,
			$this->createMock(BookingService::class),
			$journals,
			$transaction,
			$this->sepaDetails,
			$accounts ?? $this->createMock(AccountService::class),
		);
	}

	/**
	 * @param list<array<string,mixed>> $sepaDetails
	 * @return array<string,mixed>
	 */
	private function row(string $hash, int $amountCents, array $sepaDetails = []): array {
		return [
			'hash' => $hash,
			'bookingDate' => '2026-10-01',
			'valueDate' => '2026-10-01',
			'amountCents' => $amountCents,
			'bookingText' => null,
			'purpose' => 'Test',
			'counterparty' => 'Gegenseite',
			'counterpartyIban' => null,
			'counterpartyBic' => null,
			'ownAccount' => null,
			'sepaDetails' => $sepaDetails,
		];
	}

	public function testDieErkanntenRuecklastschriftenAllerNeuenZeilenWerdenGezaehlt(): void {
		$this->sepaDetails->method('extract')->willReturnOnConsecutiveCalls(1, 0, 2);

		$result = $this->service()->commitRows('book', [
			$this->row('h1', -6000),
			$this->row('h2', 6000),
			$this->row('h3', -1200),
		], 'camt053');

		$this->assertSame(3, $result['new']);
		$this->assertSame(3, $result['sepaReturnsDetected']);
	}

	public function testOhneErkannteRuecklastschriftMeldetDerImportNull(): void {
		$this->sepaDetails->method('extract')->willReturn(0);

		$result = $this->service()->commitRows('book', [$this->row('h1', 6000)], 'camt053');

		$this->assertSame(0, $result['sepaReturnsDetected']);
	}

	public function testJedeNeueZeileGehtMitIhrenRohdetailsAnDenExtraktionsdienst(): void {
		$details = [['endToEndId' => 'E2E-1', 'isReturn' => true]];
		$this->sepaDetails->expects($this->once())->method('extract')
			->with($this->isInstanceOf(BankTransaction::class), $details)
			->willReturn(1);

		$this->service()->commitRows('book', [$this->row('h1', -6000, $details)], 'camt053');
	}

	public function testEineZeileOhneSepaDetailsGehtMitLeererListeDurch(): void {
		$row = $this->row('h1', 6000);
		unset($row['sepaDetails']);
		$this->sepaDetails->expects($this->once())->method('extract')
			->with($this->isInstanceOf(BankTransaction::class), [])
			->willReturn(0);

		$this->service()->commitRows('book', [$row], 'mt940');
	}

	public function testEineDubletteWirdWederGespeichertNochAusgewertet(): void {
		$txMapper = $this->createMock(BankTransactionMapper::class);
		$txMapper->method('findExistingHashes')->willReturn(['h1']);
		$txMapper->method('findDedupKeys')->willReturn([]);
		$txMapper->expects($this->never())->method('insert');
		$this->txMapper = $txMapper;
		$this->sepaDetails->expects($this->never())->method('extract');

		$result = $this->service()->commitRows('book', [$this->row('h1', -6000)], 'camt053');

		$this->assertSame(0, $result['new']);
		$this->assertSame(1, $result['duplicate']);
		$this->assertSame(0, $result['sepaReturnsDetected']);
	}

	/**
	 * Die Vorschau nennt eigene Konten, die zu keinem Geldkonto eindeutig
	 * passen, je Konto einmal mit der Zahl der Umsätze (Issue #154). Konten,
	 * die sich auflösen lassen, und Zeilen ohne eigenes Konto bleiben stumm.
	 */
	public function testVorschauNenntKontenOhneEindeutigesGeldkonto(): void {
		$rows = [$this->row('h1', 100), $this->row('h2', 200), $this->row('h3', 300), $this->row('h4', 400)];
		$rows[0]['ownAccount'] = '0648489890';
		$rows[1]['ownAccount'] = '0648489890';
		$rows[2]['ownAccount'] = 'DE02120300000000202051';
		$parsers = $this->createMock(StatementParserRegistry::class);
		$parsers->method('parse')->willReturn([$rows, 'mt940']);

		$accounts = $this->createMock(AccountService::class);
		$accounts->method('resolveBankAccount')->willReturnCallback(static function (string $userId, ?string $own): Account {
			if ($own === '0648489890') {
				throw new \InvalidArgumentException('passt zu keinem Geldkonto');
			}
			return new Account();
		});

		$preview = $this->service($parsers, $accounts)->preview('book', 'egal');

		$this->assertSame([['ownAccount' => '0648489890', 'count' => 2, 'message' => 'passt zu keinem Geldkonto']], $preview['unresolvedAccounts']);
	}

	/**
	 * Importdienst mit Regel „Verwendungszweck enthält Test → Konto 5“ und den
	 * Diensten für den Rechnungsabgleich.
	 */
	private function serviceWithInvoices(InvoiceMatchingService $matching, InvoicePaymentService $payments, BookingService $booking, bool $autoAssign = true): ImportService {
		$rule = new Rule();
		$rule->setMatchField('purpose');
		$rule->setMatchValue('Test');
		$rule->setContraAccountId(5);
		$rules = $this->createMock(RuleMapper::class);
		$rules->method('findAll')->willReturn([$rule]);
		$journals = $this->createMock(JournalMapper::class);
		$journals->method('findManualBookingKeys')->willReturn([]);
		$transaction = $this->createMock(TransactionRunner::class);
		$transaction->method('run')->willReturnCallback(static fn (callable $work) => $work());
		$settings = $this->createMock(InvoiceSettings::class);
		$settings->method('autoAssign')->willReturn($autoAssign);

		return new ImportService(
			$this->createMock(StatementParserRegistry::class),
			$this->normalizer,
			$this->txMapper,
			$rules,
			$booking,
			$journals,
			$transaction,
			$this->sepaDetails,
			$this->createMock(AccountService::class),
			$matching,
			$payments,
			$settings,
		);
	}

	public function testEindeutigerRechnungstreffergehtJederRegelVor(): void {
		$matching = $this->createMock(InvoiceMatchingService::class);
		$matching->method('autoMatch')->willReturn(['invoiceId' => 3, 'number' => 'RE-2026-0003']);
		$payments = $this->createMock(InvoicePaymentService::class);
		$payments->expects($this->once())->method('payFromBankTransaction')->with($this->isInstanceOf(BankTransaction::class), 3);
		$booking = $this->createMock(BookingService::class);
		$booking->expects($this->never())->method('assign');

		$result = $this->serviceWithInvoices($matching, $payments, $booking)->commitRows('book', [$this->row('h1', 6000)], 'camt053');

		$this->assertSame(1, $result['autoAssigned']);
	}

	public function testOhneRechnungstrefferGreiftDieRegel(): void {
		$matching = $this->createMock(InvoiceMatchingService::class);
		$matching->method('autoMatch')->willReturn(null);
		$payments = $this->createMock(InvoicePaymentService::class);
		$payments->expects($this->never())->method('payFromBankTransaction');
		$booking = $this->createMock(BookingService::class);
		$booking->expects($this->once())->method('assign')->with($this->isInstanceOf(BankTransaction::class), 5);

		$result = $this->serviceWithInvoices($matching, $payments, $booking)->commitRows('book', [$this->row('h1', 6000)], 'camt053');

		$this->assertSame(1, $result['autoAssigned']);
	}

	public function testScheitertDieRechnungsbuchungFaelltDerImportAufDieRegelZurueck(): void {
		$matching = $this->createMock(InvoiceMatchingService::class);
		$matching->method('autoMatch')->willReturn(['invoiceId' => 3, 'number' => 'RE-2026-0003']);
		$payments = $this->createMock(InvoicePaymentService::class);
		$payments->method('payFromBankTransaction')->willThrowException(new \InvalidArgumentException('Zeitraum abgeschlossen'));
		$booking = $this->createMock(BookingService::class);
		$booking->expects($this->once())->method('assign');

		$this->serviceWithInvoices($matching, $payments, $booking)->commitRows('book', [$this->row('h1', 6000)], 'camt053');
	}

	public function testAusgeschalteterRechnungsabgleichWirdNichtBefragt(): void {
		$matching = $this->createMock(InvoiceMatchingService::class);
		$matching->expects($this->never())->method('autoMatch');
		$payments = $this->createMock(InvoicePaymentService::class);
		$payments->expects($this->never())->method('payFromBankTransaction');

		$this->serviceWithInvoices($matching, $payments, $this->createMock(BookingService::class), false)
			->commitRows('book', [$this->row('h1', 6000)], 'camt053');
	}
}
