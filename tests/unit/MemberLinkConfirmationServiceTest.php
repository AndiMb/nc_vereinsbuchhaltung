<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\ClubMailBranding;
use OCA\Vereinsbuchhaltung\Service\MemberLinkConfirmationService;
use OCA\Vereinsbuchhaltung\Service\RecipientL10n;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\AppConfigStub;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Bestätigung der Kontoverknüpfung durch das Mitglied (#295): der Link geht an
 * die Mailadresse des Mitglieds, bestätigen kann nur das verknüpfte Konto.
 */
class MemberLinkConfirmationServiceTest extends TestCase {

	private const TOKEN = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789ab';

	private MemberMapper&MockObject $members;
	private IMailer&MockObject $mailer;
	private AuditService&MockObject $audit;
	/** @var list<string> */
	private array $sentTo = [];

	protected function setUp(): void {
		$this->members = $this->createMock(MemberMapper::class);
		$this->members->method('update')->willReturnArgument(0);
		$this->mailer = $this->createMock(IMailer::class);
		$this->mailer->method('createEMailTemplate')->willReturn($this->createMock(IEMailTemplate::class));
		$message = $this->createMock(IMessage::class);
		$message->method('setTo')->willReturnCallback(function (array $to) use ($message) {
			$this->sentTo = array_keys($to);
			return $message;
		});
		$this->mailer->method('createMessage')->willReturn($message);
		$this->audit = $this->createMock(AuditService::class);
	}

	private function service(): MemberLinkConfirmationService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => $text);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(self::TOKEN);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://cloud.example/link-account/' . self::TOKEN);
		$recipient = $this->createMock(RecipientL10n::class);
		$recipient->method('forMember')->willReturn($l10n);
		return new MemberLinkConfirmationService(
			$this->members,
			$random,
			$this->mailer,
			$url,
			AppConfigStub::create($this),
			$l10n,
			$recipient,
			$this->createMock(ClubMailBranding::class),
			$this->audit,
		);
	}

	private function pendingMember(): Member {
		$member = new Member();
		$member->setId(7);
		$member->setFirstName('Katrin');
		$member->setLastName('Brunner');
		$member->setEmail('katrin@example.org');
		$member->setNcUserId('katrin.b');
		return $member;
	}

	/** Ein Mitglied mit frisch verschicktem Link, wie es die Datenbank danach liefert. */
	private function memberWithToken(string $expiresAt = '2999-01-01 00:00:00'): Member {
		$member = $this->pendingMember();
		$member->setNcLinkTokenHash(hash('sha256', self::TOKEN));
		$member->setNcLinkTokenExpiresAt($expiresAt);
		$this->members->method('findByLinkTokenHash')->with(hash('sha256', self::TOKEN))->willReturn($member);
		return $member;
	}

	public function testSendSchicktDenLinkAnDieAdresseDesMitgliedsUndSpeichertNurDenHash(): void {
		$member = $this->pendingMember();
		$this->mailer->expects($this->once())->method('send')->willReturn([]);

		$result = $this->service()->send($member);

		$this->assertSame(['katrin@example.org'], $this->sentTo);
		$this->assertSame('katrin@example.org', $result['email']);
		$this->assertSame(hash('sha256', self::TOKEN), $member->getNcLinkTokenHash());
		$this->assertNotNull($member->getNcLinkTokenExpiresAt());
		$this->assertFalse($member->isNcLinkConfirmed());
	}

	public function testSendOhneMailadresseSchlaegtFehl(): void {
		$member = $this->pendingMember();
		$member->setEmail(null);
		$this->mailer->expects($this->never())->method('send');

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->send($member);
	}

	public function testSendBeiBestaetigterVerknuepfungSchlaegtFehl(): void {
		$member = $this->pendingMember();
		$member->setNcLinkConfirmedAt('2026-01-01 00:00:00');

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->send($member);
	}

	public function testSendMeldetNichtZugestellteMail(): void {
		$this->mailer->method('send')->willReturn(['katrin@example.org']);

		$this->expectException(UserFacingException::class);
		$this->service()->send($this->pendingMember());
	}

	public function testRichtigesKontoBestaetigtUndVerbrauchtDenLink(): void {
		$member = $this->memberWithToken();

		$this->assertSame(MemberLinkConfirmationService::STATUS_PENDING, $this->service()->view(self::TOKEN, 'katrin.b')['status']);
		$result = $this->service()->confirm(self::TOKEN, 'katrin.b');

		$this->assertSame(MemberLinkConfirmationService::STATUS_CONFIRMED, $result['status']);
		$this->assertTrue($member->isNcLinkConfirmed());
		$this->assertSame('katrin.b', $member->confirmedNcUserId());
		$this->assertNull($member->getNcLinkTokenHash());
		$this->assertNull($member->getNcLinkTokenExpiresAt());
	}

	/**
	 * Der Kern von #295: ein fremdes Konto, das sich mit der Mailadresse des
	 * Mitglieds als Vorschlag eingeschlichen hat, bekommt den Link nie – und
	 * das Mitglied selbst kann ihn mit seinem eigenen Konto nicht für das
	 * fremde bestätigen.
	 */
	public function testAnderesKontoKannNichtBestaetigen(): void {
		$member = $this->memberWithToken();
		$this->members->expects($this->never())->method('update');

		$result = $this->service()->confirm(self::TOKEN, 'jemand.anderes');

		$this->assertSame(MemberLinkConfirmationService::STATUS_WRONG_ACCOUNT, $result['status']);
		$this->assertNull($result['member']);
		$this->assertFalse($member->isNcLinkConfirmed());
	}

	public function testAbgelaufenerLinkBestaetigtNicht(): void {
		$member = $this->memberWithToken('2000-01-01 00:00:00');

		$result = $this->service()->confirm(self::TOKEN, 'katrin.b');

		$this->assertSame(MemberLinkConfirmationService::STATUS_EXPIRED, $result['status']);
		$this->assertFalse($member->isNcLinkConfirmed());
	}

	public function testUnbekannterOderKaputterLinkIstUngueltig(): void {
		$this->members->method('findByLinkTokenHash')->willReturn(null);

		$this->assertSame(MemberLinkConfirmationService::STATUS_INVALID, $this->service()->confirm(self::TOKEN, 'katrin.b')['status']);
		$this->assertSame(MemberLinkConfirmationService::STATUS_INVALID, $this->service()->confirm('kurz', 'katrin.b')['status']);
		$this->assertSame(MemberLinkConfirmationService::STATUS_INVALID, $this->service()->confirm(str_repeat('/', 64), 'katrin.b')['status']);
	}

	public function testAnonymisiertesMitgliedBestaetigtNicht(): void {
		$member = $this->memberWithToken();
		$member->setRedactedAt('2026-01-01 00:00:00');

		$this->assertSame(MemberLinkConfirmationService::STATUS_INVALID, $this->service()->confirm(self::TOKEN, 'katrin.b')['status']);
		$this->assertFalse($member->isNcLinkConfirmed());
	}
}
