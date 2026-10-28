<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceDocument;
use OCA\Vereinsbuchhaltung\Db\InvoiceDunning;
use OCA\Vereinsbuchhaltung\Db\InvoiceDunningMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\ClubMailBranding;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceDocumentService;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceDunningService;
use OCA\Vereinsbuchhaltung\Service\InvoiceSettings;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\Mail\IAttachment;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Mahnwesen für Rechnungen: Stand je Rechnung, Sperren (bezahlt, storniert, nicht
 * fällig), Versand mit PDF und der tägliche Lauf.
 */
class InvoiceDunningServiceTest extends TestCase {

	private const TODAY = '2026-04-01';

	private Invoice $invoice;
	private int $remaining = 15000;
	/** @var list<InvoiceDunning> */
	private array $history = [];
	private bool $auto = true;
	private ?string $boardEmail = null;
	private bool $copyBoardSetting = false;

	private InvoiceMapper&MockObject $invoices;
	private InvoiceDunningMapper&MockObject $dunnings;
	private IMailer&MockObject $mailer;
	private InvoiceDocumentService&MockObject $documents;

	protected function setUp(): void {
		$this->history = [];
		$this->remaining = 15000;
		$this->auto = true;
		$this->boardEmail = null;
		$this->copyBoardSetting = false;
		$this->invoice = $this->makeInvoice(1, '2026-03-19');
	}

