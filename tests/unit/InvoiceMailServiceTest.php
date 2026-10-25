<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceMapper;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceDocumentService;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceMailService;
use OCA\Vereinsbuchhaltung\Service\InvoiceService;
use OCA\Vereinsbuchhaltung\Service\InvoiceSettings;
use OCP\IL10N;
use OCP\Mail\IAttachment;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Rechnungsversand per Mail: Vorgaben mit Platzhaltern, Prüfung der Adresse,
 * PDF im Anhang und Vermerk „versendet" nur nach erfolgreichem Versand.
 */
class InvoiceMailServiceTest extends TestCase {

	private IMailer&MockObject $mailer;
	private InvoiceService&MockObject $service;
	private InvoiceDocumentService&MockObject $documents;
	private InvoiceSettings&MockObject $settings;
	private Invoice $invoice;

	protected function setUp(): void {
		$this->invoice = new Invoice();
		$this->invoice->setId(1);
		$this->invoice->setKind(Invoice::KIND_INVOICE);
		$this->invoice->setStatus(Invoice::STATUS_ISSUED);
		$this->invoice->setIssuedAt('2026-03-05T10:00:00+00:00');
		$this->invoice->setNumber('RE-2026-0042');
		$this->invoice->setGrossCents(150050);
		$this->invoice->setDueDate('2026-03-19');
		$this->invoice->setDebtorSnapshot(json_encode(['name' => 'Muster GmbH', 'email' => 'buchhaltung@muster.example']));
	}

