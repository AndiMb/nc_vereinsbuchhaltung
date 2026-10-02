<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateActivationToken;
use OCA\Vereinsbuchhaltung\Db\MandateActivationTokenMapper;
use OCA\Vereinsbuchhaltung\Db\MandateLegalTextVersion;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Exception\ConsumedActivationTokenException;
use OCA\Vereinsbuchhaltung\Exception\ExpiredActivationTokenException;
use OCA\Vereinsbuchhaltung\Exception\InvalidActivationTokenException;
use OCA\Vereinsbuchhaltung\Service\MandateActivationService;
use OCA\Vereinsbuchhaltung\Service\MandateLegalTextService;
use OCA\Vereinsbuchhaltung\Service\MandateService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Elektronische Mandatserteilung per E-Mail-Einmal-Link (Spec §2.2/§8, Issue
 * #67): Versand, Token-Gültigkeit (Selector/Validator, Ablauf, Einmal-
 * Verwendung), Fixierung des Rechtstexts bei der ersten Anzeige, das
 * Beweispaket bei Zustimmung, und die abgeleitete "Link ist alt"-Aufgabe.
 */
class MandateActivationServiceTest extends TestCase {

	private MandateActivationTokenMapper&MockObject $tokenMapper;
	private MandateMapper&MockObject $mandateMapper;
	private MemberMapper&MockObject $memberMapper;
	private MandateService&MockObject $mandateService;
	private MandateLegalTextService&MockObject $legalTextService;
	private ISecureRandom&MockObject $random;
	private IUserManager&MockObject $userManager;
	private IMailer&MockObject $mailer;
	private IURLGenerator&MockObject $urlGenerator;
	private IConfig&MockObject $config;

	protected function setUp(): void {
		$this->tokenMapper = $this->createMock(MandateActivationTokenMapper::class);
		$this->mandateMapper = $this->createMock(MandateMapper::class);
		$this->memberMapper = $this->createMock(MemberMapper::class);
		$this->mandateService = $this->createMock(MandateService::class);
		$this->legalTextService = $this->createMock(MandateLegalTextService::class);
		$this->random = $this->createMock(ISecureRandom::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->mailer = $this->createMock(IMailer::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(static fn (string $app, string $key, string $default = '') => $default);
	}

	private function service(): MandateActivationService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static function (string $text, array $params = []): string {
			return $params === [] ? $text : vsprintf(str_replace(['%1$s', '%2$s', '%3$d', '%d'], ['%s', '%s', '%s', '%s'], $text), $params);
		});
		return new MandateActivationService(
			$this->tokenMapper,
			$this->mandateMapper,
			$this->memberMapper,
			$this->mandateService,
			$this->legalTextService,
			$this->random,
			$this->userManager,
			$this->mailer,
			$this->urlGenerator,
			$this->config,
			$l10n,
		);
	}

	private function electronicDraft(int $id = 1, int $memberId = 42): Mandate {
		$m = new Mandate();
		$m->setId($id);
		$m->setMemberId($memberId);
		$m->setMandateReference('M-' . $id);
		$m->setAccountHolder('Katrin Brunner');
		$m->setSignatureType(Mandate::SIGNATURE_ELECTRONIC);
		$m->setStatus(Mandate::STATUS_DRAFT);
		return $m;
	}

	private function member(int $id = 42, ?string $email = 'katrin@example.org', ?string $ncUserId = null): Member {
		$m = new Member();
		$m->setId($id);
		$m->setMemberType(Member::TYPE_PERSON);
		$m->setFirstName('Katrin');
		$m->setLastName('Brunner');
		$m->setEmail($email);
		$m->setNcUserId($ncUserId);
		return $m;
	}

	private function legalTextVersion(int $id = 1): MandateLegalTextVersion {
		$v = new MandateLegalTextVersion();
		$v->setId($id);
		$v->setCreatedBy(MandateLegalTextVersion::CREATED_BY_SYSTEM);
		$v->setBody('SEPA-Lastschriftmandat …');
		$v->setCreatedAt('2026-01-01 00:00:00');
		return $v;
	}

