<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\Reimbursement;
use OCA\Vereinsbuchhaltung\Service\ClubMailBranding;
use OCA\Vereinsbuchhaltung\Service\RecipientL10n;
use OCA\Vereinsbuchhaltung\Service\ReimbursementMailService;
use OCA\Vereinsbuchhaltung\Service\ReimbursementSettings;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Empfänger und Zeitpunkte der Mails zur Auslagenerstattung
 * (docs/reimbursement-design.md §9).
 */
class ReimbursementMailServiceTest extends TestCase {

	private IMailer&MockObject $mailer;
	private IUserManager&MockObject $users;
	private MemberMapper&MockObject $members;
	private ReimbursementSettings&MockObject $settings;
	private LoggerInterface&MockObject $logger;

	/** @var list<array<string,string>> Empfänger → Name je gesendeter Mail */
	private array $sentTo = [];
	/** @var list<string> */
	private array $subjects = [];
	/** @var list<string> */
	private array $bodies = [];

	protected function setUp(): void {
		$this->sentTo = [];
		$this->subjects = [];
		$this->bodies = [];

		$this->mailer = $this->createMock(IMailer::class);
		$this->mailer->method('createEMailTemplate')->willReturnCallback(function (): IEMailTemplate {
			$template = $this->createMock(IEMailTemplate::class);
			$template->method('setSubject')->willReturnCallback(function (string $subject): void {
				$this->subjects[] = $subject;
			});
			$template->method('addBodyText')->willReturnCallback(function (string $text): void {
				$this->bodies[] = $text;
			});
			$template->method('renderSubject')->willReturn('Betreff');
			return $template;
		});
		$this->mailer->method('createMessage')->willReturnCallback(function (): IMessage {
			$message = $this->createMock(IMessage::class);
			$message->method('setTo')->willReturnCallback(function (array $to) use ($message): IMessage {
				$this->sentTo[] = $to;
				return $message;
			});
			$message->method('setSubject')->willReturnSelf();
			$message->method('useTemplate')->willReturnSelf();
			return $message;
		});

		$this->users = $this->createMock(IUserManager::class);
		$this->members = $this->createMock(MemberMapper::class);
		$this->settings = $this->createMock(ReimbursementSettings::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function service(string $language = 'de_DE'): ReimbursementMailService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$l10n->method('getLanguageCode')->willReturn($language);
		$recipientL10n = $this->createMock(RecipientL10n::class);
		$recipientL10n->method('forNcUser')->willReturn($l10n);
		$recipientL10n->method('forMember')->willReturn($l10n);

		return new ReimbursementMailService(
			$this->mailer,
			$this->createMock(ClubMailBranding::class),
			$recipientL10n,
			$this->users,
			$this->members,
			$this->settings,
			$this->logger,
		);
	}

	private function user(string $name, ?string $email): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn($name);
		$user->method('getEMailAddress')->willReturn($email);
		return $user;
	}

	private function reimbursement(): Reimbursement {
		$r = new Reimbursement();
		$r->setId(7);
		$r->setMemberId(1);
		$r->setAmountCents(1250);
		$r->setPurpose('Material');
		$r->setSubmittedBy('anna');
		$r->setRequiredApprovals(2);
		$r->setRejectionReason('Beleg unleserlich');
		$r->setPaidAt('2026-11-02');
		return $r;
	}

	private function member(?string $email = 'anna@example.org', ?string $ncUser = 'anna'): Member {
		$member = new Member();
		$member->setId(1);
		$member->setFirstName('Anna');
		$member->setLastName('Musterfrau');
		$member->setEmail($email);
		$member->setNcUserId($ncUser);
		return $member;
	}

