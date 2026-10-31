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
use OCA\Vereinsbuchhaltung\Service\ClubMailBranding;
use OCA\Vereinsbuchhaltung\Service\MandateActivationService;
use OCA\Vereinsbuchhaltung\Service\MandateLegalTextService;
use OCA\Vereinsbuchhaltung\Service\MandateService;
use OCA\Vereinsbuchhaltung\Service\MandateStateMachine;
use OCA\Vereinsbuchhaltung\Service\RecipientL10n;
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
		// Die Sprache des Empfängers ist hier immer die des Mocks: Du/Sie und Sprache prüft RecipientL10nTest.
		$recipient = $this->createMock(RecipientL10n::class);
		$recipient->method('forMember')->willReturn($l10n);
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
			$recipient,
			$this->createMock(ClubMailBranding::class),
			new MandateStateMachine($l10n),
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
		$t->setPurpose($overrides['purpose'] ?? MandateActivationToken::PURPOSE_ACTIVATION);
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

	// --- Erneute Bestätigung nach einer neuen Fassung des Mandatstexts ---------------

	private function activeMandate(int $id, int $memberId = 42, ?int $textVersion = null, ?string $consentAt = null): Mandate {
		$m = new Mandate();
		$m->setId($id);
		$m->setMemberId($memberId);
		$m->setMandateReference('M-' . $id);
		$m->setAccountHolder('Katrin Brunner');
		$m->setSignatureType(Mandate::SIGNATURE_ELECTRONIC);
		$m->setStatus(Mandate::STATUS_ACTIVE);
		$m->setMandateTextVersion($textVersion);
		$m->setConsentAt($consentAt);
		return $m;
	}

	private function mailWillBeSent(array $sendResult = []): void {
		$this->random->method('generate')->willReturn('x');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.org/link');
		$this->mailer->method('createEMailTemplate')->willReturn($this->createMock(\OCP\Mail\IEMailTemplate::class));
		$this->mailer->method('createMessage')->willReturn($this->createMock(\OCP\Mail\IMessage::class));
		$this->mailer->method('send')->willReturn($sendResult);
	}

	public function testIssueReconfirmationLinkVerschicktEinenLinkMitZweckBestaetigung(): void {
		$mandate = $this->activeMandate(1);
		$this->mandateMapper->method('find')->with(1)->willReturn($mandate);
		$this->memberMapper->method('find')->willReturn($this->member());
		$this->mailWillBeSent();
		$captured = null;
		$this->tokenMapper->expects($this->once())->method('deleteOutstandingByMandate')->with(1);
		$this->tokenMapper->method('insert')->willReturnCallback(function (MandateActivationToken $t) use (&$captured): MandateActivationToken {
			$captured = $t;
			return $t;
		});
		$this->mandateService->expects($this->once())->method('logReconfirmationLinkSent')->with($mandate, 'katrin@example.org', 'staff');

		$result = $this->service()->issueReconfirmationLink(1, 'kassenwart');

		$this->assertSame('katrin@example.org', $result['email']);
		$this->assertSame(MandateActivationToken::PURPOSE_RECONFIRMATION, $captured->getPurpose());
	}

	public function testIssueReconfirmationLinkAufEntwurfSchlaegtFehl(): void {
		$this->mandateMapper->method('find')->willReturn($this->electronicDraft());
		$this->tokenMapper->expects($this->never())->method('insert');

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->issueReconfirmationLink(1, 'kassenwart');
	}

	public function testIssueReconfirmationLinkOhneMailadresseSchlaegtFehl(): void {
		$this->mandateMapper->method('find')->willReturn($this->activeMandate(1));
		$this->memberMapper->method('find')->willReturn($this->member(email: null, ncUserId: null));
		$this->tokenMapper->expects($this->never())->method('insert');

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->issueReconfirmationLink(1, 'kassenwart');
	}

	public function testIssueReconfirmationLinkLoeschtDenLinkWennDieMailNichtZugestelltWurde(): void {
		$this->mandateMapper->method('find')->willReturn($this->activeMandate(1));
		$this->memberMapper->method('find')->willReturn($this->member());
		$this->mailWillBeSent(['katrin@example.org']);
		$this->tokenMapper->method('insert')->willReturnArgument(0);
		$this->tokenMapper->expects($this->once())->method('delete');
		$this->mandateService->expects($this->never())->method('logReconfirmationLinkSent');

		$this->expectException(\RuntimeException::class);
		$this->service()->issueReconfirmationLink(1, 'kassenwart');
	}

	public function testReconfirmationOverviewSortiertNachBestaetigtAnschreibbarUndOhneMail(): void {
		$this->legalTextService->method('current')->willReturn($this->legalTextVersion(3));
		$ended = $this->activeMandate(6, 46, 1);
		$ended->setStatus(Mandate::STATUS_ENDED);
		$suspended = $this->activeMandate(7, 47, 2);
		$suspended->setStatus(Mandate::STATUS_SUSPENDED);
		$this->mandateMapper->method('findAll')->willReturn([
			$this->activeMandate(1, 41, 3, '2026-10-01 10:00:00'),   // aktuelle Fassung bestätigt
			$this->activeMandate(2, 42, 2, '2026-01-01 10:00:00'),   // ältere Fassung, anschreibbar
			$this->activeMandate(3, 43, 2, '2026-01-01 10:00:00'),   // ältere Fassung, Link gilt noch
			$this->activeMandate(4, 44, 2, '2026-01-01 10:00:00'),   // ältere Fassung, keine Mailadresse
			$this->activeMandate(5, 45),                              // ohne elektronische Zustimmung
			$ended,                                                   // erloschen: nicht dabei
			$suspended,                                               // ausgesetzt zählt mit
		]);
		$this->memberMapper->method('findAllById')->willReturn([
			41 => $this->member(41), 42 => $this->member(42), 43 => $this->member(43),
			44 => $this->member(44, email: null), 45 => $this->member(45), 46 => $this->member(46), 47 => $this->member(47),
		]);
		$this->tokenMapper->method('findOutstandingByPurpose')->with(MandateActivationToken::PURPOSE_RECONFIRMATION)->willReturn([
			3 => $this->token(['mandateId' => 3, 'purpose' => MandateActivationToken::PURPOSE_RECONFIRMATION]),
		]);

		$overview = $this->service()->reconfirmationOverview();

		$this->assertSame(1, $overview['upToDate']);
		$this->assertSame(5, $overview['outdated']);
		$this->assertSame(2, $overview['withoutElectronicConsent'], 'Mandat 5 und das ausgesetzte Mandat 7 tragen keine elektronische Zustimmung');
		$this->assertSame([2, 5, 7], $overview['readyIds']);
		$this->assertSame([3], $overview['pendingIds']);
		$this->assertSame([['mandateId' => 4, 'memberId' => 44, 'name' => 'Katrin Brunner']], $overview['noEmail']);
	}

	public function testReconfirmationOverviewBehandeltAbgelaufenenLinkWieKeinen(): void {
		$this->legalTextService->method('current')->willReturn($this->legalTextVersion(3));
		$this->mandateMapper->method('findAll')->willReturn([$this->activeMandate(3, 43, 2)]);
		$this->memberMapper->method('findAllById')->willReturn([43 => $this->member(43)]);
		$this->tokenMapper->method('findOutstandingByPurpose')->willReturn([
			3 => $this->token(['mandateId' => 3, 'expiresAt' => '2020-01-01 00:00:00']),
		]);

		$overview = $this->service()->reconfirmationOverview();

		$this->assertSame([3], $overview['readyIds']);
		$this->assertSame([], $overview['pendingIds']);
	}

	public function testSendReconfirmationLinksSchreibtNurAnUndZaehltDenRest(): void {
		$this->legalTextService->method('current')->willReturn($this->legalTextVersion(3));
		$ended = $this->activeMandate(4, 44, 1);
		$ended->setStatus(Mandate::STATUS_ENDED);
		$byId = [
			1 => $this->activeMandate(1, 41, 2),  // wird angeschrieben
			2 => $this->activeMandate(2, 42, 3),  // inzwischen bestätigt
			3 => $this->activeMandate(3, 43, 2),  // keine Mailadresse
			4 => $ended,                           // nicht mehr wirksam
		];
		$this->mandateMapper->method('findOrNull')->willReturnCallback(static fn (int $id) => $byId[$id] ?? null);
		$this->mandateMapper->method('find')->willReturnCallback(static fn (int $id) => $byId[$id]);
		$members = [41 => $this->member(41), 43 => $this->member(43, email: null)];
		$this->memberMapper->method('findOrNull')->willReturnCallback(static fn (int $id) => $members[$id] ?? null);
		$this->memberMapper->method('find')->willReturnCallback(static fn (int $id) => $members[$id]);
		$this->mailWillBeSent();
		$this->tokenMapper->method('insert')->willReturnArgument(0);

		$result = $this->service()->sendReconfirmationLinks([1, 2, 3, 4, 99, 1], 'kassenwart');

		$this->assertSame(1, $result['sent']);
		$this->assertSame(1, $result['alreadyCurrent']);
		$this->assertSame(2, $result['skipped']);
		$this->assertSame(['Katrin Brunner'], $result['noEmail']);
		$this->assertSame([], $result['failed']);
	}

	public function testSendReconfirmationLinksBrichtBeiEinemFehlerNichtAb(): void {
		$this->legalTextService->method('current')->willReturn($this->legalTextVersion(3));
		$byId = [1 => $this->activeMandate(1, 41, 2), 2 => $this->activeMandate(2, 42, 2)];
		$this->mandateMapper->method('findOrNull')->willReturnCallback(static fn (int $id) => $byId[$id]);
		$this->mandateMapper->method('find')->willReturnCallback(static fn (int $id) => $byId[$id]);
		$members = [41 => $this->member(41), 42 => $this->member(42)];
		$this->memberMapper->method('findOrNull')->willReturnCallback(static fn (int $id) => $members[$id]);
		$this->memberMapper->method('find')->willReturnCallback(static fn (int $id) => $members[$id]);
		$this->random->method('generate')->willReturn('x');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.org/link');
		$this->mailer->method('createEMailTemplate')->willReturn($this->createMock(\OCP\Mail\IEMailTemplate::class));
		$this->mailer->method('createMessage')->willReturn($this->createMock(\OCP\Mail\IMessage::class));
		$this->mailer->method('send')->willReturnOnConsecutiveCalls($this->throwException(new \RuntimeException('SMTP down')), []);
		$this->tokenMapper->method('insert')->willReturnArgument(0);

		$result = $this->service()->sendReconfirmationLinks([1, 2], 'kassenwart');

		$this->assertSame(1, $result['sent']);
		$this->assertSame([['name' => 'Katrin Brunner', 'reason' => 'SMTP down']], $result['failed']);
	}

	public function testConsentMitBestaetigungslinkBestaetigtDasBestehendeMandat(): void {
		$token = $this->token(['purpose' => MandateActivationToken::PURPOSE_RECONFIRMATION, 'legalTextVersionId' => 3, 'email' => 'katrin@example.org']);
		$this->tokenMapper->method('findBySelector')->willReturn($token);
		$this->mandateService->expects($this->never())->method('activateElectronic');
		$this->mandateService->expects($this->once())
			->method('reconfirmElectronic')
			->with(1, 3, $this->isType('string'), '198.51.100.7', 'Mozilla/5.0', 'katrin@example.org')
			->willReturn($this->activeMandate(1));
		$this->tokenMapper->expects($this->once())->method('update')->willReturnCallback(function (MandateActivationToken $t): MandateActivationToken {
			$this->assertNotNull($t->getConsumedAt());
			return $t;
		});

		$result = $this->service()->consent('selector123.validator123', '198.51.100.7', 'Mozilla/5.0');

		$this->assertSame(Mandate::STATUS_ACTIVE, $result->getStatus());
	}

	public function testViewEinesBestaetigungslinksAufBeendetemMandatIstUngueltig(): void {
		$token = $this->token(['purpose' => MandateActivationToken::PURPOSE_RECONFIRMATION]);
		$this->tokenMapper->method('findBySelector')->willReturn($token);
		$ended = $this->activeMandate(1);
		$ended->setStatus(Mandate::STATUS_ENDED);
		$this->mandateMapper->method('find')->willReturn($ended);

		$this->expectException(InvalidActivationTokenException::class);
		$this->service()->view('selector123.validator123');
	}

	public function testViewEinesBestaetigungslinksZeigtAktivesMandatMitDerAktuellenFassung(): void {
		$token = $this->token(['purpose' => MandateActivationToken::PURPOSE_RECONFIRMATION]);
		$this->tokenMapper->method('findBySelector')->willReturn($token);
		$this->tokenMapper->method('update')->willReturnArgument(0);
		$this->mandateMapper->method('find')->willReturn($this->activeMandate(1, 42, 2));
		$this->legalTextService->method('current')->willReturn($this->legalTextVersion(3));

		$view = $this->service()->view('selector123.validator123');

		$this->assertSame('pending', $view['status']);
		$this->assertSame(3, $view['token']->getLegalTextVersionId());
		$this->assertTrue($view['token']->isReconfirmation());
	}

	// --- Link-Status für die Akte (Issue #100) -----------------------------------------

	public function testLinkStatusMeldetGesendetAmGueltigBisUndNichtAbgelaufen(): void {
		$token = $this->token([
			'mandateId' => 1,
			'createdAt' => '2026-01-10 08:30:00',
			'expiresAt' => (new \DateTime())->modify('+5 days')->format('Y-m-d H:i:s'),
		]);
		$token->setRequestedBy('kassenwart');
		$this->tokenMapper->method('findOutstandingByMandate')->with(1)->willReturn([$token]);

		$status = $this->service()->linkStatus(1);

		$this->assertSame('2026-01-10 08:30:00', $status['sentAt']);
		$this->assertSame('katrin@example.org', $status['email']);
		$this->assertSame('kassenwart', $status['requestedBy']);
		$this->assertFalse($status['expired']);
		$this->assertNull($status['firstViewedAt']);
	}

	public function testLinkStatusErkenntAbgelaufenenLink(): void {
		$token = $this->token(['mandateId' => 1, 'createdAt' => '2020-01-01 00:00:00', 'expiresAt' => '2020-01-15 00:00:00']);
		$this->tokenMapper->method('findOutstandingByMandate')->with(1)->willReturn([$token]);

		$this->assertTrue($this->service()->linkStatus(1)['expired']);
	}

	/** Nach einem Neuversand bleibt nur der jüngste Link übrig – zählt im Zweifel der letzte der (älteste-zuerst) sortierten Liste. */
	public function testLinkStatusNimmtDenJuengstenAusstehendenLink(): void {
		$alt = $this->token(['id' => 1, 'mandateId' => 1, 'createdAt' => '2026-01-01 00:00:00']);
		$neu = $this->token(['id' => 2, 'mandateId' => 1, 'createdAt' => '2026-01-12 00:00:00']);
		$this->tokenMapper->method('findOutstandingByMandate')->with(1)->willReturn([$alt, $neu]);

		$this->assertSame('2026-01-12 00:00:00', $this->service()->linkStatus(1)['sentAt']);
	}

	public function testLinkStatusIstNullOhneAusstehendenLink(): void {
		$this->tokenMapper->method('findOutstandingByMandate')->with(1)->willReturn([]);

		$this->assertNull($this->service()->linkStatus(1));
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