	private function token(array $overrides = []): MandateActivationToken {
		$t = new MandateActivationToken();
		$t->setId($overrides['id'] ?? 99);
		$t->setMandateId($overrides['mandateId'] ?? 1);
		$t->setSelector($overrides['selector'] ?? 'selector123');
		$t->setValidatorHash($overrides['validatorHash'] ?? hash('sha256', 'validator123'));
		$t->setEmail($overrides['email'] ?? 'katrin@example.org');
		$t->setLegalTextVersionId($overrides['legalTextVersionId'] ?? null);
		// Relativ zu "jetzt", nicht fest verdrahtet: resolve() prüft den
		// Ablauf gegen die tatsächliche Systemzeit (new \DateTimeImmutable()),
		// ein festes Datum in der Vergangenheit würde diesen Fixture-Helfer
		// irgendwann unbemerkt "abgelaufen" machen.
		$t->setCreatedAt($overrides['createdAt'] ?? (new \DateTime())->format('Y-m-d H:i:s'));
		$t->setExpiresAt($overrides['expiresAt'] ?? (new \DateTime())->modify('+10 days')->format('Y-m-d H:i:s'));
		$t->setConsumedAt($overrides['consumedAt'] ?? null);
		return $t;
	}

	// --- Versand -------------------------------------------------------------------

	public function testIssueLinkVerschicktMailUndInvalidiertAlteLinks(): void {
		$mandate = $this->electronicDraft();
		$this->mandateMapper->method('find')->with(1)->willReturn($mandate);
		$this->memberMapper->method('find')->with(42)->willReturn($this->member());
		$this->random->method('generate')->willReturnOnConsecutiveCalls('SELECTORAAAAAAAA', 'VALIDATORBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example.org/apps/vereinsbuchhaltung/mandate-consent/SELECTORAAAAAAAA.VALIDATORBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB');

		$this->tokenMapper->expects($this->once())->method('deleteOutstandingByMandate')->with(1);
		$capturedToken = null;
		$this->tokenMapper->method('insert')->willReturnCallback(function (MandateActivationToken $t) use (&$capturedToken): MandateActivationToken {
			$t->setId(5);
			$capturedToken = $t;
			return $t;
		});
		$template = $this->createMock(\OCP\Mail\IEMailTemplate::class);
		$this->mailer->method('createEMailTemplate')->willReturn($template);
		$this->mailer->method('createMessage')->willReturn($this->createMock(\OCP\Mail\IMessage::class));
		$this->mailer->expects($this->once())->method('send')->willReturn([]);
		$this->mandateService->expects($this->once())->method('logActivationLinkSent')->with($mandate, 'katrin@example.org', 'staff');

		$result = $this->service()->issueLink(1, 'kassenwart');

		$this->assertSame('katrin@example.org', $result['email']);
		$this->assertStringContainsString('SELECTORAAAAAAAA.VALIDATORBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB', $result['url']);
		$this->assertNotNull($capturedToken);
		$this->assertSame('SELECTORAAAAAAAA', $capturedToken->getSelector());
		$this->assertSame(hash('sha256', 'VALIDATORBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB'), $capturedToken->getValidatorHash());
		$this->assertNotEmpty($capturedToken->getExpiresAt());
	}

	public function testIssueLinkSelbstbedienungProtokolliertActorMember(): void {
		$mandate = $this->electronicDraft();
		$this->mandateMapper->method('find')->willReturn($mandate);
		$this->memberMapper->method('find')->willReturn($this->member());
		$this->random->method('generate')->willReturn('x');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.org/link');
		$this->mailer->method('createEMailTemplate')->willReturn($this->createMock(\OCP\Mail\IEMailTemplate::class));
		$this->mailer->method('createMessage')->willReturn($this->createMock(\OCP\Mail\IMessage::class));
		$this->mailer->method('send')->willReturn([]);

		$this->mandateService->expects($this->once())->method('logActivationLinkSent')->with($mandate, 'katrin@example.org', 'member');

		$this->service()->issueLink(1, null);
	}

	public function testIssueLinkAufPapierMandatSchlaegtFehl(): void {
		$mandate = $this->electronicDraft();
		$mandate->setSignatureType(Mandate::SIGNATURE_PAPER);
		$this->mandateMapper->method('find')->willReturn($mandate);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->issueLink(1, 'kassenwart');
	}

	public function testIssueLinkAufBereitsAktivemMandatSchlaegtFehl(): void {
		$mandate = $this->electronicDraft();
		$mandate->setStatus(Mandate::STATUS_ACTIVE);
		$this->mandateMapper->method('find')->willReturn($mandate);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->issueLink(1, 'kassenwart');
	}

	public function testIssueLinkOhneMailadresseSchlaegtFehl(): void {
		$this->mandateMapper->method('find')->willReturn($this->electronicDraft());
		$this->memberMapper->method('find')->willReturn($this->member(email: null, ncUserId: null));

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->issueLink(1, 'kassenwart');
	}

