<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Activity\SelfServiceProvider;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Exception\ForbiddenException;
use OCA\Vereinsbuchhaltung\Service\ActorContextService;
use OCA\Vereinsbuchhaltung\Service\MemberService;
use OCA\Vereinsbuchhaltung\Service\SelfContactService;
use OCA\Vereinsbuchhaltung\Service\SelfServiceActivityPublisher;
use OCA\Vereinsbuchhaltung\Service\SelfServiceReceiptMailService;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Self-Service-Kontaktdatenpflege (Spec §3.4 Aktionskatalog, Issue #76):
 * IDOR-Schutz (member_id nur über ActorContextService), Quittungsmail +
 * Activity-Feed, und der E-Mail-Wechsel-Sonderfall (Warnmail an die alte
 * Adresse).
 */
class SelfContactServiceTest extends TestCase {

	private const MEMBER_ID = 5;

	private MemberService&MockObject $memberService;
	private SelfServiceReceiptMailService&MockObject $receiptMail;
	private SelfServiceActivityPublisher&MockObject $activity;
	private IUserManager&MockObject $userManager;
	private ActorContextService $actorContext;

	protected function setUp(): void {
		$this->memberService = $this->createMock(MemberService::class);
		$this->receiptMail = $this->createMock(SelfServiceReceiptMailService::class);
		$this->activity = $this->createMock(SelfServiceActivityPublisher::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->actorContext = new ActorContextService($this->createMock(IUserSession::class));
	}

	private function service(): SelfContactService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		return new SelfContactService($this->actorContext, $this->memberService, $this->receiptMail, $this->activity, $this->userManager, $l10n);
	}

	private function member(?string $email = 'katrin@example.org', ?string $ncUserId = 'katrin.b'): Member {
		$member = new Member();
		$member->setId(self::MEMBER_ID);
		$member->setMemberType(Member::TYPE_PERSON);
		$member->setFirstName('Katrin');
		$member->setLastName('Brunner');
		$member->setEmail($email);
		$member->setNcUserId($ncUserId);
		return $member;
	}

	public function testOhneAufgeloesteMemberIdWirdVerweigert(): void {
		$this->memberService->expects($this->never())->method('updateOwnContactData');
		$this->expectException(ForbiddenException::class);
		$this->service()->update(['phone' => '+49 30 999']);
	}

	public function testUpdateDelegiertMitDerAufgeloestenMemberId(): void {
		$this->actorContext->setMemberChannel(self::MEMBER_ID);
		$this->memberService->expects($this->once())->method('updateOwnContactData')
			->with(self::MEMBER_ID, ['phone' => '+49 30 999'])
			->willReturn(['member' => $this->member(), 'emailChanged' => false, 'oldEmail' => null]);

		$this->service()->update(['phone' => '+49 30 999']);
	}

	public function testOhneEmailWechselNurEineQuittungKeineWarnung(): void {
		$this->actorContext->setMemberChannel(self::MEMBER_ID);
		$this->memberService->method('updateOwnContactData')
			->willReturn(['member' => $this->member(), 'emailChanged' => false, 'oldEmail' => null]);

		$this->activity->expects($this->once())->method('publish')
			->with('katrin.b', SelfServiceProvider::SUBJECT_CONTACT_UPDATED, [], 'member', self::MEMBER_ID);
		$this->receiptMail->expects($this->once())->method('sendReceipt')
			->with($this->isInstanceOf(Member::class), 'katrin@example.org', $this->anything(), $this->anything(), null, null);
		$this->receiptMail->expects($this->never())->method('sendOldAddressWarning');

		$this->service()->update(['phone' => '+49 30 999']);
	}

	public function testEmailWechselSendetQuittungAnNeueUndWarnungAnAlteAdresse(): void {
		$this->actorContext->setMemberChannel(self::MEMBER_ID);
		$updated = $this->member(email: 'neu@example.org');
		$this->memberService->method('updateOwnContactData')
			->willReturn(['member' => $updated, 'emailChanged' => true, 'oldEmail' => 'katrin@example.org']);

		$this->receiptMail->expects($this->once())->method('sendReceipt')
			->with($this->isInstanceOf(Member::class), 'neu@example.org', $this->anything(), $this->anything(), null, null);
		$this->receiptMail->expects($this->once())->method('sendOldAddressWarning')
			->with($this->isInstanceOf(Member::class), 'katrin@example.org', 'neu@example.org');

		$this->service()->update(['email' => 'neu@example.org']);
	}

	public function testOhneNcUserIdWirdKeineActivityVeroeffentlicht(): void {
		$this->actorContext->setMemberChannel(self::MEMBER_ID);
		$this->memberService->method('updateOwnContactData')
			->willReturn(['member' => $this->member(ncUserId: null), 'emailChanged' => false, 'oldEmail' => null]);

		$this->activity->expects($this->never())->method('publish');

		$this->service()->update(['phone' => '+49 30 999']);
	}
}
