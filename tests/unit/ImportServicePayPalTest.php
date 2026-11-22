<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\RuleMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\AccountService;
use OCA\Vereinsbuchhaltung\Service\BookingService;
use OCA\Vereinsbuchhaltung\Service\CamtCsvParser;
use OCA\Vereinsbuchhaltung\Service\ImportService;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportExtractionService;
use OCA\Vereinsbuchhaltung\Service\Statement\Camt053Parser;
use OCA\Vereinsbuchhaltung\Service\Statement\Mt940Parser;
use OCA\Vereinsbuchhaltung\Service\Statement\RowNormalizer;
use OCA\Vereinsbuchhaltung\Service\Statement\StatementParserRegistry;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\ClubCurrencyStub;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * PayPal-Aktivitätsdownload in Vorschau und Import (Issue #375): übersprungene
 * Zeilen werden gezählt, die eigene PayPal-Adresse erscheint im Klartext statt
 * als interner Schlüssel, und ein zweiter Import legt nichts doppelt an.
 */
class ImportServicePayPalTest extends TestCase {

	private BankTransactionMapper&MockObject $txMapper;
	/** @var list<string> */
	private array $storedHashes = [];

	protected function setUp(): void {
		$this->storedHashes = [];
		$this->txMapper = $this->createMock(BankTransactionMapper::class);
		$this->txMapper->method('findExistingHashes')->willReturnCallback(
			fn (string $userId, array $hashes): array => array_values(array_intersect($hashes, $this->storedHashes))
		);
		$this->txMapper->method('findDedupKeys')->willReturn([]);
		$this->txMapper->method('insert')->willReturnCallback(function (BankTransaction $tx): BankTransaction {
			$this->storedHashes[] = $tx->getHash();
			return $tx;
		});
	}

	private function fixtureLines(): array {
		$content = (string)file_get_contents(__DIR__ . '/../fixtures/beispiel-paypal.csv');
		return preg_split('/\r\n|\n|\r/', trim($content)) ?: [];
	}

	private function service(?AccountService $accounts = null): ImportService {
		$normalizer = new RowNormalizer();
		$rules = $this->createMock(RuleMapper::class);
		$rules->method('findAll')->willReturn([]);
		$journals = $this->createMock(JournalMapper::class);
		$journals->method('findManualBookingKeys')->willReturn([]);
		$transaction = $this->createMock(TransactionRunner::class);
		$transaction->method('run')->willReturnCallback(static fn (callable $work) => $work());
		$accounts ??= $this->createMock(AccountService::class);
		if (!$accounts instanceof MockObject) {
			throw new \LogicException('Mock erwartet');
		}

		return new ImportService(
			new StatementParserRegistry(new Camt053Parser($normalizer), new Mt940Parser($normalizer), new CamtCsvParser($normalizer)),
			$normalizer,
			$this->txMapper,
			$rules,
			$this->createMock(BookingService::class),
			$journals,
			$transaction,
			$this->createMock(SepaImportExtractionService::class),
			$accounts,
			ClubCurrencyStub::of($this),
		);
	}

	public function testVorschauZaehltUebersprungene(): void {
		$content = (string)file_get_contents(__DIR__ . '/../fixtures/beispiel-paypal.csv');

		$result = $this->service()->preview('book', $content);

		$this->assertSame('paypal', $result['format']);
		$this->assertSame(4, $result['new']);
		$this->assertSame(2, $result['skipped']);
	}

	public function testNurUebersprungeneZeilen(): void {
		$lines = $this->fixtureLines();
		// Kopfzeile, Memo-Zeile und USD-Zeile: nichts davon wird übernommen.
		$content = implode("\r\n", [$lines[0], $lines[4], $lines[5]]);

		$result = $this->service()->preview('book', $content);

		$this->assertSame(0, $result['new']);
		$this->assertSame(0, $result['total']);
		$this->assertSame(2, $result['skipped']);
	}

	public function testAndereFormateMeldenNullUebersprungene(): void {
		$content = (string)file_get_contents(__DIR__ . '/../fixtures/beispiel-camt.csv');

		$this->assertSame(0, $this->service()->preview('book', $content)['skipped']);
	}

	public function testUnbekanntePayPalAdresseInVorschau(): void {
		$accounts = $this->createMock(AccountService::class);
		$accounts->method('resolveBankAccount')->willReturnCallback(static function (string $userId, ?string $own): Account {
			throw new \InvalidArgumentException('Kein Geldkonto passt zu ' . $own . '.');
		});
		$content = (string)file_get_contents(__DIR__ . '/../fixtures/beispiel-paypal.csv');

		$result = $this->service($accounts)->preview('book', $content);

		$this->assertCount(1, $result['unresolvedAccounts']);
		$this->assertSame('kasse@example.org', $result['unresolvedAccounts'][0]['ownAccount']);
		$this->assertSame(4, $result['unresolvedAccounts'][0]['count']);
		$this->assertSame('Kein Geldkonto passt zu kasse@example.org.', $result['unresolvedAccounts'][0]['message']);
		$this->assertStringNotContainsString('PAYPAL', $result['unresolvedAccounts'][0]['message']);
	}

	public function testZweiterImportKeineDubletten(): void {
		$service = $this->service();
		$content = (string)file_get_contents(__DIR__ . '/../fixtures/beispiel-paypal.csv');

		$first = $service->commit('book', $content, false);
		$second = $service->commit('book', $content, false);

		$this->assertSame(4, $first['new']);
		$this->assertSame(0, $second['new']);
		$this->assertSame(4, $second['duplicate']);
	}
}