	public function testNeuerAntragGehtAnAlleVerwalterAusserDemEinreicher(): void {
		$this->members->method('find')->willReturn($this->member());
		// approversFor() liefert den Kreis schon ohne den Einreicher
		$this->settings->method('approversFor')->with('anna')->willReturn(['vera', 'viktor']);
		$this->users->method('get')->willReturnMap([
			['vera', $this->user('Vera', 'vera@example.org')],
			['viktor', $this->user('Viktor', 'viktor@example.org')],
		]);

		$this->service()->submitted($this->reimbursement());

		$this->assertSame([['vera@example.org' => 'Vera'], ['viktor@example.org' => 'Viktor']], $this->sentTo);
		$this->assertStringContainsString('Anna Musterfrau', implode(' ', $this->bodies));
		$this->assertStringContainsString('12,50', implode(' ', $this->bodies));
	}

	public function testVerwalterOhneAdresseWirdUebersprungenDieAnderenBekommenDieMail(): void {
		$this->members->method('find')->willReturn($this->member());
		$this->settings->method('approversFor')->willReturn(['vera', 'viktor']);
		$this->users->method('get')->willReturnMap([
			['vera', $this->user('Vera', null)],
			['viktor', $this->user('Viktor', 'viktor@example.org')],
		]);

		$this->service()->submitted($this->reimbursement());

		$this->assertSame([['viktor@example.org' => 'Viktor']], $this->sentTo);
	}

	public function testEinMailfehlerBeiEinemEmpfaengerStoertDieAnderenNicht(): void {
		$this->members->method('find')->willReturn($this->member());
		$this->settings->method('approversFor')->willReturn(['vera', 'viktor']);
		$this->users->method('get')->willReturnMap([
			['vera', $this->user('Vera', 'vera@example.org')],
			['viktor', $this->user('Viktor', 'viktor@example.org')],
		]);
		$calls = 0;
		$this->mailer->method('send')->willReturnCallback(function () use (&$calls): array {
			if ($calls++ === 0) {
				throw new \RuntimeException('SMTP down');
			}
			return [];
		});
		$this->logger->expects($this->once())->method('warning');

		$this->service()->submitted($this->reimbursement());

		$this->assertCount(2, $this->sentTo);
	}

	public function testFreigabeAblehnungUndAuszahlungGehenAnDasMitglied(): void {
		$this->members->method('find')->willReturn($this->member());
		$service = $this->service();

		$service->approved($this->reimbursement());
		$service->rejected($this->reimbursement());
		$service->paid($this->reimbursement());

		$this->assertCount(3, $this->sentTo);
		foreach ($this->sentTo as $to) {
			$this->assertSame(['anna@example.org' => 'Anna Musterfrau'], $to);
		}
		$this->assertSame(['Ihre Auslage wurde freigegeben', 'Ihre Auslage wurde abgelehnt', 'Ihre Auslage wurde erstattet'], $this->subjects);
		$text = implode(' ', $this->bodies);
		$this->assertStringContainsString('Beleg unleserlich', $text, 'Die Ablehnung nennt die Begründung');
		$this->assertStringContainsString('02.11.2026', $text, 'Die Auszahlung nennt das Datum');
	}

	public function testBetraegeStehenInDerSchreibweiseDerMailsprache(): void {
		$this->members->method('find')->willReturn($this->member());
		$r = $this->reimbursement();
		$r->setAmountCents(123456);

		$this->service('en')->approved($r);
		$this->assertStringContainsString('1,234.56', implode(' ', $this->bodies));

		$this->bodies = [];
		$this->service('de')->approved($r);
		$this->assertStringContainsString('1.234,56', implode(' ', $this->bodies));
	}

	public function testOhneAdresseImMitgliedNimmtDieMailDieDesKontos(): void {
		$this->members->method('find')->willReturn($this->member(null, 'anna'));
		$this->users->method('get')->with('anna')->willReturn($this->user('Anna', 'konto@example.org'));

		$this->service()->approved($this->reimbursement());

		$this->assertSame([['konto@example.org' => 'Anna Musterfrau']], $this->sentTo);
	}

	public function testMitgliedOhneJedeAdresseBekommtKeineMail(): void {
		$this->members->method('find')->willReturn($this->member(null, null));

		$this->service()->approved($this->reimbursement());

		$this->assertSame([], $this->sentTo);
	}
}
