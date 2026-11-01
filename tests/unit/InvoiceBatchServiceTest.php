<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\CostCenterMapper;
use OCA\Vereinsbuchhaltung\Db\Debtor;
use OCA\Vereinsbuchhaltung\Db\DebtorMapper;
use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceBatch;
use OCA\Vereinsbuchhaltung\Db\InvoiceBatchMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceItemTemplateMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\DebtorService;
use OCA\Vereinsbuchhaltung\Service\InvoiceBatch\InvoiceBatchService;
use OCA\Vereinsbuchhaltung\Service\InvoiceService;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Sammelrechnungen (#243): Übernahme in einen Stapel, Empfänger anlegen oder
 * wiederverwenden, fehlerhafte Rechnungen überspringen, Stapel löschen.
 */
class InvoiceBatchServiceTest extends TestCase {

	private InvoiceBatchMapper&MockObject $batches;
	private InvoiceMapper&MockObject $invoices;
	private DebtorMapper&MockObject $debtors;
	private DebtorService&MockObject $debtorService;
	private InvoiceService&MockObject $invoiceService;
	/** @var list<array<string, mixed>> */
	private array $drafts = [];
	/** @var list<array<string, mixed>> */
	private array $createdDebtors = [];
	/** @var list<Debtor> */
	private array $existing = [];

	protected function setUp(): void {
		$this->drafts = [];
		$this->createdDebtors = [];
		$this->existing = [];
		$this->batches = $this->createMock(InvoiceBatchMapper::class);
		$this->batches->method('insert')->willReturnCallback(function (InvoiceBatch $b): InvoiceBatch {
			$b->setId(5);
			return $b;
		});
		$this->invoices = $this->createMock(InvoiceMapper::class);
		$this->debtors = $this->createMock(DebtorMapper::class);
		$this->debtors->method('findActive')->willReturnCallback(fn (): array => $this->existing);
		$this->debtorService = $this->createMock(DebtorService::class);
		$this->debtorService->method('create')->willReturnCallback(function (array $data): Debtor {
			$this->createdDebtors[] = $data;
			$d = new Debtor();
			$d->setId(100 + count($this->createdDebtors));
			return $d;
		});
		$this->invoiceService = $this->createMock(InvoiceService::class);
		$this->invoiceService->method('createDraft')->willReturnCallback(function (array $data): Invoice {
			$this->drafts[] = $data;
			$i = new Invoice();
			$i->setId(200 + count($this->drafts));
			return $i;
		});
	}

	private function service(): InvoiceBatchService {
		$templates = $this->createMock(InvoiceItemTemplateMapper::class);
		$templates->method('findAll')->willReturn([]);
		$accounts = $this->createMock(AccountMapper::class);
		$account = new Account();
		$account->setId(40);
		$account->setNumber('8200');
		$accounts->method('findAll')->willReturn([$account]);
		$costCenters = $this->createMock(CostCenterMapper::class);
		$costCenters->method('findAll')->willReturn([]);
		$transaction = $this->createMock(TransactionRunner::class);
		$transaction->method('run')->willReturnCallback(fn (callable $fn) => $fn());
		return new InvoiceBatchService(
			$this->batches,
			$this->invoices,
			$this->debtors,
			$this->debtorService,
			$this->invoiceService,
			$templates,
			$accounts,
			$costCenters,
			$transaction,
			$this->createMock(AuditService::class),
			$this->createMock(IUserSession::class),
		);
	}

	private const CSV = "Rechnung;Name;Straße;PLZ;Ort;E-Mail;Position;Einzelpreis;Erlöskonto;Leistungsdatum\n"
		. "1;Anna Beispiel;Hauptstr. 1;12345;Musterstadt;anna@example.org;Teilnahme;120;8200;05.03.2026\n"
		. ";;;;;;Übernachtung;35;8200;06.03.2026\n"
		. "2;Anna Beispiel;Hauptstr. 1;12345;Musterstadt;anna@example.org;Noten;12;8200;05.03.2026\n"
		. "3;Bernd Muster;Weg 2;54321;Andersdorf;;Teilnahme;zwölf;8200;05.03.2026\n"
		. "4;Carla Alt;;99999;Altdorf;carla@example.org;Teilnahme;80;;\n";

	public function testUebernahmeLegtStapelEmpfaengerUndEntwuerfeAn(): void {
		$carla = new Debtor();
		$carla->setId(7);
		$carla->setName('Carla Alt');
		$carla->setPostalCode('99999');
		$carla->setStreet('Alter Weg 3');
		$this->existing = [$carla];
		// Leere Felder des vorhandenen Empfängers werden ergänzt, die Straße bleibt.
		$this->debtorService->expects($this->once())->method('update')->with(7, ['city' => 'Altdorf', 'email' => 'carla@example.org']);

		$result = $this->service()->import(self::CSV, 'projekt.csv', ' Probenwochenende ', ['accountId' => 40, 'noteHead' => 'Hallo']);

		$this->assertSame('Probenwochenende', $result['batch']['name']);
		$this->assertSame('projekt.csv', $result['batch']['sourceFilename']);
		$this->assertSame(3, $result['batch']['invoiceCount']);
		// Anna steht in zwei Rechnungen, wird aber nur einmal angelegt; Carla gibt es schon.
		$this->assertCount(1, $this->createdDebtors);
		$this->assertSame('Anna Beispiel', $this->createdDebtors[0]['name']);
		$this->assertCount(3, $this->drafts);
		[$first, $second, $third] = $this->drafts;
		$this->assertSame(5, $first['batchId']);
		$this->assertSame(101, $first['debtorId']);
		$this->assertSame(101, $second['debtorId']);
		$this->assertSame(7, $third['debtorId']);
		$this->assertSame(['periodStart' => '2026-03-05', 'periodEnd' => '2026-03-06'], array_intersect_key($first, ['periodStart' => 1, 'periodEnd' => 1, 'deliveryDate' => 1]));
		$this->assertSame('2026-03-05', $second['deliveryDate']);
		$this->assertSame('Hallo', $first['noteHead']);
		$this->assertArrayNotHasKey('noteFoot', $first);
		// Carlas Position ohne Konto bekommt die Vorgabe aus dem Dialog.
		$this->assertSame(40, $third['lines'][0]['accountId']);
		$this->assertSame(1, $result['summary']['failed']);
		$this->assertSame(201, $result['groups'][0]['invoiceId']);
		$this->assertNull($result['groups'][2]['invoiceId']);
	}

	public function testVorschauLegtNichtsAnUndNenntHinweise(): void {
		$this->batches->expects($this->never())->method('insert');

		$result = $this->service()->preview(self::CSV, []);

		$this->assertSame(['invoices' => 3, 'failed' => 1, 'newDebtors' => 2, 'existingDebtors' => 0, 'withoutEmail' => 0, 'totalCents' => 12000 + 3500 + 1200 + 8000], $result['summary']);
		$this->assertSame([], $this->drafts);
		$carla = $result['groups'][3];
		$this->assertSame('new', $carla['debtorStatus']);
		// Ohne Straße, ohne Konto, ohne Leistungsdatum: drei Hinweise, kein Fehler.
		$this->assertSame([], $carla['errors']);
		$this->assertCount(3, $carla['warnings']);
	}

	public function testOhneFehlerfreieRechnungWirdNichtsAngelegt(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->import("Name;Position;Preis\nAnna;X;abc\n", null, 'Test', []);
	}

	public function testOhneNamenWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->import(self::CSV, null, '  ', []);
	}

	public function testHalberLeistungszeitraumWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->preview(self::CSV, ['periodStart' => '2026-03-01']);
	}

	public function testLoeschenNurSolangeAllesEntwuerfeSind(): void {
		$batch = new InvoiceBatch();
		$batch->setId(5);
		$batch->setName('X');
		$this->batches->method('find')->willReturn($batch);
		$draft = new Invoice();
		$draft->setId(1);
		$draft->setStatus(Invoice::STATUS_DRAFT);
		$issued = new Invoice();
		$issued->setId(2);
		$issued->setStatus(Invoice::STATUS_ISSUED);

		$this->invoices->method('findByBatch')->willReturnOnConsecutiveCalls([$draft, $issued], [$draft]);
		try {
			$this->service()->delete(5);
			$this->fail('Stapel mit festgeschriebener Rechnung wurde gelöscht.');
		} catch (\InvalidArgumentException) {
		}

		$this->invoiceService->expects($this->once())->method('deleteDraft')->with(1);
		$this->batches->expects($this->once())->method('delete')->with($batch);
		$this->service()->delete(5);
	}
}