	public function testIssueLinkFaelltAufNcKontoMailZurueckWennKeineMitgliedsMailGesetztIst(): void {
		$this->mandateMapper->method('find')->willReturn($this->electronicDraft());
		$this->memberMapper->method('find')->willReturn($this->member(email: null, ncUserId: 'katrin.b'));
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getEMailAddress')->willReturn('katrin@nc.example.org');
		$this->userManager->method('get')->with('katrin.b')->willReturn($user);
		$this->random->method('generate')->willReturn('x');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.org/link');
		$this->mailer->method('createEMailTemplate')->willReturn($this->createMock(\OCP\Mail\IEMailTemplate::class));
		$this->mailer->method('createMessage')->willReturn($this->createMock(\OCP\Mail\IMessage::class));
		$this->mailer->method('send')->willReturn([]);

		$result = $this->service()->issueLink(1, 'kassenwart');

		$this->assertSame('katrin@nc.example.org', $result['email']);
	}

	// --- Anzeige / Token-Gültigkeit --------------------------------------------------

	public function testViewFixiertDieRechtstextversionBeimErstenAufruf(): void {
		$token = $this->token(['legalTextVersionId' => null]);
		$this->tokenMapper->method('findBySelector')->with('selector123')->willReturn($token);
		$this->mandateMapper->method('find')->willReturn($this->electronicDraft());
		$this->legalTextService->method('current')->willReturn($this->legalTextVersion(7));
		$this->tokenMapper->expects($this->once())->method('update')->willReturnCallback(function (MandateActivationToken $t): MandateActivationToken {
			$this->assertSame(7, $t->getLegalTextVersionId());
			$this->assertNotNull($t->getFirstViewedAt());
			return $t;
		});

		$result = $this->service()->view('selector123.validator123');

		$this->assertSame('pending', $result['status']);
		$this->assertSame(7, $result['legalText']->getId());
	}

	public function testViewBenutztBeiZweitemAufrufDieBereitsFixierteVersion(): void {
		$token = $this->token(['legalTextVersionId' => 3]);
		$this->tokenMapper->method('findBySelector')->willReturn($token);
		$this->mandateMapper->method('find')->willReturn($this->electronicDraft());
		$this->legalTextService->expects($this->never())->method('current');
		$this->legalTextService->method('find')->with(3)->willReturn($this->legalTextVersion(3));
		$this->tokenMapper->expects($this->never())->method('update');

		$result = $this->service()->view('selector123.validator123');

		$this->assertSame(3, $result['legalText']->getId());
	}

	public function testViewMitUnbekanntemSelectorWirftInvalid(): void {
		$this->tokenMapper->method('findBySelector')->willReturn(null);

		$this->expectException(InvalidActivationTokenException::class);
		$this->service()->view('unbekannt.validator123');
	}

	public function testViewMitFalschemValidatorWirftInvalid(): void {
		$this->tokenMapper->method('findBySelector')->willReturn($this->token());

		$this->expectException(InvalidActivationTokenException::class);
		$this->service()->view('selector123.falscherValidator');
	}

	public function testViewOhnePunktImTokenWirftInvalid(): void {
		$this->expectException(InvalidActivationTokenException::class);
		$this->service()->view('keintrenner');
	}

	public function testViewMitAbgelaufenemTokenWirftExpired(): void {
		$token = $this->token(['expiresAt' => '2020-01-01 00:00:00']);
		$this->tokenMapper->method('findBySelector')->willReturn($token);

		$this->expectException(ExpiredActivationTokenException::class);
		$this->service()->view('selector123.validator123');
	}

	public function testViewMitBereitsVerbrauchtemTokenLiefertConsumedOhneFehler(): void {
		$token = $this->token(['consumedAt' => '2026-02-01 10:00:00', 'legalTextVersionId' => 3]);
		$this->tokenMapper->method('findBySelector')->willReturn($token);
		$mandate = $this->electronicDraft();
		$mandate->setStatus(Mandate::STATUS_ACTIVE);
		$mandate->setMandateTextVersion(3);
		$this->mandateMapper->method('find')->willReturn($mandate);
		$this->legalTextService->method('find')->with(3)->willReturn($this->legalTextVersion(3));
		$this->tokenMapper->expects($this->never())->method('update');

		$result = $this->service()->view('selector123.validator123');

		$this->assertSame('consumed', $result['status']);
	}

	// --- Zustimmung: das Beweispaket -------------------------------------------------

