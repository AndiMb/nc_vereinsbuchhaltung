<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\Debtor;
use OCA\Vereinsbuchhaltung\Db\DebtorMapper;
use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceDocumentMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceLine;
use OCA\Vereinsbuchhaltung\Db\InvoiceLineMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceMapper;
use OCA\Vereinsbuchhaltung\Db\InvoicePaymentMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Exception\ConflictException;
use OCA\Vereinsbuchhaltung\Exception\InvoiceNotIssuableException;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\FolderPathValidator;
use OCA\Vereinsbuchhaltung\Service\InvoiceBankAccountService;
use OCA\Vereinsbuchhaltung\Service\InvoiceService;
use OCA\Vereinsbuchhaltung\Service\InvoiceSettings;
use OCA\Vereinsbuchhaltung\Service\NumberRangeService;
use OCA\Vereinsbuchhaltung\Service\SepaDebtorAccountService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Lebenszyklus der Rechnung gegen Mapper im Speicher: Entwurf, Summen,
 * Prüfliste und Festschreiben samt offenem Posten.
 */
class InvoiceServiceTest extends TestCase {

	/** @var array<int, Invoice> */
	private array $invoiceRows = [];
	/** @var array<int, list<InvoiceLine>> */
	private array $lineRows = [];
	/** @var array<int, OpenItem> */
	private array $openItemRows = [];
	/** @var array<string, string> */
	private array $config = [];
	private bool $claimSucceeds = true;
	private int $nextNumber = 1;

	private AuditService&MockObject $audit;
	private DebtorMapper&MockObject $debtors;
	private AccountMapper&MockObject $accounts;

	protected function setUp(): void {
		$this->invoiceRows = [];
		$this->lineRows = [];
		$this->openItemRows = [];
		$this->config = [
			'club_name' => 'TSV Beispiel e. V.',
			'club_street' => 'Hauptstr. 1',
			'club_postal_code' => '12345',
			'club_city' => 'Beispielstadt',
			'club_tax_number' => '12/345/67890',
			'invoice_account_id' => '9',
		];
		$this->claimSucceeds = true;
		$this->nextNumber = 1;
	}

	private function debtor(string $name = 'Muster GmbH', bool $complete = true): Debtor {
		$d = new Debtor();
		$d->setId(5);
		$d->setName($name);
		$d->setKind(Debtor::KIND_ORGANISATION);
		$d->setCountry('DE');
		if ($complete) {
			$d->setStreet('Weg 2');
			$d->setPostalCode('54321');
			$d->setCity('Ort');
		}
		return $d;
	}

