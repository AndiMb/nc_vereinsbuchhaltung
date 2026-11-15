<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Controller\AccountReconciliationController;
use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\ImportService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\Reconciliation\ReconciliationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Endpunkt der Kontoabstimmung (Issue #159): liest nur, ab `revisor`. */
class AccountReconciliationControllerTest extends TestCase {
	private ReconciliationService&MockObject $service;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->service = $this->createMock(ReconciliationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/** @param array<string, mixed> $params */
	private function controller(array $params): AccountReconciliationController {
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->willReturn(null);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []): string => $p === [] ? $text : vsprintf($text, $p));
		return new AccountReconciliationController($request, $this->service, $l10n, $this->logger);
	}

	public function testErfolgReichtDasErgebnisDurch(): void {
		$this->service->expects($this->once())->method('reconcile')
			->with(Application::BOOK, 7, 'AUSZUG', '2026-01-01', '2026-01-31', 5)
			->willReturn(['surplus' => []]);

		$response = $this->controller(['content' => 'AUSZUG', 'from' => '2026-01-01', 'to' => '2026-01-31', 'tolerance' => '5'])->run(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['surplus' => []], $response->getData());
	}

	public function testOhneAngabenGeltenAuszugszeitraumUndVorgabeToleranz(): void {
		$this->service->expects($this->once())->method('reconcile')
			->with(Application::BOOK, 7, 'AUSZUG', null, null, ReconciliationService::DEFAULT_TOLERANCE)
			->willReturn([]);

		$this->controller(['content' => 'AUSZUG', 'from' => '', 'to' => ''])->run(7);
	}

	public function testUnlesbareToleranzIstDieVorgabe(): void {
		$this->service->expects($this->once())->method('reconcile')
			->with($this->anything(), 7, 'AUSZUG', null, null, ReconciliationService::DEFAULT_TOLERANCE)
			->willReturn([]);

		$this->controller(['content' => 'AUSZUG', 'tolerance' => 'viel'])->run(7);
	}

	public function testOhneDatei(): void {
		$this->service->expects($this->never())->method('reconcile');

		$response = $this->controller([])->run(7);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Keine Datei empfangen', $response->getData()['message']);
	}

	public function testZuGrosseDatei(): void {
		$this->service->expects($this->never())->method('reconcile');

		$response = $this->controller(['content' => str_repeat('x', ImportService::MAX_BYTES + 1)])->run(7);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testFachlicheMeldungenKommenDurch(): void {
		$this->service->method('reconcile')->willThrowException(new \InvalidArgumentException('Der Zeitraum endet vor seinem Beginn.'));
		$response = $this->controller(['content' => 'AUSZUG'])->run(7);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Der Zeitraum endet vor seinem Beginn.', $response->getData()['message']);

		$this->service = $this->createMock(ReconciliationService::class);
		$this->service->method('reconcile')->willThrowException(new UserFacingException('Das Dateiformat wurde nicht erkannt.'));
		$response = $this->controller(['content' => 'AUSZUG'])->run(7);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Das Dateiformat wurde nicht erkannt.', $response->getData()['message']);
	}

	public function testUnbekanntesKonto(): void {
		$this->service->method('reconcile')->willThrowException(new DoesNotExistException('x'));

		$response = $this->controller(['content' => 'AUSZUG'])->run(7);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	/** Technische Fehler gehen ins Log, nicht in die Antwort (Issue #261). */
	public function testTechnischerFehlerVerraetNichts(): void {
		$this->service->method('reconcile')->willThrowException(new \RuntimeException('SQLSTATE[42S02] vbh_journal'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller(['content' => 'AUSZUG'])->run(7);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringNotContainsString('SQLSTATE', $response->getData()['message']);
	}

	/** Ein POST, der nur liest: die Rolle steht ausdrücklich da, sonst verlangte die Verb-Heuristik `buchhalter`. */
	public function testAbRevisor(): void {
		$attributes = (new \ReflectionMethod(AccountReconciliationController::class, 'run'))->getAttributes(RequiresRole::class);

		$this->assertCount(1, $attributes);
		$this->assertSame([PermissionService::ROLE_READ], $attributes[0]->getArguments());
	}
}