	public function testConsentAktiviertDasMandatMitVollstaendigemBeweispaket(): void {
		$token = $this->token(['legalTextVersionId' => 7, 'email' => 'katrin@example.org']);
		$this->tokenMapper->method('findBySelector')->willReturn($token);
		$activated = $this->electronicDraft();
		$activated->setStatus(Mandate::STATUS_ACTIVE);
		$this->mandateService->expects($this->once())
			->method('activateElectronic')
			->with(1, 7, $this->isType('string'), '198.51.100.7', 'Mozilla/5.0', 'katrin@example.org')
			->willReturn($activated);
		$this->tokenMapper->expects($this->once())->method('update')->willReturnCallback(function (MandateActivationToken $t): MandateActivationToken {
			$this->assertNotNull($t->getConsumedAt());
			return $t;
		});

		$result = $this->service()->consent('selector123.validator123', '198.51.100.7', 'Mozilla/5.0');

		$this->assertSame(Mandate::STATUS_ACTIVE, $result->getStatus());
	}

	public function testConsentAufBereitsVerbrauchtemTokenWirftConsumed(): void {
		$token = $this->token(['consumedAt' => '2026-02-01 10:00:00']);
		$this->tokenMapper->method('findBySelector')->willReturn($token);
		$this->mandateService->expects($this->never())->method('activateElectronic');

		$this->expectException(ConsumedActivationTokenException::class);
		$this->service()->consent('selector123.validator123', '198.51.100.7', 'Mozilla/5.0');
	}

	public function testConsentAufAbgelaufenemTokenWirftExpired(): void {
		$token = $this->token(['expiresAt' => '2020-01-01 00:00:00']);
		$this->tokenMapper->method('findBySelector')->willReturn($token);

		$this->expectException(ExpiredActivationTokenException::class);
		$this->service()->consent('selector123.validator123', '198.51.100.7', 'Mozilla/5.0');
	}

	public function testConsentOhneVorherigeAnzeigeFixiertDieVersionNachtraeglich(): void {
		$token = $this->token(['legalTextVersionId' => null]);
		$this->tokenMapper->method('findBySelector')->willReturn($token);
		$this->legalTextService->method('current')->willReturn($this->legalTextVersion(9));
		$this->mandateService->expects($this->once())
			->method('activateElectronic')
			->with(1, 9, $this->anything(), $this->anything(), $this->anything(), $this->anything())
			->willReturn($this->electronicDraft());

		$this->service()->consent('selector123.validator123', '198.51.100.7', 'Mozilla/5.0');
	}

	// --- Aufgabe "Link ist alt" (Issue #67) ------------------------------------------

	public function testFindStaleElectronicDraftTasksMeldetHinweisUnterhalbDerFrist(): void {
		$mandate = $this->electronicDraft(1);
		$token = $this->token(['mandateId' => 1, 'createdAt' => '2026-01-10 00:00:00']);
		$this->tokenMapper->method('findOldestOutstandingByElectronicDraftMandates')->willReturn([1 => $token]);
		$this->mandateMapper->method('findOrNull')->with(1)->willReturn($mandate);
		$this->memberMapper->method('findOrNull')->with(42)->willReturn($this->member());

		$tasks = $this->service()->findStaleElectronicDraftTasks('2026-01-15');

		$this->assertCount(1, $tasks);
		$this->assertSame('hinweis', $tasks[0]['severity']);
		$this->assertSame(1, $tasks[0]['objectId']);
	}

	public function testFindStaleElectronicDraftTasksMeldetHandlungsbedarfAbVierzehnTagen(): void {
		$mandate = $this->electronicDraft(1);
		$token = $this->token(['mandateId' => 1, 'createdAt' => '2026-01-01 00:00:00', 'expiresAt' => '2026-01-15 00:00:00']);
		$this->tokenMapper->method('findOldestOutstandingByElectronicDraftMandates')->willReturn([1 => $token]);
		$this->mandateMapper->method('findOrNull')->with(1)->willReturn($mandate);
		$this->memberMapper->method('findOrNull')->with(42)->willReturn($this->member());

		$tasks = $this->service()->findStaleElectronicDraftTasks('2026-01-16');

		$this->assertCount(1, $tasks);
		$this->assertSame('handlungsbedarf', $tasks[0]['severity']);
	}

	public function testFindStaleElectronicDraftTasksIstLeerOhneAusstehendeLinks(): void {
		$this->tokenMapper->method('findOldestOutstandingByElectronicDraftMandates')->willReturn([]);

		$this->assertSame([], $this->service()->findStaleElectronicDraftTasks());
	}
}
