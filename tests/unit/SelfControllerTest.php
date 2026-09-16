<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\SelfController;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Exception\ForbiddenException;
use OCA\Vereinsbuchhaltung\Service\ActorContextService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * SelfController::me() – die einzige Stelle im Modul, die ohne
 * Buchhaltungsrolle erreichbar ist (Spec §3.4). Zwei Eigenschaften stehen im
 * Vordergrund:
 *
 *  - IDOR-Schutz: die zurückgegebene member_id kommt ausschließlich aus dem
 *    ActorContextService (von der Middleware aus der Kontoverknüpfung
 *    aufgelöst) – ein Request-Parameter mit einer fremden ID hat gar keinen
 *    Einfluss, weil der Controller ihn nie liest (er nimmt nicht einmal
 *    einen entgegen).
 *  - Datenhygiene: `internal_note` (Vereinsinterna) und technische Felder
 *    (`nc_user_id`, `created_at`) dürfen niemals in der Antwort landen.
 */
class SelfControllerTest extends TestCase {

	private const MEMBER_ID = 5;
	private const FOREIGN_MEMBER_ID = 999;

	private ActorContextService $actorContext;
	private MemberMapper&MockObject $memberMapper;
	private IL10N&MockObject $l10n;

	protected function setUp(): void {
		$userSession = $this->createMock(IUserSession::class);
		$this->actorContext = new ActorContextService($userSession);
		$this->memberMapper = $this->createMock(MemberMapper::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnArgument(0);
	}

	private function controller(): SelfController {
		$request = $this->createMock(IRequest::class);
		// Selbst wenn ein Angriff eine fremde ID mitschickt: SelfController
		// nimmt in me() gar keinen Parameter entgegen, ein getParam()-Aufruf
		// darf also nie stattfinden.
		$request->expects($this->never())->method('getParam');
		return new SelfController($request, $this->actorContext, $this->memberMapper, $this->l10n);
	}

	private function fullMember(): Member {
		$member = new Member();
		$member->setId(self::MEMBER_ID);
		$member->setMemberType(Member::TYPE_PERSON);
		$member->setFirstName('Katrin');
		$member->setLastName('Brunner');
		$member->setEmail('katrin@example.org');
		$member->setPhone('+49 30 1234567');
		$member->setStreet('Musterstraße 1');
		$member->setPostalCode('12345');
		$member->setCity('Berlin');
		$member->setCountry('DE');
		$member->setMemberNumber('M-042');
		$member->setJoinedAt('2020-01-01');
		$member->setNcUserId('katrin.b');
		$member->setInternalNote('Zahlt oft zu spät – im Blick behalten.');
		$member->setCreatedAt('2020-01-01T00:00:00+00:00');
		return $member;
	}

	public function testOhneAufgeloesteMemberIdWirdVerweigert(): void {
		// ActorContextService steht auf dem Default (staff, keine member_id) -
		// die Middleware laesst diesen Fall in der Praxis nie bis hierher
		// durch, der Controller ist trotzdem als zweite Linie abgesichert.
		$this->memberMapper->expects($this->never())->method('findOrNull');

		$this->expectException(ForbiddenException::class);
		$this->controller()->me();
	}

	public function testLiefertNurKontaktdatenDesEigenenMitglieds(): void {
		$this->actorContext->setMemberChannel(self::MEMBER_ID);
		$member = $this->fullMember();
		$this->memberMapper->method('findOrNull')->with(self::MEMBER_ID)->willReturn($member);

		$response = $this->controller()->me();
		$data = $response->getData();

		$this->assertSame(self::MEMBER_ID, $data['id']);
		$this->assertSame('Katrin', $data['firstName']);
		$this->assertSame('Brunner', $data['lastName']);
		$this->assertSame('katrin@example.org', $data['email']);
		$this->assertSame('+49 30 1234567', $data['phone']);
		$this->assertSame('Musterstraße 1', $data['street']);
		$this->assertSame('M-042', $data['memberNumber']);
	}

	/**
	 * Kern der Datenhygiene-Anforderung aus Issue #74: `internal_note` ist
	 * Vereinsinterna und darf im Self-Service niemals sichtbar sein, ebenso
	 * wenig `nc_user_id`/`created_at` (technische Verwaltungsfelder). Ein
	 * naives `jsonSerialize()` der Entity würde das verletzen.
	 */
	public function testInternalNoteUndTechnischeFelderNieInDerAntwort(): void {
		$this->actorContext->setMemberChannel(self::MEMBER_ID);
		$this->memberMapper->method('findOrNull')->willReturn($this->fullMember());

		$data = $this->controller()->me()->getData();

		$this->assertArrayNotHasKey('internalNote', $data);
		$this->assertArrayNotHasKey('ncUserId', $data);
		$this->assertArrayNotHasKey('createdAt', $data);
	}

	/**
	 * IDOR-Kern: MemberMapper::findOrNull() wird ausschließlich mit der
	 * server-aufgelösten eigenen member_id aufgerufen - nie mit einer
	 * fremden ID, selbst wenn eine im Request stünde (siehe controller(),
	 * das getParam() als "darf nie aufgerufen werden" absichert).
	 */
	public function testFindetNurUeberDieAufgeloesteEigeneMemberId(): void {
		$this->actorContext->setMemberChannel(self::MEMBER_ID);
		$this->memberMapper->expects($this->once())
			->method('findOrNull')
			->with(self::MEMBER_ID)
			->willReturn($this->fullMember());

		$this->controller()->me();
	}

	public function testUnbekanntesMitgliedLiefert404(): void {
		$this->actorContext->setMemberChannel(self::FOREIGN_MEMBER_ID);
		$this->memberMapper->method('findOrNull')->with(self::FOREIGN_MEMBER_ID)->willReturn(null);

		$response = $this->controller()->me();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}
}
