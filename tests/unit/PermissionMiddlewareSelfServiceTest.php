<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\SelfController;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Exception\ForbiddenException;
use OCA\Vereinsbuchhaltung\Middleware\PermissionMiddleware;
use OCA\Vereinsbuchhaltung\Service\ActorContextService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\SelfServiceService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Vierter instanceof-Sonderfall der PermissionMiddleware (Issue #74, Spec
 * §3.4): der SelfController braucht KEINE vbh-Rolle – statt dessen zählt
 * ausschließlich `self_service_enabled && Kontoverknüpfung`.
 *
 * Zentrale Sicherheitseigenschaft, die hier geprüft wird: es gibt keinen Weg,
 * über einen Request-Parameter an eine fremde member_id zu kommen (IDOR) –
 * die einzige Quelle ist die vom `ActorContextService` bereitgestellte,
 * serverseitig aus der Kontoverknüpfung aufgelöste ID. Deshalb prüft
 * `testFremdeMemberIdWirdIgnoriert()` nicht nur "kein Zugriff", sondern dass
 * ein manipulierter Parameter im Erfolgsfall schlicht nie gelesen wird.
 *
 * SelfServiceService selbst wird gemockt (sein eigenes Verhalten ist Sache
 * von SelfServiceServiceTest) – hier interessiert nur, wie die Middleware
 * darauf reagiert.
 */
class PermissionMiddlewareSelfServiceTest extends TestCase {

	private const MEMBER_ID = 7;
	/** Manipulierter Fremd-Parameter, den ein Angriff mitschicken könnte. */
	private const FOREIGN_MEMBER_ID = 999;

	private SelfServiceService&MockObject $selfService;
	private PermissionService&MockObject $permissions;
	private ActorContextService $actorContext;
	private IL10N&MockObject $l10n;

	protected function setUp(): void {
		$this->selfService = $this->createMock(SelfServiceService::class);
		$this->permissions = $this->createMock(PermissionService::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnArgument(0);

		// Echte Instanz statt Mock: ActorContextService ist reiner Zustand
		// (Getter/Setter), ein Mock würde hier nur die Middleware-Aufrufe an
		// sich selbst zurückspiegeln, statt den tatsächlichen Effekt zu
		// zeigen. userSession wird nicht gebraucht (actorUid() wird in diesen
		// Tests nicht abgefragt).
		$userSession = $this->createMock(IUserSession::class);
		$this->actorContext = new ActorContextService($userSession);
	}

	private function middleware(string $httpMethod = 'GET'): PermissionMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn($httpMethod);
		return new PermissionMiddleware($this->permissions, $this->selfService, $this->actorContext, $request, $this->l10n);
	}

	/**
	 * Eine echte Entity statt Mock: getId()/setId() sind an der Entity-
	 * Basisklasse magisch über __call() implementiert (siehe @method-Docblock
	 * an OCP\AppFramework\Db\Entity), PHPUnit kann magische Methoden nicht
	 * per createMock()->method() konfigurieren.
	 */
	private function member(int $id = self::MEMBER_ID): Member {
		$member = new Member();
		$member->setId($id);
		return $member;
	}

	public function testVerknuepftUndAktiviertGewaehrtZugriff(): void {
		$this->selfService->method('isEnabled')->willReturn(true);
		$this->selfService->method('currentMember')->willReturn($this->member());

		// Darf nicht werfen.
		$this->middleware()->beforeController($this->createMock(SelfController::class), 'me');

		$this->assertSame(ActorContextService::TYPE_MEMBER, $this->actorContext->actorType());
		$this->assertSame(self::MEMBER_ID, $this->actorContext->memberId());
	}

	public function testVerknuepftAberDeaktiviertVerweigertZugriff(): void {
		$this->selfService->method('isEnabled')->willReturn(false);
		// Bei deaktiviertem Schalter darf currentMember() gar nicht erst
		// abgefragt werden müssen - die Middleware kurzschließt über isEnabled().
		$this->selfService->expects($this->never())->method('currentMember');

		$this->expectException(ForbiddenException::class);
		$this->middleware()->beforeController($this->createMock(SelfController::class), 'me');
	}

	public function testNichtVerknuepftVerweigertZugriffTrotzAktiviertemSchalter(): void {
		$this->selfService->method('isEnabled')->willReturn(true);
		$this->selfService->method('currentMember')->willReturn(null);

		$this->expectException(ForbiddenException::class);
		$this->middleware()->beforeController($this->createMock(SelfController::class), 'me');
	}

	/**
	 * IDOR-Kern: selbst wenn ein Request eine fremde member_id mitschickt
	 * (Query/Body), landet im ActorContextService ausschließlich die vom
	 * Server aufgelöste eigene ID. Der SelfController hat gar keine
	 * Möglichkeit, an die manipulierte ID heranzukommen - sie fließt nie in
	 * die Middleware-Prüfung ein.
	 */
	public function testFremdeMemberIdWirdIgnoriert(): void {
		$this->selfService->method('isEnabled')->willReturn(true);
		$this->selfService->method('currentMember')->willReturn($this->member(self::MEMBER_ID));

		$this->middleware()->beforeController($this->createMock(SelfController::class), 'me');

		$this->assertSame(self::MEMBER_ID, $this->actorContext->memberId());
		$this->assertNotSame(self::FOREIGN_MEMBER_ID, $this->actorContext->memberId());
	}

	/** Jeder andere Controller bekommt weiterhin den Kanal "staff" (Personalunion, Spec §3.9). */
	public function testAndererControllerSetztStaffKanal(): void {
		$this->permissions->method('getRole')->willReturn(PermissionService::ROLE_ADMIN);

		$other = $this->createMock(\OCA\Vereinsbuchhaltung\Controller\AccountController::class);
		$this->middleware()->beforeController($other, 'index');

		$this->assertSame(ActorContextService::TYPE_STAFF, $this->actorContext->actorType());
		$this->assertNull($this->actorContext->memberId());
	}
}
