<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Service\InvoiceMatchingService;
use PHPUnit\Framework\TestCase;

/**
 * Zuordnung von Zahlungseingängen zu offenen Rechnungen: Nummer im
 * Verwendungszweck, Betrag mit Name, Betrag allein.
 */
class InvoiceMatchingServiceTest extends TestCase {

	/** @var list<OpenItem> */
	private array $items = [];
	/** @var array<int, Invoice> */
	private array $invoices = [];

	protected function setUp(): void {
		$this->items = [];
		$this->invoices = [];
	}

	private function addInvoice(int $id, string $number, int $amount, string $debtor, int $settled = 0, string $status = Invoice::STATUS_SENT): void {
		$invoice = new Invoice();
		$invoice->setId($id);
		$invoice->setNumber($number);
		$invoice->setKind(Invoice::KIND_INVOICE);
		$invoice->setStatus($status);
		$invoice->setDebtorSnapshot(json_encode(['name' => $debtor]));
		$this->invoices[$id] = $invoice;
		$item = new OpenItem();
		$item->setId($id + 100);
		$item->setInvoiceId($id);
		$item->setReference($number);
		$item->setDebtor($debtor);
		$item->setAmountCents($amount);
		$item->setSettledCents($settled);
		$item->setStatus('open');
		$this->items[] = $item;
	}

	private function service(): InvoiceMatchingService {
		$openItems = $this->createMock(OpenItemMapper::class);
		$openItems->method('findOpenInvoiceItems')->willReturnCallback(fn () => $this->items);
		$invoices = $this->createMock(InvoiceMapper::class);
		$invoices->method('findByIds')->willReturnCallback(fn () => $this->invoices);
		return new InvoiceMatchingService($openItems, $invoices);
	}

	private function tx(int $id, int $amount, string $purpose, string $counterparty = 'Unbekannt'): BankTransaction {
		$tx = new BankTransaction();
		$tx->setId($id);
		$tx->setAmountCents($amount);
		$tx->setPurpose($purpose);
		$tx->setCounterparty($counterparty);
		return $tx;
	}

	/** @dataProvider schreibweisen */
	public function testRechnungsnummerWirdTrotzAbweichenderSchreibweiseErkannt(string $purpose): void {
		$this->addInvoice(1, 'RE-2026-0042', 15000, 'Muster GmbH');

		$result = $this->service()->suggestForMany([$this->tx(1, 15000, $purpose)]);

		$this->assertSame(1, $result[1][0]['invoiceId']);
		$this->assertSame(InvoiceMatchingService::REASON_NUMBER, $result[1][0]['reason']);
		$this->assertTrue($result[1][0]['exact']);
	}

	/** @return array<string, array{string}> */
	public static function schreibweisen(): array {
		return [
			'wie gedruckt' => ['Rechnung RE-2026-0042 vom 05.03.'],
			'mit Leerzeichen' => ['RE 2026 0042'],
			'zusammengeschrieben' => ['RE20260042 Hallenmiete'],
			'Kleinschreibung' => ['re-2026-0042'],
			'am Ende' => ['Danke fuer RE-2026-0042'],
		];
	}

	public function testNummer42IstNichtNummer420(): void {
		$this->addInvoice(1, 'RE-2026-0042', 15000, 'Muster');

		$result = $this->service()->suggestForMany([$this->tx(1, 15000, 'RE-2026-00420')]);

		// Nur der Betrag passt: ein schwacher Vorschlag, aber kein Treffer über die Nummer.
		$this->assertSame(InvoiceMatchingService::REASON_AMOUNT, $result[1][0]['reason']);
		$this->assertNull($this->service()->autoMatch($this->tx(1, 15000, 'RE-2026-00420')));
	}

	public function testNummerImVerwendungszweckGewinntGegenBetragsTreffer(): void {
		$this->addInvoice(1, 'RE-2026-0001', 5000, 'Anna');
		$this->addInvoice(2, 'RE-2026-0002', 5000, 'Berta');

		$result = $this->service()->suggestForMany([$this->tx(1, 5000, 'RE-2026-0002')]);

		$this->assertCount(1, $result[1]);
		$this->assertSame(2, $result[1][0]['invoiceId']);
	}

	public function testBetragUndNamePassen(): void {
		$this->addInvoice(1, 'RE-2026-0001', 5000, 'Anna Müller');
		$this->addInvoice(2, 'RE-2026-0002', 5000, 'Berta Schmidt');

		$result = $this->service()->suggestForMany([$this->tx(1, 5000, 'Mitgliedsfest', 'MUELLER, ANNA')]);

		$this->assertSame(1, $result[1][0]['invoiceId']);
		$this->assertSame(InvoiceMatchingService::REASON_AMOUNT_NAME, $result[1][0]['reason']);
	}

