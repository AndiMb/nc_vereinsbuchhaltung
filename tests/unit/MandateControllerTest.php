<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\MandateController;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateAmendment;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\MandateActivationService;
use OCA\Vereinsbuchhaltung\Service\MandateDocumentService;
use OCA\Vereinsbuchhaltung\Service\MandateExpiryCalculator;
use OCA\Vereinsbuchhaltung\Service\MandateFormRenderer;
use OCA\Vereinsbuchhaltung\Service\MandateLegalTextService;
use OCA\Vereinsbuchhaltung\Service\MandateService;
use OCA\Vereinsbuchhaltung\Service\OpenItemService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Die Zusatzfelder der Mitglieder-Akte (Issue #100): 36-Monats-Ablauf samt
 * Warnflag in jeder Mandatsantwort, Link-Status und offene Summe nur in der
 * Einzelansicht – dazu die IBAN-Maskierung nach tatsächlicher Rolle (Spec
 * §3.9). Services sind gemockt, der Ablaufrechner läuft echt (reine Rechnung).
 */
class MandateControllerTest extends TestCase {

	private MandateService&MockObject $service;
	private MandateActivationService&MockObject $activation;
	private OpenItemService&MockObject $openItems;
	private PermissionService&MockObject $permissions;

	protected function setUp(): void {
		$this->service = $this->createMock(MandateService::class);
		$this->activation = $this->createMock(MandateActivationService::class);
		$this->openItems = $this->createMock(OpenItemService::class);
		$this->permissions = $this->createMock(PermissionService::class);
		$this->permissions->method('canWrite')->willReturn(true);
	}

	private function controller(): MandateController {
		$documents = $this->createMock(MandateDocumentService::class);
		$documents->method('hasDocument')->willReturn(false);
		$documents->method('showMissingDocumentWarning')->willReturn(true);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new MandateController(
			$this->createMock(IRequest::class),
			$this->service,
			$documents,
			$this->activation,
			$this->createMock(MandateLegalTextService::class),
			$this->createMock(MandateFormRenderer::class),
			new MandateExpiryCalculator(),
			$this->openItems,
			$this->permissions,
			$this->createMock(IUserSession::class),
			$this->createMock(IConfig::class),
			$l10n,
		);
	}

	private function mandate(string $status, string $signatureType = Mandate::SIGNATURE_PAPER, ?string $signedAt = '2026-01-15'): Mandate {
		$m = new Mandate();
		$m->setId(7);
		$m->setMemberId(42);
		$m->setMandateReference('M-7');
		$m->setIban('DE12500105170648489890');
		$m->setAccountHolder('Katrin Brunner');
		$m->setSignatureType($signatureType);
		$m->setSignedAt($signedAt);
		$m->setStatus($status);
		return $m;
	}

	public function testAktivesMandatMeldetAblaufdatumNach36Monaten(): void {
		$this->service->method('findByMember')->with(42)->willReturn([$this->mandate(Mandate::STATUS_ACTIVE)]);

		$data = $this->controller()->byMember(42)->getData()[0];

		$this->assertSame('2029-01-15', $data['expiresAt']);
		$this->assertFalse($data['expiryWarning'], 'mehr als 180 Tage Restlaufzeit');
	}

	public function testEntwurfHatNochKeineLaufendeFrist(): void {
		$this->service->method('findByMember')->with(42)->willReturn([$this->mandate(Mandate::STATUS_DRAFT)]);

		$data = $this->controller()->byMember(42)->getData()[0];

		$this->assertNull($data['expiresAt']);
		$this->assertFalse($data['expiryWarning']);
	}

	public function testBaldAblaufendesMandatSetztDasWarnflag(): void {
		// Unterschrift vor knapp 35,5 Monaten: Ablauf in rund 15 Tagen, innerhalb der 180-Tage-Vorwarnung.
		$signed = (new \DateTimeImmutable('today'))->modify('-36 months')->modify('+15 days')->format('Y-m-d');
		$this->service->method('findByMember')->with(42)->willReturn([$this->mandate(Mandate::STATUS_ACTIVE, Mandate::SIGNATURE_PAPER, $signed)]);

		$data = $this->controller()->byMember(42)->getData()[0];

		$this->assertTrue($data['expiryWarning']);
	}

	public function testBeendetesMandatHatWederAblaufNochWarnung(): void {
		$this->service->method('findByMember')->with(42)->willReturn([$this->mandate(Mandate::STATUS_ENDED)]);

		$data = $this->controller()->byMember(42)->getData()[0];

		$this->assertNull($data['expiresAt']);
		$this->assertFalse($data['expiryWarning']);
	}

	public function testRevisorSiehtDieIbanNurMaskiert(): void {
		$permissions = $this->createMock(PermissionService::class);
		$permissions->method('canWrite')->willReturn(false);
		$this->permissions = $permissions;
		$this->service->method('findByMember')->with(42)->willReturn([$this->mandate(Mandate::STATUS_ACTIVE)]);

		$data = $this->controller()->byMember(42)->getData()[0];

		$this->assertSame('DE12••••••••••••••9890', $data['iban']);
	}

	private function amendmentWithOldIban(string $oldIban): MandateAmendment {
		$amendment = new MandateAmendment();
		$amendment->setId(3);
		$amendment->setMandateId(7);
		$amendment->setType(MandateAmendment::TYPE_ACCOUNT);
		$amendment->setOldIban($oldIban);
		$amendment->setOldAccountHolder('Katrin Brunner');
		$amendment->setStatus(MandateAmendment::STATUS_OPEN);
		return $amendment;
	}

	/**
	 * Issue #119: ein Amendment trägt die IBAN des ersetzten Kontos. Sie lag über
	 * die Einzelansicht (ab `revisor` lesbar) im Klartext vor, obwohl die IBAN
	 * des Mandats selbst maskiert wird (Spec §3.9).
	 */
	public function testRevisorSiehtDieAlteIbanEinesAmendmentsNurMaskiert(): void {
		$permissions = $this->createMock(PermissionService::class);
		$permissions->method('canWrite')->willReturn(false);
		$this->permissions = $permissions;
		$this->service->method('find')->with(7)->willReturn($this->mandate(Mandate::STATUS_ACTIVE));
		$this->service->method('history')->willReturn([]);
		$this->service->method('amendments')->willReturn([$this->amendmentWithOldIban('DE89370400440532013000')]);

		$data = $this->controller()->show(7)->getData();

		$this->assertSame('DE12••••••••••••••9890', $data['iban']);
		$this->assertSame('DE89••••••••••••••3000', $data['amendments'][0]['oldIban']);
		$this->assertStringNotContainsString('0440532013', json_encode($data, JSON_THROW_ON_ERROR), 'keine Ziffernfolge der alten IBAN in der Antwort');
	}

	public function testBuchhalterSiehtDieAlteIbanEinesAmendmentsImKlartext(): void {
		$this->service->method('find')->with(7)->willReturn($this->mandate(Mandate::STATUS_ACTIVE));
		$this->service->method('history')->willReturn([]);
		$this->service->method('amendments')->willReturn([$this->amendmentWithOldIban('DE89370400440532013000')]);

		$data = $this->controller()->show(7)->getData();

		$this->assertSame('DE89370400440532013000', $data['amendments'][0]['oldIban']);
		$this->assertSame('Katrin Brunner', $data['amendments'][0]['oldAccountHolder']);
	}

	public function testEinzelansichtLiefertLinkStatusFuerElektronischenEntwurfUndOffeneSumme(): void {
		$draft = $this->mandate(Mandate::STATUS_DRAFT, Mandate::SIGNATURE_ELECTRONIC, null);
		$this->service->method('find')->with(7)->willReturn($draft);
		$this->service->method('history')->willReturn([]);
		$this->service->method('amendments')->willReturn([]);
		$status = ['sentAt' => '2026-01-10 08:30:00', 'expiresAt' => '2026-01-24 08:30:00', 'expired' => true, 'email' => 'k@example.org', 'requestedBy' => 'kassenwart', 'firstViewedAt' => null];
		$this->activation->expects($this->once())->method('linkStatus')->with(7)->willReturn($status);
		$this->openItems->method('openClaimsTotalCents')->with(42)->willReturn(12000);

		$data = $this->controller()->show(7)->getData();

		$this->assertSame($status, $data['activationLink']);
		$this->assertSame(12000, $data['openClaimsTotalCents']);
		$this->assertArrayHasKey('history', $data);
		$this->assertArrayHasKey('amendments', $data);
	}

	public function testEinzelansichtFragtLinkStatusNurFuerElektronischeEntwuerfeAb(): void {
		$this->service->method('find')->with(7)->willReturn($this->mandate(Mandate::STATUS_DRAFT));
		$this->service->method('history')->willReturn([]);
		$this->service->method('amendments')->willReturn([]);
		$this->activation->expects($this->never())->method('linkStatus');

		$data = $this->controller()->show(7)->getData();

		$this->assertNull($data['activationLink']);
	}

	public function testListenAntwortEnthaeltWederLinkStatusNochOffeneSumme(): void {
		$this->service->method('findByMember')->with(42)->willReturn([$this->mandate(Mandate::STATUS_ACTIVE)]);
		$this->activation->expects($this->never())->method('linkStatus');
		$this->openItems->expects($this->never())->method('openClaimsTotalCents');

		$data = $this->controller()->byMember(42)->getData()[0];

		$this->assertArrayNotHasKey('activationLink', $data);
		$this->assertArrayNotHasKey('openClaimsTotalCents', $data);
	}

	public function testEntsperrenReichtDiePflichtNotizAnDenDienstDurch(): void {
		$this->service->expects($this->once())->method('resume')->with(7, 'Konto bestätigt')->willReturn($this->mandate(Mandate::STATUS_ACTIVE));

		$response = $this->controller()->resume(7, 'Konto bestätigt');

		$this->assertSame(200, $response->getStatus());
	}

	// --- Entwurf korrigieren/verwerfen (Issue #118) -----------------------------

	private function declaredRole(string $method): ?string {
		$attributes = (new \ReflectionMethod(MandateController::class, $method))->getAttributes(RequiresRole::class);
		return $attributes === [] ? null : $attributes[0]->newInstance()->role;
	}

	/** Spec §3.9: Verwaltung nur `buchhalter` - ausdrücklich per #[RequiresRole], nicht über die Verb-Heuristik. */
	public function testEntwurfKorrigierenUndVerwerfenVerlangenAusdruecklichDieBuchhalterRolle(): void {
		$this->assertSame(PermissionService::ROLE_WRITE, $this->declaredRole('correctDraft'));
		$this->assertSame(PermissionService::ROLE_WRITE, $this->declaredRole('discardDraft'));
	}

	public function testKorrekturReichtAlleDreiFelderAnDenDienstDurch(): void {
		$this->service->expects($this->once())->method('correctDraft')
			->with(7, 'DE89370400440532013000', 'COBADEFFXXX', 'Katrin Meier')
			->willReturn($this->mandate(Mandate::STATUS_DRAFT));

		$response = $this->controller()->correctDraft(7, 'DE89370400440532013000', 'COBADEFFXXX', 'Katrin Meier');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('entwurf', $response->getData()['status']);
	}

	public function testVerwerfenReichtDiePflichtNotizAnDenDienstDurchUndMeldetDasErloschene(): void {
		$ended = $this->mandate(Mandate::STATUS_ENDED);
		$ended->setEndReason(Mandate::END_REASON_DISCARDED);
		$this->service->expects($this->once())->method('discardDraft')->with(7, 'Tippfehler')->willReturn($ended);

		$data = $this->controller()->discardDraft(7, 'Tippfehler')->getData();

		$this->assertSame('erloschen', $data['status']);
		$this->assertSame('verworfen', $data['endReason']);
	}

	public function testVerwerfenUndKorrekturMeldenFehlerDesDienstesAls400Und404(): void {
		$this->service->method('discardDraft')->willThrowException(new \InvalidArgumentException('Zum Verwerfen eines Entwurfs ist eine Notiz Pflicht.'));
		$this->service->method('correctDraft')->willThrowException(new DoesNotExistException('weg'));

		$discard = $this->controller()->discardDraft(7, '');
		$correct = $this->controller()->correctDraft(7, 'DE89370400440532013000', null, 'Katrin Brunner');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $discard->getStatus());
		$this->assertSame('Zum Verwerfen eines Entwurfs ist eine Notiz Pflicht.', $discard->getData()['message']);
		$this->assertSame(Http::STATUS_NOT_FOUND, $correct->getStatus());
	}
}
