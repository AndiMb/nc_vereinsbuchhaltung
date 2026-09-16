<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Activity\SelfServiceProvider;
use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Service\ActorContextService;
use OCA\Vereinsbuchhaltung\Service\AssignmentService;
use OCA\Vereinsbuchhaltung\Service\SelfContributionService;
use OCA\Vereinsbuchhaltung\Service\SelfServiceActivityPublisher;
use OCA\Vereinsbuchhaltung\Service\SelfServiceReceiptMailService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Self-Service-Hülle um {@see AssignmentService} (Spec §3.4, Issue #76): der
 * Fokus liegt auf IDOR-Schutz (jede Methode löst member_id ausschließlich
 * über {@see ActorContextService} auf) und den Benachrichtigungen
 * (Quittungsmail + Activity-Feed je tatsächlich geändertem Feld) - die
 * fachliche Validierung selbst ist bereits durch AssignmentServiceTest
 * abgedeckt und wird hier nur durchgereicht.
 */
class SelfContributionServiceTest extends TestCase {

	private const OWN_MEMBER_ID = 5;
	private const FOREIGN_MEMBER_ID = 999;
	private const ASSIGNMENT_ID = 42;

	private AssignmentService&MockObject $assignments;
	private MemberMapper&MockObject $members;
	private SelfServiceReceiptMailService&MockObject $receiptMail;
	private SelfServiceActivityPublisher&MockObject $activity;
	private IUserManager&MockObject $userManager;
	private ActorContextService $actorContext;

	protected function setUp(): void {
		$this->assignments = $this->createMock(AssignmentService::class);
		$this->members = $this->createMock(MemberMapper::class);
		$this->receiptMail = $this->createMock(SelfServiceReceiptMailService::class);
		$this->activity = $this->createMock(SelfServiceActivityPublisher::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$userSession = $this->createMock(IUserSession::class);
		$this->actorContext = new ActorContextService($userSession);
	}

	/**
	 * $today muss zum effectiveFrom von fixedPreview() passen (Default
	 * 2026-07-01) - sonst würde die neue Sperrfenster-Ablehnung
	 * (assertNotLocked()) bestehende "Erfolg"-Tests grundlos scheitern lassen.
	 */
	private function service(string $today = '2026-07-01'): SelfContributionService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime($today));
		return new SelfContributionService($this->actorContext, $this->assignments, $this->members, $this->receiptMail, $this->activity, $this->userManager, $time, $l10n);
	}

	private function ownAssignment(int $amountCents = 1000, int $intervalMonths = 1): Assignment {
		$a = new Assignment();
		$a->setId(self::ASSIGNMENT_ID);
		$a->setMemberId(self::OWN_MEMBER_ID);
		$a->setMonthlyAmountCents($amountCents);
		$a->setIntervalMonths($intervalMonths);
		return $a;
	}

	private function member(?string $ncUserId = 'katrin.b', ?string $email = 'katrin@example.org'): Member {
		$member = new Member();
		$member->setId(self::OWN_MEMBER_ID);
		$member->setMemberType(Member::TYPE_PERSON);
		$member->setFirstName('Katrin');
		$member->setLastName('Brunner');
		$member->setEmail($email);
		$member->setNcUserId($ncUserId);
		return $member;
	}

	private function fixedPreview(string $effectiveFrom = '2026-07-01', string $firstDueDate = '2026-07-01'): array {
		return ['effectiveFrom' => $effectiveFrom, 'periodStart' => $effectiveFrom, 'periodEnd' => '2026-12-31', 'firstDueDate' => $firstDueDate, 'amountCents' => 1500];
	}

	// --- IDOR-Schutz -----------------------------------------------------

	public function testFindOwnFiltertUeberDieAufgeloesteMemberId(): void {
		$this->actorContext->setMemberChannel(self::OWN_MEMBER_ID);
		$this->assignments->expects($this->once())->method('findByMember')->with(self::OWN_MEMBER_ID)->willReturn([]);

		$this->service()->findOwn();
	}

	public function testPreviewFremderZuweisungWirftDoesNotExistException(): void {
		$this->actorContext->setMemberChannel(self::OWN_MEMBER_ID);
		$foreign = new Assignment();
		$foreign->setId(self::ASSIGNMENT_ID);
		$foreign->setMemberId(self::FOREIGN_MEMBER_ID);
		$this->assignments->method('find')->with(self::ASSIGNMENT_ID)->willReturn($foreign);
		$this->assignments->expects($this->never())->method('previewChange');

		$this->expectException(DoesNotExistException::class);
		$this->service()->preview(self::ASSIGNMENT_ID, 1500, null);
	}

	public function testApplyFremderZuweisungWirftDoesNotExistExceptionUndSpeichertNichts(): void {
		$this->actorContext->setMemberChannel(self::OWN_MEMBER_ID);
		$foreign = new Assignment();
		$foreign->setId(self::ASSIGNMENT_ID);
		$foreign->setMemberId(self::FOREIGN_MEMBER_ID);
		$this->assignments->method('find')->with(self::ASSIGNMENT_ID)->willReturn($foreign);
		$this->assignments->expects($this->never())->method('update');

		$this->expectException(DoesNotExistException::class);
		$this->service()->apply(self::ASSIGNMENT_ID, 1500, null);
	}

	public function testPreviewEigenerZuweisungDelegiertAnAssignmentService(): void {
		$this->actorContext->setMemberChannel(self::OWN_MEMBER_ID);
		$this->assignments->method('find')->with(self::ASSIGNMENT_ID)->willReturn($this->ownAssignment());
		$this->assignments->expects($this->once())->method('previewChange')->with(self::ASSIGNMENT_ID, 1500, null)->willReturn($this->fixedPreview());

		$preview = $this->service()->preview(self::ASSIGNMENT_ID, 1500, null);

		$this->assertSame('2026-07-01', $preview['effectiveFrom']);
	}

	// --- Sperrfenster: "Änderung wird abgelehnt mit Erklärung" (GitHub-Akzeptanzkriterium #76) ---

	public function testPreviewLehntGesperrteAenderungMitErklaerungAb(): void {
		$this->actorContext->setMemberChannel(self::OWN_MEMBER_ID);
		$this->assignments->method('find')->willReturn($this->ownAssignment());
		// effectiveFrom (2026-07-01) liegt NACH "heute" (2026-06-15) - die
		// Vorabinfo hat also eine Periode gesperrt.
		$this->assignments->method('previewChange')->willReturn($this->fixedPreview(effectiveFrom: '2026-07-01'));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/2026-07-01/');
		$this->service('2026-06-15')->preview(self::ASSIGNMENT_ID, 1500, null);
	}

	public function testApplyLehntGesperrteAenderungAbUndSpeichertNichts(): void {
		$this->actorContext->setMemberChannel(self::OWN_MEMBER_ID);
		$this->assignments->method('find')->willReturn($this->ownAssignment());
		$this->assignments->method('previewChange')->willReturn($this->fixedPreview(effectiveFrom: '2026-07-01'));
		$this->assignments->expects($this->never())->method('update');
		$this->activity->expects($this->never())->method('publish');
		$this->receiptMail->expects($this->never())->method('sendReceipt');

		$this->expectException(\InvalidArgumentException::class);
		$this->service('2026-06-15')->apply(self::ASSIGNMENT_ID, 1500, null);
	}

	public function testApplyErlaubtAenderungWennSperreBereitsVerstrichenIst(): void {
		$this->actorContext->setMemberChannel(self::OWN_MEMBER_ID);
		$assignment = $this->ownAssignment(amountCents: 1000, intervalMonths: 1);
		$this->assignments->method('find')->willReturn($assignment);
		// effectiveFrom liegt NICHT nach heute (gleich) - keine aktive Sperre.
		$this->assignments->method('previewChange')->willReturn($this->fixedPreview(effectiveFrom: '2026-06-15'));
		$this->assignments->expects($this->once())->method('update')->willReturn($this->ownAssignment(amountCents: 1500, intervalMonths: 1));
		$this->members->method('find')->willReturn($this->member());

		$this->service('2026-06-15')->apply(self::ASSIGNMENT_ID, 1500, null);
	}

	// --- apply(): Benachrichtigungen ---------------------------------------

	public function testApplyBeiBetragsAenderungSendetQuittungUndActivity(): void {
		$this->actorContext->setMemberChannel(self::OWN_MEMBER_ID);
		$assignment = $this->ownAssignment(amountCents: 1000, intervalMonths: 1);
		$this->assignments->method('find')->willReturn($assignment);
		$this->assignments->method('previewChange')->willReturn($this->fixedPreview());
		$updated = $this->ownAssignment(amountCents: 1500, intervalMonths: 1);
		$this->assignments->expects($this->once())->method('update')
			->with(self::ASSIGNMENT_ID, 1500, null, null, ActorContextService::TYPE_MEMBER, $this->anything())
			->willReturn($updated);
		$this->members->method('find')->with(self::OWN_MEMBER_ID)->willReturn($this->member());

		$this->activity->expects($this->once())->method('publish')
			->with('katrin.b', SelfServiceProvider::SUBJECT_CONTRIBUTION_AMOUNT_CHANGED, ['from' => 1000, 'to' => 1500, 'effectiveFrom' => '2026-07-01'], 'assignment', self::ASSIGNMENT_ID);
		$this->receiptMail->expects($this->once())->method('sendReceipt')
			->with(
				$this->isInstanceOf(Member::class),
				'katrin@example.org',
				$this->anything(),
				$this->stringContains('10,00 € auf 15,00 €'),
				'2026-07-01',
				'2026-07-01',
			);

		$result = $this->service()->apply(self::ASSIGNMENT_ID, 1500, null);

		$this->assertSame(1500, $result['assignment']->getMonthlyAmountCents());
	}

	public function testApplyOhneTatsaechlicheAenderungSendetNichts(): void {
		$this->actorContext->setMemberChannel(self::OWN_MEMBER_ID);
		$assignment = $this->ownAssignment(amountCents: 1000, intervalMonths: 1);
		$this->assignments->method('find')->willReturn($assignment);
		$this->assignments->method('previewChange')->willReturn($this->fixedPreview());
		$this->assignments->method('update')->willReturn($assignment);
		$this->members->method('find')->willReturn($this->member());

		$this->activity->expects($this->never())->method('publish');
		$this->receiptMail->expects($this->never())->method('sendReceipt');

		// Derselbe Betrag wie vorher (1000) - keine tatsaechliche Aenderung.
		$this->service()->apply(self::ASSIGNMENT_ID, 1000, null);
	}

	public function testApplyBeiBetragUndTurnusSendetZweiActivityEintraegeUndEineMail(): void {
		$this->actorContext->setMemberChannel(self::OWN_MEMBER_ID);
		$assignment = $this->ownAssignment(amountCents: 1000, intervalMonths: 1);
		$this->assignments->method('find')->willReturn($assignment);
		$this->assignments->method('previewChange')->willReturn($this->fixedPreview());
		$this->assignments->method('update')->willReturn($this->ownAssignment(amountCents: 1500, intervalMonths: 12));
		$this->members->method('find')->willReturn($this->member());

		$this->activity->expects($this->exactly(2))->method('publish');
		$this->receiptMail->expects($this->once())->method('sendReceipt')
			->with($this->anything(), $this->anything(), $this->anything(), $this->logicalAnd(
				$this->stringContains('Monatsbeitrag'),
				$this->stringContains('Zahlungsturnus'),
			), $this->anything(), $this->anything());

		$this->service()->apply(self::ASSIGNMENT_ID, 1500, 12);
	}

	public function testApplyOhneNcUserIdSendetKeineActivityAberWennMoeglichDieMail(): void {
		$this->actorContext->setMemberChannel(self::OWN_MEMBER_ID);
		$assignment = $this->ownAssignment(amountCents: 1000, intervalMonths: 1);
		$this->assignments->method('find')->willReturn($assignment);
		$this->assignments->method('previewChange')->willReturn($this->fixedPreview());
		$this->assignments->method('update')->willReturn($this->ownAssignment(amountCents: 1500, intervalMonths: 1));
		$this->members->method('find')->willReturn($this->member(ncUserId: null));

		$this->activity->expects($this->never())->method('publish');
		$this->receiptMail->expects($this->once())->method('sendReceipt');

		$this->service()->apply(self::ASSIGNMENT_ID, 1500, null);
	}

	public function testApplyOhneJedeMailadresseSendetKeineMail(): void {
		$this->actorContext->setMemberChannel(self::OWN_MEMBER_ID);
		$assignment = $this->ownAssignment(amountCents: 1000, intervalMonths: 1);
		$this->assignments->method('find')->willReturn($assignment);
		$this->assignments->method('previewChange')->willReturn($this->fixedPreview());
		$this->assignments->method('update')->willReturn($this->ownAssignment(amountCents: 1500, intervalMonths: 1));
		$this->members->method('find')->willReturn($this->member(ncUserId: 'katrin.b', email: null));
		$this->userManager->method('get')->with('katrin.b')->willReturn(null);

		$this->receiptMail->expects($this->never())->method('sendReceipt');

		$this->service()->apply(self::ASSIGNMENT_ID, 1500, null);
	}
}