	public function testNurDerBetragGenuegtBeiGenauEinerRechnung(): void {
		$this->addInvoice(1, 'RE-2026-0001', 4711, 'Anna');
		$this->addInvoice(2, 'RE-2026-0002', 5000, 'Berta');

		$result = $this->service()->suggestForMany([$this->tx(1, 4711, 'Ohne Bezug', 'Jemand Anderes')]);

		$this->assertSame(InvoiceMatchingService::REASON_AMOUNT, $result[1][0]['reason']);
		$this->assertSame(1, $result[1][0]['invoiceId']);
	}

	public function testMehrdeutigerBetragErzeugtKeinenVorschlag(): void {
		$this->addInvoice(1, 'RE-2026-0001', 5000, 'Anna');
		$this->addInvoice(2, 'RE-2026-0002', 5000, 'Berta');

		$this->assertSame([], $this->service()->suggestForMany([$this->tx(1, 5000, 'Ohne Bezug', 'Jemand')]));
	}

	public function testTeilzahlungMitNummerWirdVorgeschlagenAberNichtAutomatischGebucht(): void {
		$this->addInvoice(1, 'RE-2026-0001', 10000, 'Anna');
		$tx = $this->tx(1, 4000, 'RE-2026-0001');

		$suggestion = $this->service()->suggestForMany([$tx])[1][0];

		$this->assertFalse($suggestion['exact']);
		$this->assertFalse($suggestion['overpay']);
		$this->assertNull($this->service()->autoMatch($tx));
	}

	public function testRestzahlungNachTeilzahlungIstExakt(): void {
		$this->addInvoice(1, 'RE-2026-0001', 10000, 'Anna', 4000);

		$suggestion = $this->service()->suggestForMany([$this->tx(1, 6000, 'RE-2026-0001')])[1][0];

		$this->assertTrue($suggestion['exact']);
		$this->assertSame(6000, $suggestion['remainingCents']);
	}

	public function testUeberzahlungWirdMarkiert(): void {
		$this->addInvoice(1, 'RE-2026-0001', 10000, 'Anna');

		$suggestion = $this->service()->suggestForMany([$this->tx(1, 12000, 'RE-2026-0001')])[1][0];

		$this->assertTrue($suggestion['overpay']);
		$this->assertFalse($suggestion['exact']);
	}

	public function testAusgaengeWerdenIgnoriert(): void {
		$this->addInvoice(1, 'RE-2026-0001', 10000, 'Anna');

		$this->assertSame([], $this->service()->suggestForMany([$this->tx(1, -10000, 'RE-2026-0001')]));
		$this->assertNull($this->service()->autoMatch($this->tx(1, -10000, 'RE-2026-0001')));
	}

	public function testAutomatischNurBeiNummerUndGenauemBetrag(): void {
		$this->addInvoice(1, 'RE-2026-0001', 10000, 'Anna');

		$this->assertSame(['invoiceId' => 1, 'number' => 'RE-2026-0001'], $this->service()->autoMatch($this->tx(1, 10000, 'RE-2026-0001')));
		$this->assertNull($this->service()->autoMatch($this->tx(2, 10000, 'Ohne Nummer', 'Anna')), 'Betrag und Name allein buchen nicht von selbst.');
	}

	public function testZweiNummernImTextBuchenNichtAutomatisch(): void {
		$this->addInvoice(1, 'RE-2026-0001', 10000, 'Anna');
		$this->addInvoice(2, 'RE-2026-0002', 10000, 'Anna');

		$this->assertNull($this->service()->autoMatch($this->tx(1, 10000, 'RE-2026-0001 und RE-2026-0002')));
	}

	public function testBezahlteStornierteUndEntwurfsrechnungenWerdenIgnoriert(): void {
		$this->addInvoice(1, 'RE-2026-0001', 10000, 'Anna', 0, Invoice::STATUS_CANCELLED);
		$this->addInvoice(2, 'RE-2026-0002', 10000, 'Berta', 10000);

		$this->assertSame([], $this->service()->suggestForMany([$this->tx(1, 10000, 'RE-2026-0001 RE-2026-0002')]));
	}

	/** @dataProvider namen */
	public function testNamensvergleich(string $a, string $b, bool $expected): void {
		$this->assertSame($expected, InvoiceMatchingService::namesMatch($a, $b));
	}

	/** @return array<string, array{string, string, bool}> */
	public static function namen(): array {
		return [
			'Umlaute und Reihenfolge' => ['Müller, Hans', 'Hans Mueller', true],
			'Rechtsform egal' => ['Muster GmbH', 'Muster', true],
			'Teilname genügt' => ['TSV Beispiel e.V.', 'TSV Beispiel Abteilung Fußball', true],
			'anderer Name' => ['Anna Meier', 'Anna Müller', false],
			'leer' => ['', 'Muster', false],
		];
	}
}