	private function service(): InvoiceMailService {
		$this->mailer = $this->createMock(IMailer::class);
		$this->service = $this->createMock(InvoiceService::class);
		$this->documents = $this->createMock(InvoiceDocumentService::class);
		$this->settings = $this->createMock(InvoiceSettings::class);
		$this->settings->method('all')->willReturn([InvoiceSettings::SETTING_MAIL_SUBJECT => '', InvoiceSettings::SETTING_MAIL_BODY => '']);
		$this->settings->method('issuer')->willReturn(['name' => 'TSV Beispiel e. V.']);
		$invoices = $this->createMock(InvoiceMapper::class);
		$invoices->method('find')->willReturn($this->invoice);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $params = []) => vsprintf($text, (array)$params));
		$l10n->method('getLanguageCode')->willReturn('de');
		return new InvoiceMailService($this->mailer, $invoices, $this->service, $this->documents, $this->settings, $this->createMock(LoggerInterface::class), $l10n);
	}

	private function mockMessage(): IMessage&MockObject {
		$template = $this->createMock(IEMailTemplate::class);
		$template->method('renderSubject')->willReturn('Betreff');
		$message = $this->createMock(IMessage::class);
		$message->method('setTo')->willReturnSelf();
		$message->method('setSubject')->willReturnSelf();
		$message->method('useTemplate')->willReturnSelf();
		$this->mailer->method('createEMailTemplate')->willReturn($template);
		$this->mailer->method('createMessage')->willReturn($message);
		$this->mailer->method('createAttachment')->willReturn($this->createMock(IAttachment::class));
		$this->documents->method('pdfContent')->willReturn(['content' => '%PDF-1.7', 'fileName' => 'RE-2026-0042.pdf']);
		return $message;
	}

	public function testVorgabenFuellenDiePlatzhalter(): void {
		$defaults = $this->service()->defaults(1);

		$this->assertSame('buchhaltung@muster.example', $defaults['to']);
		$this->assertSame('Rechnung RE-2026-0042 von TSV Beispiel e. V.', $defaults['subject']);
		$this->assertStringContainsString('Guten Tag Muster GmbH', $defaults['body']);
		$this->assertStringContainsString('1.500,50 €', $defaults['body']);
		$this->assertStringContainsString('19.03.2026', $defaults['body']);
		$this->assertSame('RE-2026-0042.pdf', $defaults['fileName']);
	}

	public function testStornorechnungBekommtEinenEigenenText(): void {
		$this->invoice->setKind(Invoice::KIND_CREDIT);
		$this->invoice->setGrossCents(-150050);

		$body = $this->service()->defaults(1)['body'];

		$this->assertStringContainsString('Stornorechnung RE-2026-0042 über 1.500,50 €', $body);
		$this->assertStringNotContainsString('überweisen', $body);
	}

	public function testErfolgreicherVersandSendetMitPdfUndVermerktDenVersand(): void {
		$s = $this->service();
		$message = $this->mockMessage();
		$message->expects($this->once())->method('attach');
		$this->mailer->expects($this->once())->method('send')->willReturn([]);
		$this->service->expects($this->once())->method('markSent')->with(1)->willReturn($this->invoice);

		$s->send(1, ' buchhaltung@muster.example ');
	}

	public function testUngueltigeAdresseVerschicktNichts(): void {
		$s = $this->service();
		$this->mailer->expects($this->never())->method('send');

		$this->expectException(\InvalidArgumentException::class);
		$s->send(1, 'kein-mail');
	}

	public function testEntwurfUndStornierteRechnungWerdenNichtVerschickt(): void {
		$this->invoice->setIssuedAt(null);
		$s = $this->service();
		$this->mailer->expects($this->never())->method('send');

		$this->expectException(\InvalidArgumentException::class);
		$s->send(1, 'a@b.example');
	}

	public function testScheitertDerVersandBleibtDieRechnungUnversendet(): void {
		$s = $this->service();
		$this->mockMessage();
		$this->mailer->method('send')->willThrowException(new \Exception('SMTP down'));
		$this->service->expects($this->never())->method('markSent');

		$this->expectException(\RuntimeException::class);
		$s->send(1, 'a@b.example');
	}

	public function testAbgelehnteEmpfaengerGeltenAlsFehler(): void {
		$s = $this->service();
		$this->mockMessage();
		$this->mailer->method('send')->willReturn(['a@b.example']);
		$this->service->expects($this->never())->method('markSent');

		$this->expectException(\RuntimeException::class);
		$s->send(1, 'a@b.example');
	}

	public function testBetreffUndTextLassenSichBeimVersandUeberschreiben(): void {
		$s = $this->service();
		$this->mockMessage();
		$template = $this->createMock(IEMailTemplate::class);
		$template->method('renderSubject')->willReturn('x');
		$template->expects($this->once())->method('setSubject')->with('Mein Betreff');
		$this->mailer = $this->createMock(IMailer::class);
		$this->mailer->method('createEMailTemplate')->willReturn($template);
		$message = $this->createMock(IMessage::class);
		$message->method('setTo')->willReturnSelf();
		$message->method('setSubject')->willReturnSelf();
		$message->method('useTemplate')->willReturnSelf();
		$this->mailer->method('createMessage')->willReturn($message);
		$this->mailer->method('createAttachment')->willReturn($this->createMock(IAttachment::class));
		$this->mailer->method('send')->willReturn([]);
		// Neuen Dienst mit dem neu aufgebauten Mailer erzeugen.
		$invoices = $this->createMock(InvoiceMapper::class);
		$invoices->method('find')->willReturn($this->invoice);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $params = []) => vsprintf($text, (array)$params));
		$l10n->method('getLanguageCode')->willReturn('de');
		$documents = $this->createMock(InvoiceDocumentService::class);
		$documents->method('pdfContent')->willReturn(['content' => '%PDF', 'fileName' => 'x.pdf']);
		$settings = $this->createMock(InvoiceSettings::class);
		$settings->method('all')->willReturn([InvoiceSettings::SETTING_MAIL_SUBJECT => '', InvoiceSettings::SETTING_MAIL_BODY => '']);
		$settings->method('issuer')->willReturn(['name' => 'Verein']);
		$service = $this->createMock(InvoiceService::class);
		$service->method('markSent')->willReturn($this->invoice);

		(new InvoiceMailService($this->mailer, $invoices, $service, $documents, $settings, $this->createMock(LoggerInterface::class), $l10n))
			->send(1, 'a@b.example', 'Mein Betreff', 'Mein Text');
	}
}
