<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\InternalErrorResponder;
use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Interne Ausnahmetexte (Pfade, SQL, Bibliotheksmeldungen) gehören ins Log,
 * nicht in die Antwort an den Browser (Issue #261). Nur eine
 * UserFacingException trägt einen Text, der angezeigt werden darf.
 */
class InternalErrorResponderTest extends TestCase {

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function responder(): object {
		return new class($this->logger) {
			use InternalErrorResponder;

			public function __construct(
				private LoggerInterface $logger,
			) {
			}

			/** @param Http::STATUS_* $status */
			public function respond(\Throwable $e, int $status, string $fallback, string $field = 'message'): DataResponse {
				return $this->internalError($e, $status, $fallback, $field);
			}
		};
	}

	public function testInterneMeldungBleibtImLog(): void {
		$e = new \RuntimeException('/var/www/html/apps/x/vendor/tcpdf/tcpdf.php: font missing');
		$this->logger->expects($this->once())
			->method('error')
			->with($e->getMessage(), $this->callback(fn (array $context): bool => ($context['exception'] ?? null) === $e
				&& ($context['app'] ?? null) === 'vereinsbuchhaltung'));

		$response = $this->responder()->respond($e, Http::STATUS_INTERNAL_SERVER_ERROR, 'PDF konnte nicht erzeugt werden.');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'PDF konnte nicht erzeugt werden.'], $response->getData());
		$this->assertStringNotContainsString('/var/www', (string)json_encode($response->getData()));
	}

	public function testNutzermeldungKommtDurch(): void {
		$this->logger->expects($this->never())->method('error');

		$response = $this->responder()->respond(
			new UserFacingException('Kein Rechnungskonto hinterlegt.'),
			Http::STATUS_BAD_GATEWAY,
			'E-Mail konnte nicht versendet werden.',
		);

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame(['message' => 'Kein Rechnungskonto hinterlegt.'], $response->getData());
	}

	public function testFeldnameIstWaehlbar(): void {
		$response = $this->responder()->respond(new \LogicException('intern'), Http::STATUS_OK, 'Fallback', 'pdfError');

		$this->assertSame(['pdfError' => 'Fallback'], $response->getData());
	}
}
