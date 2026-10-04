<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\ClaimController;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\ClaimOverviewService;
use OCA\Vereinsbuchhaltung\Service\ClaimService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Rechtelinie der Forderungs-Endpunkte (Spec §3.9, Issue #104): lesend ab
 * `revisor`, jede Schreibaktion ab `buchhalter` – jeweils ausdrücklich per
 * #[RequiresRole], nie über die Verb-Heuristik. Den Rückgabecode der
 * Rücklastschrift bekommt nur, wer schreiben darf (Spec §3.6).
 */
class ClaimControllerTest extends TestCase {

	private ClaimService&MockObject $service;
	private ClaimOverviewService&MockObject $overview;
	private PermissionService&MockObject $permissions;

	protected function setUp(): void {
		$this->service = $this->createMock(ClaimService::class);
		$this->overview = $this->createMock(ClaimOverviewService::class);
		$this->permissions = $this->createMock(PermissionService::class);
	}

	private function controller(): ClaimController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('kassenwart');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new ClaimController($this->createMock(IRequest::class), $this->service, $this->overview, $this->permissions, $session, $l10n);
	}

	private function declaredRole(string $method): ?string {
		$attributes = (new \ReflectionMethod(ClaimController::class, $method))->getAttributes(RequiresRole::class);
		return $attributes === [] ? null : $attributes[0]->newInstance()->role;
	}

	public function testDieUebersichtIstAbRevisorLesbarJedeSchreibaktionErstAbBuchhalter(): void {
		$this->assertSame(PermissionService::ROLE_READ, $this->declaredRole('overview'));
		foreach (['create', 'settle', 'cancel', 'defer', 'undefer'] as $method) {
			$this->assertSame(PermissionService::ROLE_WRITE, $this->declaredRole($method), $method);
		}
	}

	public function testBuchhalterBekommenDenRueckgabecodeRevisorenNicht(): void {
		$this->permissions->method('canWrite')->willReturnOnConsecutiveCalls(true, false);
		$this->overview->expects($this->exactly(2))->method('build')
			->willReturnCallback(static fn (bool $withCodes): array => ['today' => '2026-10-10', 'dunningIntervalDays' => 14, 'claims' => [], 'withCodes' => $withCodes]);

		$controller = $this->controller();
		$forWriter = $controller->overview()->getData();
		$forReader = $controller->overview()->getData();

		$this->assertTrue($forWriter['withCodes']);
		$this->assertFalse($forReader['withCodes']);
	}

	public function testErledigenSchreibtDenAngemeldetenNutzerAlsUrheber(): void {
		$this->service->expects($this->once())->method('settle')->with(7, 'paid', 'Bar', 'kassenwart')->willReturn($this->claim());

		$response = $this->controller()->settle(7, 'paid', 'Bar');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUndeferMeldetEineNichtGestundeteForderungAlsFehler(): void {
		$this->service->method('undefer')->willThrowException(new \InvalidArgumentException('Diese Forderung ist nicht gestundet.'));

		$response = $this->controller()->undefer(7);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Diese Forderung ist nicht gestundet.', $response->getData()['message']);
	}

	private function claim(): OpenItem {
		$item = new OpenItem();
		$item->setId(7);
		return $item;
	}
}
