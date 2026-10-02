<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Activity\SelfServiceMandateRevocationSetting;
use OCA\Vereinsbuchhaltung\Activity\SelfServiceSetting;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Service\ActorContextService;
use OCA\Vereinsbuchhaltung\Service\MandateFormRenderer;
use OCA\Vereinsbuchhaltung\Service\MandateLegalTextService;
use OCA\Vereinsbuchhaltung\Service\MandateService;
use OCA\Vereinsbuchhaltung\Service\OpenItemService;
use OCA\Vereinsbuchhaltung\Service\SelfServiceMandateService;
use OCA\Vereinsbuchhaltung\Service\SelfServiceReceiptMailer;
use OCP\Activity\IEvent;
use OCP\Activity\IManager as IActivityManager;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Self-Service-Mandats-Aktionskatalog (Spec §3.4, Issue #75): Schwerpunkt
 * IDOR-Schutz (jede Aktion löst das betroffene Mandat SERVERSEITIG über die
 * member_id auf, nimmt nie eine Mandats-ID entgegen) und die drei
 * verkabelten Seiteneffekte (MandateService-Aufruf, Quittungsmail, Activity-
 * Eintrag mit dem richtigen Settings-Typ für den Widerruf-Sonderfall).
 */
class SelfServiceMandateServiceTest extends TestCase {

	private MandateService&MockObject $mandateService;
	private MemberMapper&MockObject $memberMapper;
	private OpenItemService&MockObject $openItemService;
	private SelfServiceReceiptMailer&MockObject $receiptMailer;
	private IActivityManager&MockObject $activityManager;
	private IEvent&MockObject $event;

	protected function setUp(): void {
		$this->mandateService = $this->createMock(MandateService::class);
		$this->memberMapper = $this->createMock(MemberMapper::class);
		$this->openItemService = $this->createMock(OpenItemService::class);
		$this->receiptMailer = $this->createMock(SelfServiceReceiptMailer::class);
		$this->activityManager = $this->createMock(IActivityManager::class);

		// IEvent hat eine fluent API (jeder Setter gibt $this zurueck) - siehe
		// SelfServiceMandateService::publishActivity().
		$this->event = $this->createMock(IEvent::class);
		foreach (['setApp', 'setType', 'setAffectedUser', 'setAuthor', 'setSubject', 'setParsedSubject', 'setObject'] as $method) {
			$this->event->method($method)->willReturnSelf();
		}
		$this->activityManager->method('generateEvent')->willReturn($this->event);
	}

	private function member(int $id = 42): Member {
		$member = new Member();
		$member->setId($id);
		$member->setMemberType(Member::TYPE_PERSON);
		$member->setFirstName('Katrin');
		$member->setLastName('Brunner');
		$member->setNcUserId('katrin.b');
		return $member;
	}

	private function mandate(int $id = 1, string $status = Mandate::STATUS_ACTIVE): Mandate {
		$m = new Mandate();
		$m->setId($id);
		$m->setMemberId(42);
		$m->setMandateReference('M-' . $id);
		$m->setIban('DE12500105170648489890');
		$m->setAccountHolder('Katrin Brunner');
		$m->setSignatureType(Mandate::SIGNATURE_ELECTRONIC);
		$m->setStatus($status);
		return $m;
	}

	private function service(): SelfServiceMandateService {
		$legalText = $this->createMock(MandateLegalTextService::class);
		$version = new \OCA\Vereinsbuchhaltung\Db\MandateLegalTextVersion();
		$version->setId(3);
		$legalText->method('current')->willReturn($version);

		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('203.0.113.5');
		$request->method('getHeader')->willReturn('TestBrowser/1.0');

		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('katrin.b');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$actorContext = new ActorContextService($userSession);
		$actorContext->setMemberChannel(42);

		return new SelfServiceMandateService(
			$this->mandateService,
			$legalText,
			$this->createMock(MandateFormRenderer::class),
			$this->memberMapper,
			$this->openItemService,
			$this->receiptMailer,
			$actorContext,
			$this->activityManager,
			$request,
			$this->createMock(IConfig::class),
			$this->l10n(),
		);
	}

	private function l10n(): IL10N&MockObject {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf(str_replace(['%s', '%1$s', '%2$s'], ['%s', '%1$s', '%2$s'], $text), $params));
		return $l10n;
	}

	// --- IDOR-Schutz: keine Mandats-ID nimmt je ein Aufrufer entgegen -----------

	public function testChangeIbanLoestMandatAusschliesslichUeberDieMemberIdAuf(): void {
		$this->memberMapper->method('find')->with(42)->willReturn($this->member());
		$this->mandateService->expects($this->once())->method('findLiveByMember')->with(42)->willReturn($this->mandate(7));
		$this->mandateService->expects($this->once())->method('amendBankDetails')->with(7, 'DE89370400440532013000', null)->willReturn($this->mandate(7));

		$this->service()->changeIban(42, 'DE89370400440532013000', null);
	}

	public function testChangeIbanOhneLebendesMandatWirftOhneMandateServiceAufzurufen(): void {
		$this->memberMapper->method('find')->willReturn($this->member());
		$this->mandateService->method('findLiveByMember')->willReturn(null);
		$this->mandateService->expects($this->never())->method('amendBankDetails');

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->changeIban(42, 'DE89370400440532013000', null);
	}

	public function testRevokeLoestMandatAusschliesslichUeberDieMemberIdAuf(): void {
		$this->memberMapper->method('find')->willReturn($this->member());
		$this->mandateService->expects($this->once())->method('findLiveByMember')->with(42)->willReturn($this->mandate(9));
		$this->mandateService->expects($this->once())->method('revoke')->with(9)->willReturn($this->mandate(9, Mandate::STATUS_ENDED));

		$this->service()->revoke(42);
	}

	public function testReplaceForNewHolderLoestAltesMandatAusschliesslichUeberDieMemberIdAuf(): void {
		$this->memberMapper->method('find')->willReturn($this->member());
		$this->mandateService->expects($this->once())->method('findLiveByMember')->with(42)->willReturn($this->mandate(11));
		$this->mandateService->expects($this->once())->method('replaceElectronicSelfService')
			->with(11, 'DE89370400440532013000', null, 'Neuer Kontoinhaber', 3, '203.0.113.5', 'TestBrowser/1.0', 'katrin.b')
			->willReturn($this->mandate(12));

		$result = $this->service()->replaceForNewHolder(42, 'DE89370400440532013000', null, 'Neuer Kontoinhaber');

		$this->assertSame(12, $result->getId());
	}

	public function testGrantUebergibtDieAktuelleRechtstextversionUndDieNcUidAlsConsentActor(): void {
		$this->memberMapper->method('find')->willReturn($this->member());
		$this->mandateService->expects($this->once())->method('grantElectronicSelfService')
			->with(42, 'DE89370400440532013000', null, null, 3, '203.0.113.5', 'TestBrowser/1.0', 'katrin.b')
			->willReturn($this->mandate(5));

		$result = $this->service()->grant(42, 'DE89370400440532013000', null, null);

		$this->assertSame(5, $result->getId());
	}

	// --- Seiteneffekte: Quittungsmail + Activity ---------------------------------

	public function testJedeAktionSchicktEineQuittungsmail(): void {
		$this->memberMapper->method('find')->willReturn($this->member());
		$this->mandateService->method('findLiveByMember')->willReturn($this->mandate(1));
		$this->mandateService->method('revoke')->willReturn($this->mandate(1, Mandate::STATUS_ENDED));
		$this->receiptMailer->expects($this->once())->method('send');

		$this->service()->revoke(42);
	}

	public function testWiderrufVeroeffentlichtEinenActivityEintragMitDemWiderrufSpezifischenTyp(): void {
		$this->memberMapper->method('find')->willReturn($this->member());
		$this->mandateService->method('findLiveByMember')->willReturn($this->mandate(1));
		$this->mandateService->method('revoke')->willReturn($this->mandate(1, Mandate::STATUS_ENDED));
		$this->event->expects($this->once())->method('setType')->with(SelfServiceMandateRevocationSetting::TYPE)->willReturnSelf();
		$this->activityManager->expects($this->once())->method('publish')->with($this->event);

		$this->service()->revoke(42);
	}

	public function testIbanAenderungVeroeffentlichtDenAllgemeinenSelfServiceTypNichtDenWiderrufTyp(): void {
		$this->memberMapper->method('find')->willReturn($this->member());
		$this->mandateService->method('findLiveByMember')->willReturn($this->mandate(1));
		$this->mandateService->method('amendBankDetails')->willReturn($this->mandate(1));
		$this->event->expects($this->once())->method('setType')->with(SelfServiceSetting::TYPE)->willReturnSelf();
		$this->activityManager->expects($this->once())->method('publish')->with($this->event);

		$this->service()->changeIban(42, 'DE89370400440532013000', null);
	}

	/** Ein Fehler in der Activity-Veröffentlichung darf die bereits vollzogene Aktion nicht rückwirkend scheitern lassen (siehe AuditService::log() für dasselbe Prinzip). */
	public function testFehlschlagendeActivityVeroeffentlichungWirftNicht(): void {
		$this->memberMapper->method('find')->willReturn($this->member());
		$this->mandateService->method('findLiveByMember')->willReturn($this->mandate(1));
		$this->mandateService->method('revoke')->willReturn($this->mandate(1, Mandate::STATUS_ENDED));
		$this->activityManager->method('publish')->willThrowException(new \RuntimeException('kaputt'));

		$result = $this->service()->revoke(42);

		$this->assertSame(Mandate::STATUS_ENDED, $result->getStatus());
	}
}