	private function service(?Debtor $debtor = null): InvoiceService {
		$debtor ??= $this->debtor();

		$invoices = $this->createMock(InvoiceMapper::class);
		$invoices->method('insert')->willReturnCallback(function (Invoice $i): Invoice {
			$i->setId(count($this->invoiceRows) + 1);
			$this->invoiceRows[(int)$i->getId()] = $i;
			return $i;
		});
		$invoices->method('update')->willReturnArgument(0);
		$invoices->method('find')->willReturnCallback(fn (int $id) => $this->invoiceRows[$id] ?? throw new DoesNotExistException(''));
		$invoices->method('delete')->willReturnCallback(function (Invoice $i): Invoice {
			unset($this->invoiceRows[(int)$i->getId()]);
			return $i;
		});
		$invoices->method('claimIssue')->willReturnCallback(fn () => $this->claimSucceeds);
		$invoices->method('findCreditFor')->willReturn(null);
		$invoices->method('findFiltered')->willReturnCallback(fn () => array_values($this->invoiceRows));

		$lines = $this->createMock(InvoiceLineMapper::class);
		$lines->method('insert')->willReturnCallback(function (InvoiceLine $l): InvoiceLine {
			$this->lineRows[$l->getInvoiceId()][] = $l;
			return $l;
		});
		$lines->method('deleteByInvoice')->willReturnCallback(function (int $id): void {
			$this->lineRows[$id] = [];
		});
		$lines->method('findByInvoice')->willReturnCallback(fn (int $id) => $this->lineRows[$id] ?? []);

		$documents = $this->createMock(InvoiceDocumentMapper::class);
		$documents->method('findByInvoice')->willReturn([]);
		$payments = $this->createMock(InvoicePaymentMapper::class);
		$payments->method('findByInvoice')->willReturn([]);

		$this->debtors = $this->createMock(DebtorMapper::class);
		$this->debtors->method('find')->willReturnCallback(fn (int $id) => $id === 5 ? $debtor : throw new DoesNotExistException(''));
		$this->debtors->method('findByIds')->willReturn([5 => $debtor]);

		$openItems = $this->createMock(OpenItemMapper::class);
		$openItems->method('insert')->willReturnCallback(function (OpenItem $o): OpenItem {
			$o->setId(count($this->openItemRows) + 100);
			$this->openItemRows[(int)$o->getId()] = $o;
			return $o;
		});
		$openItems->method('findByIds')->willReturnCallback(fn (array $ids) => array_intersect_key($this->openItemRows, array_flip($ids)));

		$this->accounts = $this->createMock(AccountMapper::class);
		$this->accounts->method('find')->willReturnCallback(function (int $id): Account {
			if ($id === 404) {
				throw new DoesNotExistException('');
			}
			$a = new Account();
			$a->setId($id);
			$a->setType($id === 77 ? 'expense' : 'income');
			$a->setIsBank(false);
			$a->setActive(true);
			$a->setSphere($id === 78 ? 'ideell' : 'zweckbetrieb');
			return $a;
		});

		$numbers = $this->createMock(NumberRangeService::class);
		$numbers->method('issue')->willReturnCallback(function (string $kind, \DateTimeInterface $date): array {
			return ['number' => sprintf('RE-%s-%04d', $date->format('Y'), $this->nextNumber++), 'rangeId' => 1];
		});

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, string $default = '') => $this->config[$key] ?? $default);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $params = []) => vsprintf($text, (array)$params));
		$settings = new InvoiceSettings($config, new FolderPathValidator($l10n), $l10n);

		$this->audit = $this->createMock(AuditService::class);
		$session = $this->createMock(IUserSession::class);

		return new InvoiceService(
			$invoices,
			$lines,
			$documents,
			$payments,
			$this->debtors,
			$openItems,
			$this->accounts,
			$numbers,
			$settings,
			new InvoiceBankAccountService($settings, new SepaDebtorAccountService($config), $this->accounts),
			new TransactionRunner($this->createMock(IDBConnection::class)),
			$this->audit,
			$session,
			$l10n,
		);
	}

	/** @return array<string, mixed> */
	private function draftData(array $override = []): array {
		return $override + [
			'debtorId' => 5,
			'issueDate' => '2026-03-05',
			'deliveryDate' => '2026-03-01',
			'lines' => [
				['name' => 'Hallenmiete', 'quantity' => 3, 'unitCode' => 'HUR', 'unitPrice' => 25.5, 'accountId' => 10],
				['name' => 'Reinigung', 'quantity' => '1', 'unitPriceCents' => 4000, 'discountPercent' => 10, 'accountId' => 11],
			],
		];
	}

	public function testEntwurfBerechnetSummenUndUeberspringtLeereZeilen(): void {
		$s = $this->service();
		$data = $this->draftData();
		$data['lines'][] = ['name' => '', 'unitPrice' => 0];

		$invoice = $s->createDraft($data);

		$this->assertSame(Invoice::STATUS_DRAFT, $invoice->getStatus());
		$this->assertNull($invoice->getNumber());
		// 3 × 25,50 = 76,50 und 40,00 abzüglich 10 % = 36,00
		$this->assertSame(11250, $invoice->getNetCents());
		$this->assertSame(0, $invoice->getTaxCents());
		$this->assertSame(11250, $invoice->getGrossCents());
		$this->assertCount(2, $this->lineRows[(int)$invoice->getId()]);
		$this->assertSame(1, $this->lineRows[(int)$invoice->getId()][0]->getPosition());
		$this->assertSame(Invoice::TAX_SMALL_BUSINESS, $invoice->getTaxMode());
	}

	public function testPositionenOhneSteuerausweisSindBefreit(): void {
		$s = $this->service();
		$invoice = $s->createDraft($this->draftData());

		foreach ($this->lineRows[(int)$invoice->getId()] as $line) {
			$this->assertSame('E', $line->getTaxCategory());
			$this->assertSame(0, $line->getTaxRateBp());
		}
	}

	public function testUpdateErsetztPositionenUndRechnetNeu(): void {
		$s = $this->service();
		$invoice = $s->createDraft($this->draftData());

		$updated = $s->updateDraft((int)$invoice->getId(), ['lines' => [['name' => 'Eine', 'unitPriceCents' => 1000, 'accountId' => 10]]]);

		$this->assertSame(1000, $updated->getGrossCents());
		$this->assertCount(1, $this->lineRows[(int)$invoice->getId()]);
	}

	/** @dataProvider ungueltigeEntwuerfe */
	public function testUngueltigeEingabenWerdenAbgelehnt(array $override): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->createDraft($this->draftData($override));
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function ungueltigeEntwuerfe(): array {
		return [
			'Datum kaputt' => [['issueDate' => '05.03.2026']],
			'Zeitraum verkehrt' => [['periodStart' => '2026-03-10', 'periodEnd' => '2026-03-01']],
			'Zahlungsziel vor Rechnungsdatum' => [['dueDate' => '2026-03-01']],
			'Empfänger unbekannt' => [['debtorId' => 99]],
			'Steuer-Modus unbekannt' => [['taxMode' => 'frei']],
			'Steuersatz zu hoch' => [['taxMode' => 'regular', 'lines' => [['name' => 'x', 'unitPriceCents' => 100, 'accountId' => 10, 'taxCategory' => 'S', 'taxRatePercent' => 30]]]],
			'Steuerkategorie unbekannt' => [['taxMode' => 'regular', 'lines' => [['name' => 'x', 'unitPriceCents' => 100, 'accountId' => 10, 'taxCategory' => 'AE']]]],
			'Menge null' => [['lines' => [['name' => 'x', 'quantity' => 0, 'unitPriceCents' => 100]]]],
			'Einheit unbekannt' => [['lines' => [['name' => 'x', 'unitCode' => 'XYZ', 'unitPriceCents' => 100]]]],
			'Rabatt über 100 %' => [['lines' => [['name' => 'x', 'discountPercent' => 120, 'unitPriceCents' => 100]]]],
			'Konto existiert nicht' => [['lines' => [['name' => 'x', 'unitPriceCents' => 100, 'accountId' => 404]]]],
			'Konto ist kein Einnahmenkonto' => [['lines' => [['name' => 'x', 'unitPriceCents' => 100, 'accountId' => 77]]]],
			'keine Zahl' => [['lines' => [['name' => 'x', 'quantity' => 'viel', 'unitPriceCents' => 100]]]],
		];
	}

	public function testFestschreibenVergibtNummerLegtOffenenPostenAnUndSchreibtSchnappschuss(): void {
		$s = $this->service();
		$invoice = $s->createDraft($this->draftData(['dueDate' => '2026-03-19']));
		$this->audit->expects($this->atLeastOnce())->method('log');

		$issued = $s->issue((int)$invoice->getId());

		$this->assertSame('RE-2026-0001', $issued->getNumber());
		$this->assertSame(Invoice::STATUS_ISSUED, $issued->getStatus());
		$this->assertSame('2026-03-05', $issued->getIssueDate());
		$this->assertNotNull($issued->getIssuedAt());
		$this->assertSame('Muster GmbH', $issued->debtorSnapshotData()['name'] ?? null);
		$this->assertSame(InvoiceSettings::SMALL_BUSINESS_NOTICE, $issued->getExemptionText());

		$item = $this->openItemRows[(int)$issued->getOpenItemId()];
		$this->assertSame(11250, $item->getAmountCents());
		$this->assertSame('RE-2026-0001', $item->getReference());
		$this->assertSame($issued->getId(), $item->getInvoiceId());
		$this->assertSame('2026-03-19', $item->getDueDate());
		$this->assertSame('open', $item->getStatus());
		$this->assertSame(0, $item->getSettledCents());
		$this->assertSame('Muster GmbH', $item->getDebtor());
		$this->assertNull($item->getAccountId(), 'Bei zwei Erlöskonten bleibt das Konto am Posten leer.');
	}

	public function testZahlungszielFolgtDemStandardWennNichtGesetzt(): void {
		$s = $this->service();
		$invoice = $s->createDraft($this->draftData());

		$issued = $s->issue((int)$invoice->getId());

		$this->assertSame('2026-03-19', $issued->getDueDate(), '14 Tage nach dem Rechnungsdatum.');
	}

	public function testAbweichendesRechnungsdatumBeimFestschreiben(): void {
		$s = $this->service();
		$invoice = $s->createDraft($this->draftData());

		$issued = $s->issue((int)$invoice->getId(), '2026-04-01');

		$this->assertSame('2026-04-01', $issued->getIssueDate());
		$this->assertSame('RE-2026-0001', $issued->getNumber());
	}

	public function testFestgeschriebeneRechnungIstUnveraenderlich(): void {
		$s = $this->service();
		$invoice = $s->createDraft($this->draftData());
		$s->issue((int)$invoice->getId());

		$this->expectException(\InvalidArgumentException::class);
		$s->updateDraft((int)$invoice->getId(), ['noteHead' => 'geändert']);
	}

	public function testFestgeschriebeneRechnungLaesstSichNichtLoeschenOderErneutFestschreiben(): void {
		$s = $this->service();
		$invoice = $s->createDraft($this->draftData());
		$s->issue((int)$invoice->getId());

		try {
			$s->deleteDraft((int)$invoice->getId());
			$this->fail('Löschen muss scheitern.');
		} catch (\InvalidArgumentException) {
		}
		$this->expectException(\InvalidArgumentException::class);
		$s->issue((int)$invoice->getId());
	}

	public function testFehlendeAusstellerangabenVerhindernDasFestschreiben(): void {
		$this->service();
		unset($this->config['club_tax_number'], $this->config['club_street']);
		$s = $this->service();
		$invoice = $s->createDraft($this->draftData());

		try {
			$s->issue((int)$invoice->getId());
			$this->fail('Festschreiben muss scheitern.');
		} catch (InvoiceNotIssuableException $e) {
			$this->assertContains('Name und Anschrift des Vereins', $e->getProblems());
			$this->assertContains('Steuernummer oder USt-IdNr. des Vereins', $e->getProblems());
		}
		$this->assertSame(1, $this->nextNumber, 'Keine Nummer verbraucht.');
		$this->assertSame([], $this->openItemRows);
	}

	public function testUnvollstaendigerEmpfaengerVerhindertDasFestschreiben(): void {
		$s = $this->service($this->debtor('Muster GmbH', false));
		$invoice = $s->createDraft($this->draftData());

		$this->expectException(InvoiceNotIssuableException::class);
		$s->issue((int)$invoice->getId());
	}

	public function testPositionOhneErloeskontoVerhindertDasFestschreiben(): void {
		$s = $this->service();
		$data = $this->draftData();
		$data['lines'][0]['accountId'] = null;
		$invoice = $s->createDraft($data);

		try {
			$s->issue((int)$invoice->getId());
			$this->fail('Festschreiben muss scheitern.');
		} catch (InvoiceNotIssuableException $e) {
			$this->assertContains('Mindestens eine Position mit Bezeichnung, Menge und Erlöskonto', $e->getProblems());
		}
	}

	public function testOhneLeistungsdatumKeinFestschreiben(): void {
		$s = $this->service();
		$invoice = $s->createDraft($this->draftData(['deliveryDate' => null]));

		try {
			$s->issue((int)$invoice->getId());
			$this->fail('Festschreiben muss scheitern.');
		} catch (InvoiceNotIssuableException $e) {
			$this->assertContains('Leistungsdatum oder Leistungszeitraum', $e->getProblems());
		}
	}

	public function testLeistungszeitraumGenuegtAlsLeistungszeitpunkt(): void {
		$s = $this->service();
		$invoice = $s->createDraft($this->draftData(['deliveryDate' => null, 'periodStart' => '2026-02-01', 'periodEnd' => '2026-02-28']));

		$this->assertSame(Invoice::STATUS_ISSUED, $s->issue((int)$invoice->getId())->getStatus());
	}

	public function testBetragNullVerhindertDasFestschreiben(): void {
		$s = $this->service();
		$invoice = $s->createDraft($this->draftData(['lines' => [['name' => 'Gratis', 'unitPriceCents' => 0, 'accountId' => 10]]]));

		try {
			$s->issue((int)$invoice->getId());
			$this->fail('Festschreiben muss scheitern.');
		} catch (InvoiceNotIssuableException $e) {
			$this->assertContains('Rechnungsbetrag muss größer als 0 € sein', $e->getProblems());
		}
	}

	public function testBefreiungsmodusBrauchtDenHinweistext(): void {
		$s = $this->service();
		$invoice = $s->createDraft($this->draftData(['taxMode' => 'exempt']));

		try {
			$s->issue((int)$invoice->getId());
			$this->fail('Festschreiben muss scheitern.');
		} catch (InvoiceNotIssuableException $e) {
			$this->assertSame(['Hinweis auf die Steuerbefreiung (z. B. § 4 Nr. 22 UStG)'], $e->getProblems());
		}

		$s->updateDraft((int)$invoice->getId(), ['exemptionText' => 'Steuerfrei nach § 4 Nr. 22 UStG']);
		$this->assertSame('Steuerfrei nach § 4 Nr. 22 UStG', $s->issue((int)$invoice->getId())->getExemptionText());
	}

	public function testGleichzeitigesFestschreibenBrichtOhneNummerAb(): void {
		$s = $this->service();
		$invoice = $s->createDraft($this->draftData());
		$this->claimSucceeds = false;

		try {
			$s->issue((int)$invoice->getId());
			$this->fail('Der zweite Aufruf muss scheitern.');
		} catch (ConflictException) {
		}
		$this->assertSame(1, $this->nextNumber);
		$this->assertSame([], $this->openItemRows);
	}

	public function testDuplizierenErzeugtEntwurfOhneNummerUndDatum(): void {
		$s = $this->service();
		$invoice = $s->createDraft($this->draftData());
		$s->issue((int)$invoice->getId());

		$copy = $s->duplicate((int)$invoice->getId());

		$this->assertNotSame($invoice->getId(), $copy->getId());
		$this->assertSame(Invoice::STATUS_DRAFT, $copy->getStatus());
		$this->assertNull($copy->getNumber());
		$this->assertNull($copy->getIssueDate());
		$this->assertNull($copy->getOpenItemId());
		$this->assertSame($invoice->getGrossCents(), $copy->getGrossCents());
		$this->assertCount(2, $this->lineRows[(int)$copy->getId()]);
		$this->assertSame(5, $copy->getDebtorId());
	}

	public function testVersandSetztStatusUndZeitpunkt(): void {
		$s = $this->service();
		$invoice = $s->createDraft($this->draftData());
		$s->issue((int)$invoice->getId());

		$sent = $s->markSent((int)$invoice->getId());

		$this->assertSame(Invoice::STATUS_SENT, $sent->getStatus());
		$this->assertNotNull($sent->getSentAt());
	}

	public function testEntwurfLaesstSichNichtVersenden(): void {
		$s = $this->service();
		$invoice = $s->createDraft($this->draftData());

		$this->expectException(\InvalidArgumentException::class);
		$s->markSent((int)$invoice->getId());
	}

	public function testListeZeigtEmpfaengerUndOffenenRestbetrag(): void {
		$s = $this->service();
		$invoice = $s->createDraft($this->draftData(['dueDate' => '2020-01-01', 'issueDate' => '2019-12-01']));
		$s->issue((int)$invoice->getId());
		$item = $this->openItemRows[(int)$this->invoiceRows[(int)$invoice->getId()]->getOpenItemId()];
		$item->setSettledCents(1000);

		$rows = $s->list();

		$this->assertCount(1, $rows);
		$this->assertSame('Muster GmbH', $rows[0]['debtorName']);
		$this->assertSame(1000, $rows[0]['settledCents']);
		$this->assertSame(10250, $rows[0]['remainingCents']);
		$this->assertTrue($rows[0]['overdue']);
	}

	public function testDetailEnthaeltPruflisteNurFuerEntwuerfe(): void {
		$s = $this->service();
		$invoice = $s->createDraft($this->draftData());

		$detail = $s->detail((int)$invoice->getId());
		$this->assertArrayHasKey('checklist', $detail);
		$this->assertCount(2, $detail['lines']);
		$keys = array_column($detail['checklist'], 'key');
		$this->assertContains('bank', $keys);

		$s->issue((int)$invoice->getId());
		$this->assertArrayNotHasKey('checklist', $s->detail((int)$invoice->getId()));
	}

	public function testPositionAufEinemSpendenkontoIstNurEinHinweis(): void {
		$s = $this->service();
		$invoice = $s->createDraft($this->draftData(['lines' => [
			['name' => 'Spende', 'quantity' => 1, 'unitPrice' => 50, 'accountId' => 78],
		]]));

		$sphere = $this->checklistItem($s, (int)$invoice->getId(), 'sphere');

		$this->assertFalse($sphere['ok']);
		$this->assertFalse($sphere['required']);
		$this->assertSame(Invoice::STATUS_ISSUED, $s->issue((int)$invoice->getId())->getStatus());
	}

	public function testPositionenAufErloeskontenAnderenSphaerenSindOhneHinweis(): void {
		$s = $this->service();
		$invoice = $s->createDraft($this->draftData());

		$this->assertTrue($this->checklistItem($s, (int)$invoice->getId(), 'sphere')['ok']);
	}

	public function testEingetragenerVereinOhneZusatzEVBekommtEinenHinweis(): void {
		$this->config['club_register_no'] = 'VR 1234';
		$this->config['club_name'] = 'TSV Beispiel';
		$s = $this->service();
		$invoice = $s->createDraft($this->draftData());

		$this->assertFalse($this->checklistItem($s, (int)$invoice->getId(), 'clubSuffix')['ok']);

		$this->config['club_name'] = 'TSV Beispiel e.V.';
		$this->assertTrue($this->checklistItem($this->service(), (int)$invoice->getId(), 'clubSuffix')['ok']);
	}

	public function testOhneRegisternummerGibtEsKeinenZusatzHinweis(): void {
		$s = $this->service();
		$invoice = $s->createDraft($this->draftData());

		$this->assertNull($this->checklistItem($s, (int)$invoice->getId(), 'clubSuffix'));
	}

	/** @return array{key: string, label: string, ok: bool, required: bool}|null */
	private function checklistItem(InvoiceService $s, int $id, string $key): ?array {
		foreach ($s->detail($id)['checklist'] as $item) {
			if ($item['key'] === $key) {
				return $item;
			}
		}
		return null;
	}

	public function testFehlendeBankverbindungIstNurEineEmpfehlung(): void {
		unset($this->config['invoice_account_id']);
		$s = $this->service();
		$invoice = $s->createDraft($this->draftData());

		$this->assertSame(Invoice::STATUS_ISSUED, $s->issue((int)$invoice->getId())->getStatus());
	}

	public function testStornoErzeugtNegativeStornorechnungUndStorniertDasOriginal(): void {
		$s = $this->service();
		$original = $s->issue((int)$s->createDraft($this->draftData())->getId());
		$originalItem = $this->openItemRows[(int)$original->getOpenItemId()];

		$credit = $s->cancel((int)$original->getId(), 'Falscher Empfänger');

		$this->assertTrue($credit->isCredit());
		$this->assertSame(Invoice::STATUS_ISSUED, $credit->getStatus());
		$this->assertSame($original->getId(), $credit->getPrecedingInvoiceId());
		$this->assertSame(-11250, $credit->getGrossCents());
		$this->assertNull($credit->getOpenItemId(), 'Die Stornorechnung hat keinen offenen Posten.');
		$this->assertSame($original->getDebtorSnapshot(), $credit->getDebtorSnapshot());
		$this->assertSame($credit->getIssueDate(), $credit->getDueDate());
		foreach ($this->lineRows[(int)$credit->getId()] as $line) {
			$this->assertLessThan(0, $line->getQuantityE4());
			$this->assertLessThan(0, $line->getLineNetCents());
		}
		$this->assertSame(Invoice::STATUS_CANCELLED, $original->getStatus());
		$this->assertSame('Falscher Empfänger', $original->getCancelReason());
		$this->assertNotNull($original->getCancelledAt());
		$this->assertSame('cancelled', $originalItem->getStatus());
		$this->assertSame('Falscher Empfänger', $originalItem->getCancelledReason());
	}

	public function testStornoBrauchtEineBegruendung(): void {
		$s = $this->service();
		$original = $s->issue((int)$s->createDraft($this->draftData())->getId());

		$this->expectException(\InvalidArgumentException::class);
		$s->cancel((int)$original->getId(), ' ');
	}

	public function testEntwurfLaesstSichNichtStornieren(): void {
		$s = $this->service();
		$draft = $s->createDraft($this->draftData());

		$this->expectException(\InvalidArgumentException::class);
		$s->cancel((int)$draft->getId(), 'Grund');
	}

	public function testStornorechnungLaesstSichNichtStornieren(): void {
		$s = $this->service();
		$original = $s->issue((int)$s->createDraft($this->draftData())->getId());
		$credit = $s->cancel((int)$original->getId(), 'Grund');

		$this->expectException(\InvalidArgumentException::class);
		$s->cancel((int)$credit->getId(), 'Nochmal');
	}

	public function testZweitesStornoDerselbenRechnungWirdAbgelehnt(): void {
		$s = $this->service();
		$original = $s->issue((int)$s->createDraft($this->draftData())->getId());
		$s->cancel((int)$original->getId(), 'Grund');

		$this->expectException(\InvalidArgumentException::class);
		$s->cancel((int)$original->getId(), 'Nochmal');
	}

	public function testDetailDerStornorechnungZeigtDieNochZurueckzuzahlendeSumme(): void {
		$s = $this->service();
		$original = $s->issue((int)$s->createDraft($this->draftData())->getId());
		$this->openItemRows[(int)$original->getOpenItemId()]->setSettledCents(4000);
		$credit = $s->cancel((int)$original->getId(), 'Grund');

		$detail = $s->detail((int)$credit->getId());

		$this->assertSame(4000, $detail['refundDueCents']);
		$this->assertSame($original->getNumber(), $detail['precedingNumber']);
	}

	public function testStornoVerbrauchtEineNummerAusDemStornokreis(): void {
		$s = $this->service();
		$original = $s->issue((int)$s->createDraft($this->draftData())->getId());

		$credit = $s->cancel((int)$original->getId(), 'Grund');

		$this->assertSame('RE-2026-0002', $credit->getNumber(), 'Im Test liefert der Nummernkreis-Dienst eine laufende Nummer.');
	}

	private function regularDraft(InvoiceService $s, ?array $lines = null): Invoice {
		return $s->createDraft($this->draftData([
			'taxMode' => 'regular',
			'lines' => $lines ?? [
				['name' => 'Kursgebuehr', 'quantity' => 1, 'unitCode' => 'LS', 'unitPriceCents' => 10000, 'accountId' => 10, 'taxCategory' => 'S', 'taxRatePercent' => 19],
				['name' => 'Eintritt', 'quantity' => 2, 'unitCode' => 'C62', 'unitPriceCents' => 2500, 'accountId' => 11, 'taxCategory' => 'S', 'taxRateBp' => 700],
				['name' => 'Raumnutzung', 'quantity' => 1, 'unitCode' => 'LS', 'unitPriceCents' => 3000, 'accountId' => 11, 'taxCategory' => 'E'],
			],
		]));
	}

	public function testRegelbesteuerungRechnetSteuerJeSatz(): void {
		$s = $this->service();

		$invoice = $this->regularDraft($s);

		// Netto 100 + 50 + 30 = 180; USt 19 % auf 100 = 19,00 und 7 % auf 50 = 3,50.
		$this->assertSame(18000, $invoice->getNetCents());
		$this->assertSame(2250, $invoice->getTaxCents());
		$this->assertSame(20250, $invoice->getGrossCents());
		$lines = $this->lineRows[(int)$invoice->getId()];
		$this->assertSame([['S', 1900], ['S', 700], ['E', 0]], array_map(static fn ($l) => [$l->getTaxCategory(), $l->getTaxRateBp()], $lines));
		$breakdown = $s->detail((int)$invoice->getId())['taxBreakdown'];
		$this->assertEqualsCanonicalizing([1900, 700, 0], array_column($breakdown, 'rateBp'));
	}

	public function testRegelbesteuerungBrauchtFuerJedenSatzEinUmsatzsteuerkonto(): void {
		$s = $this->service();
		$invoice = $this->regularDraft($s);

		try {
			$s->issue((int)$invoice->getId());
			$this->fail('Ohne Umsatzsteuerkonten lässt sich nicht festschreiben.');
		} catch (InvoiceNotIssuableException $e) {
			$this->assertContains('Umsatzsteuerkonto für jeden verwendeten Steuersatz (Einstellungen)', $e->getProblems());
		}

		$this->config['invoice_vat_account_19'] = '31';
		$this->config['invoice_vat_account_7'] = '32';
		$this->config['invoice_default_exemption_text'] = 'Steuerfrei nach § 4 Nr. 12 UStG';
		$s2 = $this->service();
		$invoice = $this->regularDraft($s2);
		$issued = $s2->issue((int)$invoice->getId());

		$this->assertSame(20250, $this->openItemRows[(int)$issued->getOpenItemId()]->getAmountCents(), 'Der offene Posten trägt den Bruttobetrag.');
		$this->assertSame('Steuerfrei nach § 4 Nr. 12 UStG', $issued->getExemptionText());
	}

	public function testRegelbesteuerungMitBefreitenPositionenBrauchtDenHinweis(): void {
		$this->config['invoice_vat_account_19'] = '31';
		$s = $this->service();
		$invoice = $this->regularDraft($s, [['name' => 'Raum', 'unitPriceCents' => 1000, 'accountId' => 10, 'taxCategory' => 'E']]);

		try {
			$s->issue((int)$invoice->getId());
			$this->fail('Ohne Befreiungshinweis lässt sich nicht festschreiben.');
		} catch (InvoiceNotIssuableException $e) {
			$this->assertContains('Hinweis auf die Steuerbefreiung (z. B. § 4 Nr. 22 UStG)', $e->getProblems());
		}
	}

	public function testStandardsatzEinerPositionOhneAngabeIstDerWirtschaftlicheSatz(): void {
		$s = $this->service();

		$invoice = $this->regularDraft($s, [['name' => 'Ohne Satz', 'unitPriceCents' => 10000, 'accountId' => 10]]);

		$this->assertSame(1900, $this->lineRows[(int)$invoice->getId()][0]->getTaxRateBp());
		$this->assertSame(1900, $invoice->getTaxCents());
	}

	public function testStornoEinerRechnungMitSteuerKehrtAuchDieSteuerUm(): void {
		$this->config['invoice_vat_account_19'] = '31';
		$s = $this->service();
		$invoice = $s->issue((int)$this->regularDraft($s, [['name' => 'Kurs', 'unitPriceCents' => 10000, 'accountId' => 10, 'taxCategory' => 'S', 'taxRatePercent' => 19]])->getId());

		$credit = $s->cancel((int)$invoice->getId(), 'Irrtum');

		$this->assertSame(-10000, $credit->getNetCents());
		$this->assertSame(-1900, $credit->getTaxCents());
		$this->assertSame(-11900, $credit->getGrossCents());
	}

	public function testDuplizierenBehaeltDieSteuerDerPositionen(): void {
		$this->config['invoice_vat_account_19'] = '31';
		$this->config['invoice_vat_account_7'] = '32';
		$this->config['invoice_default_exemption_text'] = 'Steuerfrei nach § 4 Nr. 12 UStG';
		$s = $this->service();
		$invoice = $s->issue((int)$this->regularDraft($s)->getId());

		$copy = $s->duplicate((int)$invoice->getId());

		$this->assertSame('regular', $copy->getTaxMode());
		$this->assertSame($invoice->getTaxCents(), $copy->getTaxCents());
	}
}