	private function makeInvoice(int $id, string $due, string $status = Invoice::STATUS_SENT, string $email = 'zahler@example.org'): Invoice {
		$invoice = new Invoice();
		$invoice->setId($id);
		$invoice->setKind(Invoice::KIND_INVOICE);
		$invoice->setStatus($status);
		$invoice->setNumber('RE-2026-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT));
		$invoice->setIssueDate('2026-03-05');
		$invoice->setDueDate($due);
		$invoice->setGrossCents(15000);
		$invoice->setOpenItemId($id + 100);
		$invoice->setDebtorSnapshot(json_encode(['name' => 'Muster GmbH', 'email' => $email]));
		return $invoice;
	}

	private function service(): InvoiceDunningService {
		$this->invoices = $this->createMock(InvoiceMapper::class);
		$this->invoices->method('find')->willReturnCallback(fn () => $this->invoice);
		$this->invoices->method('findFiltered')->willReturnCallback(fn (?string $status) => $this->invoice->getStatus() === $status ? [$this->invoice] : []);
		$openItems = $this->createMock(OpenItemMapper::class);
		$openItems->method('findByIds')->willReturnCallback(function () {
			$item = new OpenItem();
			$item->setId(101);
			$item->setAmountCents(15000);
			$item->setSettledCents(15000 - $this->remaining);
			return [101 => $item];
		});
		$this->dunnings = $this->createMock(InvoiceDunningMapper::class);
		$this->dunnings->method('lastByInvoices')->willReturnCallback(function () {
			if ($this->history === []) {
				return [];
			}
			$last = end($this->history);
			return [1 => ['level' => $last->getLevel(), 'sentAt' => $last->getSentAt()]];
		});
		$this->dunnings->method('insert')->willReturnArgument(0);
		$this->documents = $this->createMock(InvoiceDocumentService::class);
		$this->mailer = $this->createMock(IMailer::class);
		$settings = $this->createMock(InvoiceSettings::class);
		$settings->method('dunningAuto')->willReturnCallback(fn () => $this->auto);
		$settings->method('dunningDays')->willReturn([1 => 7, 2 => 14, 3 => 14]);
		$settings->method('dunningGraceDays')->willReturn(7);
		$settings->method('boardEmail')->willReturnCallback(fn () => $this->boardEmail);
		$settings->method('dunningCopyBoard')->willReturnCallback(fn () => $this->copyBoardSetting);
		$settings->method('all')->willReturn([]);
		$settings->method('issuer')->willReturn(['name' => 'TSV Beispiel e. V.']);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $params = []) => vsprintf($text, (array)$params));
		$l10n->method('getLanguageCode')->willReturn('de');
		return new InvoiceDunningService($this->mailer, $this->invoices, $openItems, $this->dunnings, $this->documents, $settings, $this->createMock(AuditService::class), $this->createMock(LoggerInterface::class), $this->createMock(IUserSession::class), $l10n, $this->createMock(ClubMailBranding::class));
	}

	private function row(): array {
		return ['id' => 1, 'kind' => 'rechnung', 'status' => $this->invoice->getStatus(), 'remainingCents' => $this->remaining, 'dueDate' => $this->invoice->getDueDate()];
	}

	private function expectMail(int $times): IMessage&MockObject {
		$template = $this->createMock(IEMailTemplate::class);
		$template->method('renderSubject')->willReturn('Betreff');
		$message = $this->createMock(IMessage::class);
		$message->method('setTo')->willReturnSelf();
		$message->method('setSubject')->willReturnSelf();
		$message->method('useTemplate')->willReturnSelf();
		$this->mailer->method('createEMailTemplate')->willReturn($template);
		$this->mailer->method('createMessage')->willReturn($message);
		$this->mailer->method('createAttachment')->willReturn($this->createMock(IAttachment::class));
		$this->mailer->expects($this->exactly($times))->method('send')->willReturn([]);
		$doc = new InvoiceDocument();
		$doc->setId(9);
		$this->documents->method('createDunningLetter')->willReturn($doc);
		$this->documents->method('documentContent')->willReturn(['content' => '%PDF', 'fileName' => 'RE-2026-0001-mahnung-1.pdf', 'kind' => 'dunning']);
		return $message;
	}

	public function testUeberfaelligeRechnungHatDieErinnerungAlsNaechsteStufe(): void {
		$state = $this->service()->annotate([$this->row()], self::TODAY)[0]['dunning'];

		$this->assertSame(0, $state['level']);
		$this->assertSame(1, $state['next']);
		$this->assertSame('2026-03-26', $state['dueOn']);
		$this->assertTrue($state['due']);
		$this->assertNull($state['blocked']);
	}

	public function testErinnerungNochNichtFaelligSolangeDieFristLaeuft(): void {
		$state = $this->service()->annotate([$this->row()], '2026-03-22')[0]['dunning'];

		$this->assertSame(1, $state['next']);
		$this->assertFalse($state['due']);
	}

	/** @dataProvider gesperrt */
	public function testBezahlteStornierteUndNichtFaelligeRechnungenWerdenNichtGemahnt(string $status, int $remaining, string $due, string $reason): void {
		$this->invoice->setStatus($status);
		$this->invoice->setDueDate($due);
		$this->remaining = $remaining;

		$state = $this->service()->annotate([$this->row()], self::TODAY)[0]['dunning'];

		$this->assertSame($reason, $state['blocked']);
		$this->assertNull($state['next']);
		$this->assertFalse($state['due']);
	}

	/** @return array<string, array{string, int, string, string}> */
	public static function gesperrt(): array {
		return [
			'bezahlt' => [Invoice::STATUS_PAID, 0, '2026-03-19', 'paid'],
			'Rest null' => [Invoice::STATUS_SENT, 0, '2026-03-19', 'paid'],
			'storniert' => [Invoice::STATUS_CANCELLED, 0, '2026-03-19', 'cancelled'],
			'Entwurf' => [Invoice::STATUS_DRAFT, 15000, '2026-03-19', 'draft'],
			'nicht fällig' => [Invoice::STATUS_SENT, 15000, '2026-04-01', 'not_due'],
		];
	}

	public function testNachDerLetztenStufeGibtEsKeineWeitere(): void {
		$sent = new InvoiceDunning();
		$sent->setInvoiceId(1);
		$sent->setLevel(3);
		$sent->setSentAt('2026-03-30T10:00:00+00:00');
		$this->history = [$sent];

		$state = $this->service()->annotate([$this->row()], self::TODAY)[0]['dunning'];

		$this->assertSame(3, $state['level']);
		$this->assertNull($state['next']);
	}

	public function testVersandErzeugtPdfSendetMailUndMerktDieMahnung(): void {
		$s = $this->service();
		$this->expectMail(1);

		$dunning = $s->send(1, ' zahler@example.org ', null, null, null, false, self::TODAY);

		$this->assertSame(1, $dunning->getLevel());
		$this->assertSame('zahler@example.org', $dunning->getSentTo());
		$this->assertSame(15000, $dunning->getRemainingCents());
		$this->assertSame(9, $dunning->getDocumentId());
		$this->assertSame(0, $dunning->getAuto());
	}

	public function testKopieAnDenVorstandGehtAlsBlindkopie(): void {
		$this->boardEmail = 'vorstand@verein.example';
		$s = $this->service();
		$this->expectMail(1)->expects($this->once())->method('setBcc')->with(['vorstand@verein.example']);

		$s->send(1, 'zahler@example.org', null, null, null, false, self::TODAY, true);
	}

	public function testOhneAngabeGiltDieEinstellung(): void {
		$this->boardEmail = 'vorstand@verein.example';
		$this->copyBoardSetting = true;
		$s = $this->service();
		$this->expectMail(1)->expects($this->once())->method('setBcc')->with(['vorstand@verein.example']);

		$s->send(1, 'zahler@example.org', null, null, null, false, self::TODAY);
	}

	public function testSchalterAusUeberstimmtDieEinstellung(): void {
		$this->boardEmail = 'vorstand@verein.example';
		$this->copyBoardSetting = true;
		$s = $this->service();
		$this->expectMail(1)->expects($this->never())->method('setBcc');

		$s->send(1, 'zahler@example.org', null, null, null, false, self::TODAY, false);
	}

	public function testKopieOhneVorstandsAdresseVerschicktNichts(): void {
		$s = $this->service();
		$this->mailer->expects($this->never())->method('send');
		$this->documents->expects($this->never())->method('createDunningLetter');

		$this->expectException(\InvalidArgumentException::class);
		$s->send(1, 'zahler@example.org', null, null, null, false, self::TODAY, true);
	}

	public function testTaeglicherLaufKopiertDenVorstandNachEinstellung(): void {
		$this->boardEmail = 'vorstand@verein.example';
		$this->copyBoardSetting = true;
		$s = $this->service();
		$this->expectMail(1)->expects($this->once())->method('setBcc')->with(['vorstand@verein.example']);

		$this->assertSame(['sent' => 1, 'skipped' => 0, 'failed' => 0], $s->runDaily(self::TODAY));
	}

	public function testTaeglicherLaufOhneVorstandsAdresseMahntTrotzdem(): void {
		$this->copyBoardSetting = true;
		$s = $this->service();
		$this->expectMail(1)->expects($this->never())->method('setBcc');

		$this->assertSame(['sent' => 1, 'skipped' => 0, 'failed' => 0], $s->runDaily(self::TODAY));
	}

	public function testBezahlteRechnungWirdNichtVerschickt(): void {
		$this->invoice->setStatus(Invoice::STATUS_PAID);
		$this->remaining = 0;
		$s = $this->service();
		$this->mailer->expects($this->never())->method('send');

		$this->expectException(\InvalidArgumentException::class);
		$s->send(1, 'zahler@example.org', null, null, null, false, self::TODAY);
	}

	public function testEineStufeLaesstSichNichtWiederholen(): void {
		$sent = new InvoiceDunning();
		$sent->setInvoiceId(1);
		$sent->setLevel(2);
		$sent->setSentAt('2026-03-30T10:00:00+00:00');
		$this->history = [$sent];
		$s = $this->service();
		$this->mailer->expects($this->never())->method('send');

		$this->expectException(\InvalidArgumentException::class);
		$s->send(1, 'zahler@example.org', null, null, 2, false, self::TODAY);
	}

	public function testUngueltigeAdresseVerschicktNichts(): void {
		$s = $this->service();
		$this->mailer->expects($this->never())->method('send');

		$this->expectException(\InvalidArgumentException::class);
		$s->send(1, 'kein-mail', null, null, null, false, self::TODAY);
	}

	public function testVorgabenFuellenDieTextbausteine(): void {
		$defaults = $this->service()->defaults(1);

		$this->assertSame(1, $defaults['level']);
		$this->assertSame('zahler@example.org', $defaults['to']);
		$this->assertStringContainsString('Zahlungserinnerung zu Rechnung RE-2026-0001 von TSV Beispiel e. V.', $defaults['subject']);
		$this->assertStringContainsString('Muster GmbH', $defaults['body']);
		$this->assertStringContainsString('150,00 €', $defaults['body']);
	}

	public function testTaeglicherLaufMahntNurWennEingeschaltet(): void {
		$this->auto = false;
		$s = $this->service();
		$this->mailer->expects($this->never())->method('send');

		$this->assertSame(['sent' => 0, 'skipped' => 0, 'failed' => 0], $s->runDaily(self::TODAY));
	}

	public function testTaeglicherLaufVerschicktFaelligeStufen(): void {
		$s = $this->service();
		$this->expectMail(1);

		$this->assertSame(['sent' => 1, 'skipped' => 0, 'failed' => 0], $s->runDaily(self::TODAY));
	}

	public function testTaeglicherLaufUeberspringtEmpfaengerOhneAdresse(): void {
		$this->invoice = $this->makeInvoice(1, '2026-03-19', Invoice::STATUS_SENT, '');
		$s = $this->service();
		$this->mailer->expects($this->never())->method('send');

		$this->assertSame(['sent' => 0, 'skipped' => 1, 'failed' => 0], $s->runDaily(self::TODAY));
	}

	public function testTaeglicherLaufLaesstNochNichtFaelligeStufenStehen(): void {
		$s = $this->service();
		$this->mailer->expects($this->never())->method('send');

		$this->assertSame(['sent' => 0, 'skipped' => 0, 'failed' => 0], $s->runDaily('2026-03-22'));
	}
}
