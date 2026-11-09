<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\InvoiceController;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Service\EInvoice\EInvoiceService;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceDocumentService;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceDunningService;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceMailService;
use OCA\Vereinsbuchhaltung\Service\InvoiceMatchingService;
use OCA\Vereinsbuchhaltung\Service\InvoicePaymentService;
use OCA\Vereinsbuchhaltung\Service\InvoiceService;
use OCA\Vereinsbuchhaltung\Service\InvoiceSettings;
use OCA\Vereinsbuchhaltung\Service\NumberRangeService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Fehlerpfade des Rechnungs-Controllers (Issue #261): interne Ausnahmetexte
 * gehen ins Log, der Browser bekommt eine allgemeine Meldung. Bewusst
 * formulierte Meldungen (UserFacingException) kommen weiter durch.
 */
class InvoiceControllerTest extends TestCase {

	private const PFAD = '/var/www/html/apps/vereinsbuchhaltung/vendor/tecnickcom/tcpdf/tcpdf.php: font helvetica missing';

	private InvoiceService&MockObject $service;
	private InvoiceDocumentService&MockObject $documents;
	private InvoiceMailService&MockObject $mail;
	private LoggerInterface&MockObject $logger;
	private string $ownEmail = 'kassenwart@verein.example';

	protected function setUp(): void {
		$this->service = $this->createMock(InvoiceService::class);
		$this->service->method('detail')->willReturn(['status' => 'draft']);
		$this->documents = $this->createMock(InvoiceDocumentService::class);
		$this->mail = $this->createMock(InvoiceMailService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function controller(): InvoiceController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return new InvoiceController(
			$this->createMock(IRequest::class),
			$this->service,
			$this->createMock(InvoiceSettings::class),
			$this->createMock(NumberRangeService::class),
			$this->createMock(InvoicePaymentService::class),
			$this->createMock(InvoiceMatchingService::class),
			$this->documents,
			$this->mail,
			$this->createMock(EInvoiceService::class),
			$this->createMock(InvoiceDunningService::class),
			$this->createMock(BankTransactionMapper::class),
			$l10n,
			$this->logger,
			$this->session(),
		);
	}

	private function session(): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturnCallback(fn () => $this->ownEmail === '' ? null : $this->ownEmail);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}

	public function testTestversandGehtAnDieAdresseDesEigenenKontos(): void {
		$this->mail->expects($this->once())->method('sendTest')->with(5, 'kassenwart@verein.example', 'Betreff', null, [3]);

		$response = $this->controller()->sendTest(5, 'Betreff', null, ['3']);

		$this->assertSame(['sentTo' => 'kassenwart@verein.example'], $response->getData());
	}

	public function testVorgabenNennenDieEigeneAdresseFuerDenTest(): void {
		$this->mail->method('defaults')->willReturn(['to' => 'kunde@example.org']);

		$data = $this->controller()->mailDefaults(5)->getData();

		$this->assertSame('kunde@example.org', $data['to']);
		$this->assertSame('kassenwart@verein.example', $data['testTo']);
	}

	public function testOhneEigeneAdresseIstDieTestadresseLeer(): void {
		$this->ownEmail = '';
		$this->mail->method('defaults')->willReturn(['to' => 'kunde@example.org']);

		$this->assertSame('', $this->controller()->mailDefaults(5)->getData()['testTo']);
	}

	public function testPdfFehlerVerraetKeinePfade(): void {
		$this->documents->method('pdfContent')->willThrowException(new \RuntimeException(self::PFAD));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller()->pdf(5);

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'PDF konnte nicht erzeugt werden.'], $response->getData());
	}

	public function testPdfFehlerBeimFestschreibenVerraetKeinePfade(): void {
		$this->documents->method('ensurePdf')->willThrowException(new \RuntimeException(self::PFAD));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller()->issue(5);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('PDF konnte nicht erzeugt werden.', $response->getData()['pdfError']);
	}

	public function testMailserverTextBleibtImLog(): void {
		$this->mail->method('send')->willThrowException(new \RuntimeException('Expected response code 250 but got code "550", with message "550 5.7.1 relay denied smtp.intern.example"'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller()->send(5, 'a@example.org');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame(['message' => 'E-Mail konnte nicht versendet werden.'], $response->getData());
	}

	public function testNutzermeldungDesMailversandsKommtDurch(): void {
		$this->mail->method('send')->willThrowException(new UserFacingException('Die E-Mail wurde vom Mailserver nicht angenommen.'));
		$this->logger->expects($this->never())->method('error');

		$response = $this->controller()->send(5, 'a@example.org');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame(['message' => 'Die E-Mail wurde vom Mailserver nicht angenommen.'], $response->getData());
	}
}
