<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\ContributionGroup;
use OCA\Vereinsbuchhaltung\Db\ContributionGroupMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Service\AccountHandoverService;
use OCA\Vereinsbuchhaltung\Service\DirectDebitEligibilityResolver;
use OCA\Vereinsbuchhaltung\Service\MemberLinkConfirmationService;
use OCA\Vereinsbuchhaltung\Service\MemberSetupSteps;
use OCA\Vereinsbuchhaltung\Service\SelfServiceService;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\ClubClockStub;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Mail\IEMailTemplate;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Die nächsten Schritte eines Mitglieds in Willkommensmail und Mandats-Anfrage:
 * Passwort (neu angelegtes Konto), Bestätigung (ausstehende Verknüpfung) und
 * Mandat (Lastschrift ohne einzugsfähiges Mandat, „Mein Bereich" an).
 */
class MemberSetupStepsTest extends TestCase {

	private AccountHandoverService&MockObject $handover;
	private MemberLinkConfirmationService&MockObject $linkConfirmation;
	private SelfServiceService&MockObject $selfService;
	private MandateMapper&MockObject $mandates;
	private AssignmentMapper&MockObject $assignments;
	private ContributionGroupMapper&MockObject $groups;
	private IL10N&MockObject $l;

	protected function setUp(): void {
		$this->handover = $this->createMock(AccountHandoverService::class);
		$this->linkConfirmation = $this->createMock(MemberLinkConfirmationService::class);
		$this->selfService = $this->createMock(SelfServiceService::class);
		$this->selfService->method('isEnabled')->willReturn(true);
		$this->mandates = $this->createMock(MandateMapper::class);
		$this->assignments = $this->createMock(AssignmentMapper::class);
		$this->groups = $this->createMock(ContributionGroupMapper::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnCallback(static fn (string $text, array $p = []): string => vsprintf($text, $p));
	}

	private function steps(): MemberSetupSteps {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud.example.org/apps/vereinsbuchhaltung/self');
		return new MemberSetupSteps(
			$this->handover,
			$this->linkConfirmation,
			$this->selfService,
			new DirectDebitEligibilityResolver($this->assignments, $this->mandates),
			$this->assignments,
			$this->groups,
			$urls,
			ClubClockStub::at('2026-11-24 10:00:00'),
			$this->l,
		);
	}

	private function member(bool $confirmed = true, ?string $uid = 'anna'): Member {
		$member = new Member();
		$member->setId(7);
		$member->setEmail('anna@example.org');
		$member->setNcUserId($uid);
		$member->setNcLinkConfirmedAt($confirmed && $uid !== null ? '2026-11-24 09:00:00' : null);
		return $member;
	}

	/** @param list<int> $intervals */
	private function directDebit(array $intervals = [1, 3, 12]): void {
		$assignment = new Assignment();
		$assignment->setGroupId(1);
		$assignment->setPaymentMethod(Assignment::PAYMENT_METHOD_DIRECT_DEBIT);
		$assignment->setValidFrom('2026-12-01');
		$assignment->setValidTo(null);
		$this->assignments->method('findByMember')->willReturn([$assignment]);
		$group = new ContributionGroup();
		$group->setAllowedIntervalsArray($intervals);
		$this->groups->method('find')->willReturn($group);
	}

	/** Neues Konto, Lastschrift, kein Mandat: Passwort und Mandat in einer Mail. */
	public function testWillkommenMitPasswortUndMandat(): void {
		$this->handover->method('setPasswordUrl')->willReturn('https://cloud.example.org/lostpassword/reset/form/t/anna');
		$this->directDebit();

		$steps = $this->steps()->forWelcome($this->member(), $this->l);

		$this->assertSame(['Passwort festlegen', '„Mein Bereich" öffnen'], array_column($steps, 'button'));
		$this->assertStringContainsString('wie oft der Beitrag eingezogen wird', $steps[1]['text']);
	}

	/** Ohne Wahl beim Turnus kein Satz dazu („falls es eine Wahl gibt"). */
	public function testMandatsSchrittOhneTurnuswahl(): void {
		$this->directDebit([12]);

		$steps = $this->steps()->forWelcome($this->member(), $this->l);

		$this->assertCount(1, $steps);
		$this->assertStringNotContainsString('wie oft', $steps[0]['text']);
	}

	public function testWillkommenOhneMandatsSchrittBeiEinzugsfaehigemMandat(): void {
		$this->directDebit();
		$mandate = new Mandate();
		$mandate->setStatus(Mandate::STATUS_ACTIVE);
		$this->mandates->method('findLiveByMember')->willReturn([$mandate]);

		$this->assertSame([], $this->steps()->forWelcome($this->member(), $this->l));
	}

	/** Eine ausstehende Verknüpfung hat ihren Bestätigungslink mit eigener Mail; ohne Bestätigung kein „Mein Bereich". */
	public function testWillkommenOhneMandatsSchrittBeiAusstehenderVerknuepfung(): void {
		$this->directDebit();
		$this->linkConfirmation->expects($this->never())->method('issueLink');

		$this->assertSame([], $this->steps()->forWelcome($this->member(false), $this->l));
	}

	/** Mandats-Anfrage bei ausstehender Verknüpfung: Bestätigen und Mandat in derselben Mail. */
	public function testMandatsAnfrageMitBestaetigungsschritt(): void {
		$this->linkConfirmation->expects($this->once())->method('issueLink')
			->willReturn(['url' => 'https://cloud.example.org/link-account/tok', 'email' => 'anna@example.org', 'expiresAt' => '2026-12-01 10:00:00']);

		$steps = $this->steps()->forMandateRequest($this->member(false), $this->l);

		$this->assertSame(['Konto bestätigen', '„Mein Bereich" öffnen'], array_column($steps, 'button'));
	}

	public function testMandatsAnfrageOhneKontoGehtNicht(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->steps()->forMandateRequest($this->member(true, null), $this->l);
	}

	public function testMandatsAnfrageBeiAusgeschaltetemMeinBereichGehtNicht(): void {
		$this->selfService = $this->createMock(SelfServiceService::class);
		$this->selfService->method('isEnabled')->willReturn(false);

		$this->expectException(\InvalidArgumentException::class);
		$this->steps()->forMandateRequest($this->member(), $this->l);
	}

	/** Ab zwei Schritten nummeriert, jeder mit eigener Schaltfläche. */
	public function testRenderNummeriertAbZweiSchritten(): void {
		$mail = $this->createMock(IEMailTemplate::class);
		$texts = [];
		$mail->method('addBodyText')->willReturnCallback(static function (string $html, string $plain = '') use (&$texts): void {
			$texts[] = $plain;
		});
		$mail->expects($this->exactly(2))->method('addBodyButton');

		MemberSetupSteps::render($mail, [
			['text' => 'Erst das.', 'button' => 'A', 'url' => 'https://a'],
			['text' => 'Dann das.', 'button' => 'B', 'url' => 'https://b'],
		], $this->l);

		$this->assertSame(['So geht es weiter:', '1. Erst das.', '2. Dann das.'], $texts);
	}
}
