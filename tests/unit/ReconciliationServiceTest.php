<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Service\CamtCsvParser;
use OCA\Vereinsbuchhaltung\Service\Reconciliation\ReconciliationService;
use OCA\Vereinsbuchhaltung\Service\Reconciliation\StatementMatcher;
use OCA\Vereinsbuchhaltung\Service\Statement\Camt053Parser;
use OCA\Vereinsbuchhaltung\Service\Statement\Mt940Parser;
use OCA\Vereinsbuchhaltung\Service\Statement\RowNormalizer;
use OCA\Vereinsbuchhaltung\Service\Statement\StatementParserRegistry;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Kontoabstimmung (Issue #159): Auszug gegen die Bewegungen eines Geldkontos,
 * samt Zerlegung der Saldendifferenz. Die Datenbank ist durch eine kleine
 * Buchführung im Test ersetzt, aus der alle Mapper-Antworten folgen.
 */
class ReconciliationServiceTest extends TestCase {
	private const USER = '__verein__';
	private const IBAN = 'DE12500105170648489890';
	private const OTHER_IBAN = 'DE89370400440532013000';
	private const HEAD = 'Auftragskonto;Buchungstag;Valutadatum;Buchungstext;Verwendungszweck;Beguenstigter/Zahlungspflichtiger;Betrag;Waehrung;Saldo nach Buchung;Info';

	/** @var list<Account> */
	private array $accounts;
	/** Kontostand je Konto vor allen Bewegungen des Tests. */
	private array $openingStock = [1 => 100000, 2 => 0];
	/** @var list<array{accountId:int, journalId:int, date:string, description:string, bankTxId:?int, netCents:int}> */
	private array $ledger = [];
	/** @var array<int, BankTransaction> */
	private array $transactions = [];

	protected function setUp(): void {
		$this->accounts = [$this->account(1, '1200', 'Girokonto', self::IBAN), $this->account(2, '1210', 'Sparkonto', self::OTHER_IBAN)];
	}

	private function account(int $id, string $number, string $name, ?string $iban, bool $isBank = true): Account {
		$a = new Account();
		$a->setId($id);
		$a->setNumber($number);
		$a->setName($name);
		$a->setType('asset');
		$a->setIsBank($isBank);
		$a->setIban($iban);
		return $a;
	}

	private function booking(int $journalId, string $date, int $cents, string $description = '', ?int $bankTxId = null, int $accountId = 1, bool $opening = false): void {
		$this->ledger[] = ['accountId' => $accountId, 'journalId' => $journalId, 'date' => $date, 'description' => $description, 'bankTxId' => $bankTxId, 'netCents' => $cents, 'opening' => $opening];
	}

	private function tx(int $id, string $date, int $cents, string $status, ?string $ownAccount = self::IBAN, string $counterparty = 'Max Mustermann', string $purpose = 'Beitrag'): void {
		$t = new BankTransaction();
		$t->setId($id);
		$t->setBookingDate($date);
		$t->setAmountCents($cents);
		$t->setStatus($status);
		$t->setOwnAccount($ownAccount);
		$t->setCounterparty($counterparty);
		$t->setPurpose($purpose);
		$this->transactions[$id] = $t;
	}

	/** @param list<array{0:string,1:string,2:string,3?:string,4?:string}> $rows Datum, Betrag, Saldo, Zahlungsbeteiligter+Zweck-Kürzel, Konto */
	private function csv(array $rows, bool $withBalance = true): string {
		$lines = [$withBalance ? self::HEAD : str_replace(';Saldo nach Buchung', '', self::HEAD)];
		foreach ($rows as $r) {
			$cols = [$r[4] ?? self::IBAN, $r[0], $r[0], 'GUTSCHRIFT', 'Beitrag', $r[3] ?? 'Max Mustermann', $r[1], 'EUR'];
			if ($withBalance) {
				$cols[] = $r[2];
			}
			$cols[] = 'Umsatz gebucht';
			$lines[] = implode(';', $cols);
		}
		return implode("\n", $lines) . "\n";
	}

	private function service(): ReconciliationService {
		$accountMapper = $this->createMock(AccountMapper::class);
		$accountMapper->method('findAll')->willReturnCallback(fn (): array => $this->accounts);
		$accountMapper->method('find')->willReturnCallback(function (int $id): Account {
			foreach ($this->accounts as $a) {
				if ($a->getId() === $id) {
					return $a;
				}
			}
			throw new \OCP\AppFramework\Db\DoesNotExistException('nicht gefunden');
		});

		$journalMapper = $this->createMock(JournalMapper::class);
		$journalMapper->method('findAccountMovements')->willReturnCallback(function (string $user, int $accountId, string $from, string $to): array {
			$rows = array_filter($this->ledger, static fn (array $m): bool => $m['accountId'] === $accountId && $m['date'] >= $from && $m['date'] <= $to);
			usort($rows, static fn (array $a, array $b): int => [$a['date'], $a['journalId']] <=> [$b['date'], $b['journalId']]);
			return array_map(static function (array $m): array {
				unset($m['accountId']);
				return $m;
			}, $rows);
		});

		$lineMapper = $this->createMock(JournalLineMapper::class);
		$lineMapper->method('sumByAccount')->willReturnCallback(function (string $user, ?string $from, ?string $to): array {
			$sums = [];
			foreach ($this->openingStock as $id => $cents) {
				$sums[$id] = ['debit' => $cents, 'credit' => 0];
			}
			foreach ($this->ledger as $m) {
				if ($to === null || $m['date'] <= $to) {
					$sums[$m['accountId']]['debit'] += max($m['netCents'], 0);
					$sums[$m['accountId']]['credit'] += max(-$m['netCents'], 0);
				}
			}
			return $sums;
		});

		$txMapper = $this->createMock(BankTransactionMapper::class);
		$txMapper->method('findUnassignedInRange')->willReturnCallback(function (string $user, ?string $from, ?string $to): array {
			return array_values(array_filter($this->transactions, static fn (BankTransaction $t): bool => $t->getStatus() === 'unassigned'
				&& ($from === null || $t->getBookingDate() >= $from) && ($to === null || $t->getBookingDate() <= $to)));
		});
		$txMapper->method('findByIds')->willReturnCallback(fn (string $user, array $ids): array => array_intersect_key($this->transactions, array_flip($ids)));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => $params === [] ? $text : vsprintf($text, $params));

		$n = new RowNormalizer();
		return new ReconciliationService(
			$accountMapper,
			$journalMapper,
			$lineMapper,
			$txMapper,
			new StatementParserRegistry(new Camt053Parser($n), new Mt940Parser($n), new CamtCsvParser($n)),
			new StatementMatcher($n),
			$n,
			$l10n,
		);
	}

	private function twoRows(): string {
		return $this->csv([
			['02.01.2026', '60,00', '1.060,00'],
			['05.01.2026', '-250,00', '810,00', 'Stadtwerke'],
		]);
	}

	public function testStimmtUeberein(): void {
		$this->booking(10, '2026-01-02', 6000, 'Max Mustermann Beitrag');
		$this->booking(11, '2026-01-05', -25000, 'Stadtwerke Beitrag');

		$r = $this->service()->reconcile(self::USER, 1, $this->twoRows(), null, null);

		$this->assertSame([], $r['surplus']);
		$this->assertSame([], $r['missing']);
		$this->assertSame([], $r['shifted']);
		$this->assertSame(['id' => 1, 'number' => '1200', 'name' => 'Girokonto'], $r['account']);
		$this->assertSame(['from' => '2026-01-02', 'to' => '2026-01-05', 'statementFrom' => '2026-01-02', 'statementTo' => '2026-01-05'], $r['period']);
		$this->assertSame(2, $r['counts']['statementRows']);
		$this->assertSame(2, $r['counts']['matched']);
		$this->assertSame(100000, $r['balance']['bankOpeningCents']);
		$this->assertSame(81000, $r['balance']['bankClosingCents']);
		$this->assertSame(100000, $r['balance']['appOpeningCents']);
		$this->assertSame(81000, $r['balance']['appClosingCents']);
		$this->assertSame(0, $r['balance']['diffClosingCents']);
		$this->assertSame(0, $r['balance']['restCents']);
		$this->assertSame([], $r['balance']['gaps']);
	}

	public function testDubletteErklaertDieDifferenz(): void {
		$this->booking(10, '2026-01-02', 6000, 'Max Mustermann Beitrag');
		$this->booking(11, '2026-01-05', -25000, 'Stadtwerke Beitrag');
		$this->booking(12, '2026-01-02', 6000, 'Max Mustermann Beitrag');

		$r = $this->service()->reconcile(self::USER, 1, $this->twoRows(), null, null);

		$this->assertCount(1, $r['surplus']);
		$this->assertSame('booking', $r['surplus'][0]['kind']);
		$this->assertSame([12], $r['surplus'][0]['journalIds']);
		$this->assertNull($r['surplus'][0]['bankTxId']);
		$this->assertSame('2026-01-02', $r['surplus'][0]['date']);
		$this->assertSame(6000, $r['surplus'][0]['amountCents']);
		$this->assertSame('Max Mustermann Beitrag', $r['surplus'][0]['text']);
		$this->assertSame(6000, $r['balance']['surplusCents']);
		$this->assertSame(0, $r['balance']['diffOpeningCents']);
		$this->assertSame(6000, $r['balance']['diffClosingCents']);
		$this->assertSame(0, $r['balance']['restCents']);
	}

	public function testFehlendeZeile(): void {
		$this->booking(10, '2026-01-02', 6000, 'Max Mustermann Beitrag');

		$r = $this->service()->reconcile(self::USER, 1, $this->twoRows(), null, null);

		$this->assertCount(1, $r['missing']);
		$this->assertSame('2026-01-05', $r['missing'][0]['date']);
		$this->assertSame(-25000, $r['missing'][0]['amountCents']);
		$this->assertSame('Stadtwerke', $r['missing'][0]['counterparty']);
		$this->assertSame('Beitrag', $r['missing'][0]['text']);
		$this->assertSame(-25000, $r['balance']['missingCents']);
		$this->assertSame(25000, $r['balance']['diffClosingCents']);
		$this->assertSame(0, $r['balance']['restCents']);
	}

	public function testAbweichenderAnfangBleibtAlsAnfangsdifferenz(): void {
		$this->openingStock[1] = 100500;
		$this->booking(10, '2026-01-02', 6000, 'Max Mustermann Beitrag');
		$this->booking(11, '2026-01-05', -25000, 'Stadtwerke Beitrag');

		$r = $this->service()->reconcile(self::USER, 1, $this->twoRows(), null, null);

		$this->assertSame(500, $r['balance']['diffOpeningCents']);
		$this->assertSame(500, $r['balance']['diffClosingCents']);
		$this->assertSame(0, $r['balance']['restCents']);
		$this->assertSame([], $r['surplus']);
	}

	public function testZugeordneterUmsatzNutztDieTexteDesUmsatzes(): void {
		$this->tx(7, '2026-01-02', 6000, 'assigned');
		$this->booking(10, '2026-01-02', 6000, 'Mitgliedsbeiträge', 7);
		$this->booking(11, '2026-01-05', -25000, 'Stadtwerke Beitrag');

		$r = $this->service()->reconcile(self::USER, 1, $this->twoRows(), null, null);

		$this->assertSame([], $r['surplus']);
		$this->assertSame([], $r['missing']);
	}

	public function testAufgeteilterUmsatzZaehltEinmal(): void {
		$this->tx(7, '2026-01-05', -25000, 'assigned', self::IBAN, 'Stadtwerke');
		$this->booking(10, '2026-01-02', 6000, 'Max Mustermann Beitrag');
		$this->booking(11, '2026-01-05', -10000, 'Strom', 7);
		$this->booking(12, '2026-01-05', -15000, 'Wasser', 7);

		$r = $this->service()->reconcile(self::USER, 1, $this->twoRows(), null, null);

		$this->assertSame([], $r['surplus']);
		$this->assertSame([], $r['missing']);
		$this->assertSame(2, $r['counts']['appMovements']);
	}

	public function testNichtZugeordneterUmsatzZaehltZumSaldoUndAlsBewegung(): void {
		$this->booking(10, '2026-01-02', 6000, 'Max Mustermann Beitrag');
		$this->tx(8, '2026-01-05', -25000, 'unassigned', self::IBAN, 'Stadtwerke');
		// Doppelt importiert: derselbe Umsatz noch einmal.
		$this->tx(9, '2026-01-05', -25000, 'unassigned', self::IBAN, 'Stadtwerke');

		$r = $this->service()->reconcile(self::USER, 1, $this->twoRows(), null, null);

		$this->assertCount(1, $r['surplus']);
		$this->assertSame('unassigned', $r['surplus'][0]['kind']);
		$this->assertSame(9, $r['surplus'][0]['bankTxId']);
		$this->assertSame([], $r['surplus'][0]['journalIds']);
		$this->assertSame(81000 - 25000, $r['balance']['appClosingCents']);
		$this->assertSame(-25000, $r['balance']['diffClosingCents']);
		$this->assertSame(0, $r['balance']['restCents']);
	}

	public function testNichtZugeordneterUmsatzGehoertZumKontoSeinerIban(): void {
		$this->booking(10, '2026-01-02', 6000, 'Max Mustermann Beitrag');
		$this->booking(11, '2026-01-05', -25000, 'Stadtwerke Beitrag');
		$this->tx(8, '2026-01-03', 999, 'unassigned', self::OTHER_IBAN);
		$this->tx(9, '2026-01-03', 111, 'unassigned', 'DE00999999999999999999');

		$r = $this->service()->reconcile(self::USER, 1, $this->twoRows(), null, null);

		$this->assertSame([], $r['surplus']);
		$this->assertSame(1, $r['counts']['skippedUnassigned']);
		$this->assertSame(0, $r['balance']['diffClosingCents']);
	}

	/** Dieselbe Regel wie beim Zuordnen: ohne Kontoangabe gilt das erste Geldkonto – und nur dieses. */
	public function testUmsatzOhneKontoangabeZaehltNurBeimErstenGeldkonto(): void {
		$this->tx(8, '2026-01-03', 999, 'unassigned', null);

		$first = $this->service()->reconcile(self::USER, 1, $this->twoRows(), null, null);
		$this->assertSame([8], array_column($first['surplus'], 'bankTxId'));

		$second = $this->service()->reconcile(self::USER, 2, $this->csv([['03.01.2026', '5,00', '5,00', 'Zins', self::OTHER_IBAN]]), null, null);
		$this->assertSame([], $second['surplus']);
	}

	public function testFremdesKontoInDerDatei(): void {
		$this->booking(10, '2026-01-02', 6000, 'Max Mustermann Beitrag');
		$this->booking(11, '2026-01-05', -25000, 'Stadtwerke Beitrag');
		$content = $this->csv([
			['02.01.2026', '60,00', '1.060,00'],
			['03.01.2026', '5,00', '25,00', 'Zins', self::OTHER_IBAN],
			['05.01.2026', '-250,00', '810,00', 'Stadtwerke'],
		]);

		$r = $this->service()->reconcile(self::USER, 1, $content, null, null);

		$this->assertSame(2, $r['counts']['statementRows']);
		$this->assertSame(1, $r['counts']['otherAccountRows']);
		$this->assertSame([], $r['missing']);
		$this->assertSame(0, $r['balance']['diffClosingCents']);
	}

	public function testAuszugNurEinesAnderenKontos(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage(self::OTHER_IBAN);

		$this->service()->reconcile(self::USER, 1, $this->csv([['03.01.2026', '5,00', '25,00', 'Zins', self::OTHER_IBAN]]), null, null);
	}

	/** Eine Eröffnungsbuchung im Zeitraum ist der Anfangsbestand, keine Bewegung – der Auszug kennt sie nicht. */
	public function testEroeffnungsbuchungImZeitraumZaehltZumAnfangssaldo(): void {
		$this->openingStock[1] = 0;
		$this->booking(1, '2026-01-02', 100000, 'Eröffnungsbuchung 1200 Girokonto', null, 1, true);
		$this->booking(10, '2026-01-02', 6000, 'Max Mustermann Beitrag');
		$this->booking(11, '2026-01-05', -25000, 'Stadtwerke Beitrag');

		$r = $this->service()->reconcile(self::USER, 1, $this->twoRows(), null, null);

		$this->assertSame([], $r['surplus']);
		$this->assertSame([], $r['missing']);
		$this->assertSame(100000, $r['balance']['appOpeningCents']);
		$this->assertSame(0, $r['balance']['diffOpeningCents']);
		$this->assertSame(0, $r['balance']['diffClosingCents']);
		$this->assertSame(0, $r['balance']['restCents']);
	}

	/** Auch mit nur einem Geldkonto: nennt der Auszug eine andere IBAN als das Konto, ist es die falsche Datei. */
	public function testEinzigesGeldkontoMitAndererIbanImAuszug(): void {
		$this->accounts = [$this->account(1, '1200', 'Girokonto', self::IBAN)];
		$this->booking(10, '2026-01-03', 500, 'Zins');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage(self::OTHER_IBAN);

		$this->service()->reconcile(self::USER, 1, $this->csv([['03.01.2026', '5,00', '25,00', 'Zins', self::OTHER_IBAN]]), null, null);
	}

	/** Ein Geldkonto ohne IBAN lässt sich nicht gegenprüfen – der Auszug gilt dann für das gewählte Konto. */
	public function testGeldkontoOhneIbanNimmtJedenAuszug(): void {
		$this->accounts = [$this->account(1, '1200', 'Girokonto', null)];
		$this->booking(10, '2026-01-03', 500, 'Zins Beitrag');

		$r = $this->service()->reconcile(self::USER, 1, $this->csv([['03.01.2026', '5,00', '25,00', 'Zins', self::OTHER_IBAN]]), null, null);

		$this->assertSame(1, $r['counts']['matched']);
	}

	/** Zeilen ohne Kontoangabe gehören zum gewählten Konto, nicht zum ersten Geldkonto. */
	public function testZeilenOhneKontoangabeGehoerenZumGewaehltenKonto(): void {
		$this->booking(20, '2026-01-02', 6000, 'Max Mustermann Beitrag', null, 2);
		$content = $this->csv([['02.01.2026', '60,00', '60,00', 'Max Mustermann', '']]);

		$r = $this->service()->reconcile(self::USER, 2, $content, null, null);

		$this->assertSame(1, $r['counts']['statementRows']);
		$this->assertSame(0, $r['counts']['otherAccountRows']);
		$this->assertSame([], $r['missing']);
		$this->assertSame([], $r['surplus']);
		$this->assertSame(0, $r['balance']['diffClosingCents']);
	}

	/** Ragt der Zeitraum über den Auszug hinaus (Tippfehler im Jahr), gilt nur der Teil, den der Auszug abdeckt. */
	public function testZeitraumWirdAufDenAuszugBegrenzt(): void {
		$this->openingStock[1] = 95000;
		$this->booking(5, '2025-06-01', 5000, 'Altes Jahr');
		$this->booking(10, '2026-01-02', 6000, 'Max Mustermann Beitrag');
		$this->booking(11, '2026-01-05', -25000, 'Stadtwerke Beitrag');
		$this->booking(12, '2026-03-01', 700, 'Später');

		$r = $this->service()->reconcile(self::USER, 1, $this->twoRows(), '2025-01-02', '2026-12-31');

		$this->assertSame('2026-01-02', $r['period']['from']);
		$this->assertSame('2026-01-05', $r['period']['to']);
		$this->assertSame([], $r['surplus']);
		$this->assertSame(0, $r['balance']['diffOpeningCents']);
		$this->assertSame(0, $r['balance']['diffClosingCents']);
	}

	public function testKeinGeldkonto(): void {
		$this->accounts[] = $this->account(3, '4000', 'Beiträge', null, false);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->reconcile(self::USER, 3, $this->twoRows(), null, null);
	}

	public function testEingeengterZeitraum(): void {
		$content = $this->csv([
			['02.01.2026', '60,00', '1.060,00'],
			['05.01.2026', '-250,00', '810,00', 'Stadtwerke'],
			['08.01.2026', '10,00', '820,00'],
			['20.01.2026', '-20,00', '800,00', 'Stadtwerke'],
		]);
		$this->booking(10, '2026-01-02', 6000, 'Max Mustermann Beitrag');
		$this->booking(11, '2026-01-05', -25000, 'Stadtwerke Beitrag');
		$this->booking(13, '2026-01-20', -2000, 'Stadtwerke Beitrag');

		$r = $this->service()->reconcile(self::USER, 1, $content, '2026-01-05', '2026-01-10');

		$this->assertSame(['from' => '2026-01-05', 'to' => '2026-01-10', 'statementFrom' => '2026-01-02', 'statementTo' => '2026-01-20'], $r['period']);
		$this->assertSame(2, $r['counts']['statementRows']);
		$this->assertSame(106000, $r['balance']['bankOpeningCents']);
		$this->assertSame(82000, $r['balance']['bankClosingCents']);
		$this->assertSame(106000, $r['balance']['appOpeningCents']);
		$this->assertSame(81000, $r['balance']['appClosingCents']);
		$this->assertSame([1000], array_column($r['missing'], 'amountCents'));
		$this->assertSame(-1000, $r['balance']['diffClosingCents']);
		$this->assertSame(0, $r['balance']['restCents']);
	}

	public function testVerschobenesPaarStehtInDerZerlegung(): void {
		$this->booking(10, '2026-01-02', 6000, 'Max Mustermann Beitrag');
		// Die Bank bucht am 05.01., die App führt die Zahlung erst am 07.01. – außerhalb des Auszugs.
		$this->booking(11, '2026-01-07', -25000, 'Stadtwerke Beitrag');

		$r = $this->service()->reconcile(self::USER, 1, $this->twoRows(), null, null);

		$this->assertSame([], $r['missing']);
		$this->assertSame([], $r['surplus']);
		$this->assertCount(1, $r['shifted']);
		$this->assertSame('2026-01-05', $r['shifted'][0]['statement']['date']);
		$this->assertSame('2026-01-07', $r['shifted'][0]['app']['date']);
		$this->assertSame([11], $r['shifted'][0]['app']['journalIds']);
		$this->assertSame(-25000, $r['balance']['shiftedCents']);
		$this->assertSame(25000, $r['balance']['diffClosingCents']);
		$this->assertSame(0, $r['balance']['restCents']);
	}

	public function testVonNachBis(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->reconcile(self::USER, 1, $this->twoRows(), '2026-01-05', '2026-01-02');
	}

	public function testZeitraumAusserhalbDesAuszugs(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->reconcile(self::USER, 1, $this->twoRows(), '2026-03-01', '2026-03-31');
	}

	public function testUngueltigesDatum(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->reconcile(self::USER, 1, $this->twoRows(), '05.01.2026', null);
	}

	public function testToleranzWirdGeklemmt(): void {
		$content = $this->csv([['02.01.2026', '60,00', '1.060,00'], ['15.01.2026', '-250,00', '810,00', 'Stadtwerke']]);
		$this->booking(10, '2026-01-02', 6000, 'Max Mustermann Beitrag');
		$this->booking(11, '2026-01-05', -25000, 'Stadtwerke Beitrag');

		// 10 Tage Abstand: mit der Obergrenze 10 paart es, also wurde 99 nicht wörtlich genommen – und auch nicht verworfen.
		$high = $this->service()->reconcile(self::USER, 1, $content, null, null, 99);
		$this->assertSame([], $high['missing']);

		$this->ledger = [];
		$this->booking(10, '2026-01-02', 6000, 'Max Mustermann Beitrag');
		$this->booking(11, '2026-01-04', -25000, 'Stadtwerke Beitrag');
		$this->booking(12, '2026-01-27', -25000, 'Stadtwerke Beitrag');
		$high = $this->service()->reconcile(self::USER, 1, $content, null, null, 99);
		$this->assertCount(1, $high['missing'], '11 und 12 Tage Abstand liegen über der Obergrenze');

		$this->ledger = [];
		$this->booking(10, '2026-01-02', 6000, 'Max Mustermann Beitrag');
		$this->booking(11, '2026-01-15', -25000, 'Stadtwerke Beitrag');
		$low = $this->service()->reconcile(self::USER, 1, $content, null, null, -1);
		$this->assertSame([], $low['missing']);
		$this->assertSame([], $low['surplus']);
	}

	public function testOhneSaldenImAuszug(): void {
		$this->booking(10, '2026-01-02', 6000, 'Max Mustermann Beitrag');

		$r = $this->service()->reconcile(self::USER, 1, $this->csv([
			['02.01.2026', '60,00', ''],
			['05.01.2026', '-250,00', '', 'Stadtwerke'],
		], false), null, null);

		$this->assertNull($r['balance']);
		$this->assertCount(1, $r['missing']);
	}

	public function testLueckeImAuszugErgibtRest(): void {
		$this->booking(10, '2026-01-02', 6000, 'Max Mustermann Beitrag');
		$this->booking(11, '2026-01-05', -25000, 'Stadtwerke Beitrag');

		$r = $this->service()->reconcile(self::USER, 1, $this->csv([
			['02.01.2026', '60,00', '1.060,00'],
			['05.01.2026', '-250,00', '900,00', 'Stadtwerke'],
		]), null, null);

		$this->assertSame([], $r['surplus']);
		$this->assertSame([], $r['missing']);
		$this->assertSame(-9000, $r['balance']['diffClosingCents']);
		$this->assertSame(-9000, $r['balance']['restCents']);
		$this->assertSame([['date' => '2026-01-05', 'expectedCents' => 81000, 'actualCents' => 90000]], $r['balance']['gaps']);
	}
}
