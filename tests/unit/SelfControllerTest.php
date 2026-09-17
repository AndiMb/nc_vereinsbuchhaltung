<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\SelfController;
use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\ContributionGroup;
use OCA\Vereinsbuchhaltung\Db\ContributionGroupMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Exception\ForbiddenException;
use OCA\Vereinsbuchhaltung\Service\ActorContextService;
use OCA\Vereinsbuchhaltung\Service\Export\BeitragsbescheinigungRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\DatenuebersichtRenderer;
use OCA\Vereinsbuchhaltung\Service\MandateActivationService;
use OCA\Vereinsbuchhaltung\Service\MandateService;
use OCA\Vereinsbuchhaltung\Service\SelfContactService;
use OCA\Vereinsbuchhaltung\Service\SelfContributionService;
use OCA\Vereinsbuchhaltung\Service\SelfServiceMandateService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
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
	private SelfContributionService&MockObject $contributions;
	private SelfContactService&MockObject $contact;
	private ContributionGroupMapper&MockObject $groupMapper;
	private BeitragsbescheinigungRenderer&MockObject $certificateRenderer;
	private DatenuebersichtRenderer&MockObject $dataOverviewRenderer;

	protected function setUp(): void {
		$userSession = $this->createMock(IUserSession::class);
		$this->actorContext = new ActorContextService($userSession);
		$this->memberMapper = $this->createMock(MemberMapper::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnArgument(0);
		$this->contributions = $this->createMock(SelfContributionService::class);
		$this->contact = $this->createMock(SelfContactService::class);
		$this->groupMapper = $this->createMock(ContributionGroupMapper::class);
		$this->certificateRenderer = $this->createMock(BeitragsbescheinigungRenderer::class);
		$this->dataOverviewRenderer = $this->createMock(DatenuebersichtRenderer::class);
	}

	private function controller(): SelfController {
		$request = $this->createMock(IRequest::class);
		// Selbst wenn ein Angriff eine fremde ID mitschickt: SelfController
		// nimmt in me() gar keinen Parameter entgegen, ein getParam()-Aufruf
		// darf also nie stattfinden.
		$request->expects($this->never())->method('getParam');
		// Issue #67 (requestMandateActivationLink) und Issue #75 (Mandats-
		// Aktionskatalog) sind nicht Gegenstand dieser Testdatei (siehe
		// MandateActivationServiceTest/SelfServiceMandateServiceTest) - hier
		// reichen leere Mocks, keiner der me()-Tests ruft sie mit Erwartungen auf.
		return new SelfController(
			$request,
			$this->actorContext,
			$this->memberMapper,
			$this->createMock(MandateService::class),
			$this->createMock(MandateActivationService::class),
			$this->createMock(SelfServiceMandateService::class),
			$this->contributions,
			$this->contact,
			$this->groupMapper,
			$this->certificateRenderer,
			$this->dataOverviewRenderer,
			$this->l10n,
		);
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

	// --- Beitrag-Aktionen (Issue #76): duenne HTTP-Huelle um SelfContributionService ---

	private function assignment(int $id = 1, int $amountCents = 1500, ?string $overrideReason = null): Assignment {
		$a = new Assignment();
		$a->setId($id);
		$a->setMemberId(self::MEMBER_ID);
		$a->setGroupId(1);
		$a->setIntervalMonths(1);
		$a->setMonthlyAmountCents($amountCents);
		$a->setMinMonthlyAmountOverrideCents(500);
		$a->setOverrideReason($overrideReason);
		$a->setPaymentMethod(Assignment::PAYMENT_METHOD_DIRECT_DEBIT);
		$a->setValidFrom('2026-01-01');
		$a->setCreatedAt('2026-01-01T00:00:00+00:00');
		return $a;
	}

	public function testAssignmentsDelegiertAnSelfContributionService(): void {
		$this->contributions->expects($this->once())->method('findOwn')->willReturn([$this->assignment()]);

		$data = $this->controller()->assignments()->getData();

		$this->assertCount(1, $data);
	}

	public function testAssignmentsReichertMitGruppennameUndErlaubtenTurnussenAn(): void {
		$this->contributions->method('findOwn')->willReturn([$this->assignment()]);
		$group = new ContributionGroup();
		$group->setName('Basisbeitrag');
		$group->setAllowedIntervalsArray([1, 12]);
		$this->groupMapper->method('find')->with(1)->willReturn($group);

		$data = $this->controller()->assignments()->getData();

		$this->assertSame('Basisbeitrag', $data[0]['groupName']);
		$this->assertSame([1, 12], $data[0]['allowedIntervals']);
	}

	/** Ohne individuelle Untergrenze ist die Gruppen-Untergrenze die geltende (Spec §3.4). */
	public function testAssignmentsZeigtGeltendeUntergrenzeAuchOhneOverride(): void {
		$assignment = $this->assignment();
		$assignment->setMinMonthlyAmountOverrideCents(null);
		$this->contributions->method('findOwn')->willReturn([$assignment]);
		$group = new ContributionGroup();
		$group->setMinMonthlyAmountCents(800);
		$this->groupMapper->method('find')->willReturn($group);

		$data = $this->controller()->assignments()->getData();

		$this->assertSame(8, $data[0]['effectiveMinMonthlyAmount']);
	}

	/** Individuelle Untergrenze sichtbar, ihre Begründung nicht (Spec §3.4 Pflicht-UI). */
	public function testAssignmentsBlendetOverrideReasonAus(): void {
		$this->contributions->method('findOwn')->willReturn([$this->assignment(overrideReason: 'Sozialermäßigung, siehe Vorstandsbeschluss')]);

		$data = $this->controller()->assignments()->getData();

		$this->assertSame(5, $data[0]['minMonthlyAmountOverride']);
		$this->assertArrayNotHasKey('overrideReason', $data[0]);
	}

	public function testPreviewAssignmentDelegiertMitCentUmrechnung(): void {
		$this->contributions->expects($this->once())->method('preview')
			->with(1, 1550, 12)
			->willReturn(['effectiveFrom' => '2026-07-01']);

		$response = $this->controller()->previewAssignment(1, 15.5, 12);

		$this->assertSame(['effectiveFrom' => '2026-07-01'], $response->getData());
	}

	public function testPreviewAssignmentFremderZuweisungLiefert404(): void {
		$this->contributions->method('preview')->willThrowException(new DoesNotExistException('weg'));

		$response = $this->controller()->previewAssignment(999, 15.0, null);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testPreviewAssignmentUngueltigerWertLiefert400(): void {
		$this->contributions->method('preview')->willThrowException(new \InvalidArgumentException('zu niedrig'));

		$response = $this->controller()->previewAssignment(1, 1.0, null);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateAssignmentDelegiertMitCentUmrechnung(): void {
		$this->contributions->expects($this->once())->method('apply')
			->with(1, 1550, null)
			->willReturn(['assignment' => $this->assignment(amountCents: 1550), 'preview' => ['effectiveFrom' => '2026-06-15']]);

		$response = $this->controller()->updateAssignment(1, 15.5, null);
		$data = $response->getData();

		$this->assertSame(1550, $data['assignment']['monthlyAmountCents']);
		$this->assertSame('2026-06-15', $data['preview']['effectiveFrom']);
	}

	public function testUpdateAssignmentFremderZuweisungLiefert404(): void {
		$this->contributions->method('apply')->willThrowException(new DoesNotExistException('weg'));

		$response = $this->controller()->updateAssignment(999, 15.0, null);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// --- Kontaktdatenpflege (Issue #76): duenne HTTP-Huelle um SelfContactService ---

	public function testUpdateMeReichtNurGesetzteFelderDurch(): void {
		$this->contact->expects($this->once())->method('update')
			->with(['phone' => '+49 30 999'])
			->willReturn($this->fullMember());

		$this->controller()->updateMe(phone: '+49 30 999');
	}

	public function testUpdateMeLehntUngueltigeEingabeAbAls400(): void {
		$this->contact->method('update')->willThrowException(new \InvalidArgumentException('ungültig'));

		$response = $this->controller()->updateMe(email: 'keine-email');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// --- Beitragsbestätigung (Issue #77): duenne HTTP-Huelle um BeitragsbescheinigungRenderer ---

	public function testCertificateYearsDelegiertMitAufgeloesterMemberId(): void {
		$this->actorContext->setMemberChannel(self::MEMBER_ID);
		$this->certificateRenderer->expects($this->once())
			->method('selectableYears')
			->with(self::MEMBER_ID)
			->willReturn([2026, 2025]);

		$data = $this->controller()->certificateYears()->getData();

		$this->assertSame(['years' => [2026, 2025]], $data);
	}

	/**
	 * IDOR-Kern (siehe Klassendoc): certificate() nimmt selbst KEINEN
	 * memberId-Parameter entgegen (siehe Signatur) - der Renderer wird
	 * ausschließlich mit der server-aufgelösten eigenen member_id
	 * aufgerufen.
	 */
	public function testCertificateRendertMitAufgeloesterMemberIdUndDruckfertigerAntwort(): void {
		$this->actorContext->setMemberChannel(self::MEMBER_ID);
		$this->certificateRenderer->expects($this->once())
			->method('render')
			->with(self::MEMBER_ID, 2025)
			->willReturn('<html>Bestätigung</html>');

		$response = $this->controller()->certificate(2025);

		$this->assertInstanceOf(DataDisplayResponse::class, $response);
		$this->assertSame('<html>Bestätigung</html>', $response->getData());
	}

	public function testCertificateOhneAufgeloesteMemberIdWirdVerweigert(): void {
		$this->certificateRenderer->expects($this->never())->method('render');

		$this->expectException(ForbiddenException::class);
		$this->controller()->certificate();
	}

	public function testCertificateUnbekanntesMitgliedLiefert404(): void {
		$this->actorContext->setMemberChannel(self::FOREIGN_MEMBER_ID);
		$this->certificateRenderer->method('render')->willThrowException(new DoesNotExistException('weg'));

		$response = $this->controller()->certificate();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}
}
