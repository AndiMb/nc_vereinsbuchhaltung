<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\WelcomeMailService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Willkommens-Mail: Vorlage (gespeichert oder Standardtext), Platzhalter, Versand
 * an die Mailadresse der Akte – unabhängig vom Nextcloud-Konto.
 */
class WelcomeMailServiceTest extends TestCase {

	/** @var array<string,string> */
	private array $stored = [];
	private IMailer&MockObject $mailer;
	private IEMailTemplate&MockObject $template;
	private IMessage&MockObject $message;
	private MemberMapper&MockObject $members;
	private AuditService&MockObject $audit;

	protected function setUp(): void {
		$this->stored = ['club_name' => 'Popchor Magdeburg e.V.'];
		$this->template = $this->createMock(IEMailTemplate::class);
		$this->template->method('renderSubject')->willReturn('Betreff');
		$this->message = $this->createMock(IMessage::class);
		$this->message->method('setTo')->willReturnSelf();
		$this->message->method('setSubject')->willReturnSelf();
		$this->message->method('useTemplate')->willReturnSelf();
		$this->mailer = $this->createMock(IMailer::class);
		$this->mailer->method('createEMailTemplate')->willReturn($this->template);
		$this->mailer->method('createMessage')->willReturn($this->message);
		$this->mailer->method('send')->willReturn([]);
		$this->members = $this->createMock(MemberMapper::class);
		$this->audit = $this->createMock(AuditService::class);
	}

	private function service(): WelcomeMailService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->stored[$key] ?? $default);
		$config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void {
			$this->stored[$key] = $value;
		});
		$config->method('deleteAppValue')->willReturnCallback(function (string $app, string $key): void {
			unset($this->stored[$key]);
		});
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturn('https://cloud.example.org/');
		return new WelcomeMailService($config, $this->mailer, $urls, $l10n, $this->members, $this->audit);
	}

	private function member(?string $email = 'anna@example.org'): Member {
		$member = new Member();
		$member->setId(7);
		$member->setMemberType(Member::TYPE_PERSON);
		$member->setFirstName('Anna');
		$member->setLastName('Koch');
		$member->setEmail($email);
		$member->setMemberNumber('42');
		$member->setJoinedAt('2026-10-01');
		return $member;
	}

	public function testOhneGespeicherteVorlageGiltDerStandardtext(): void {
		$template = $this->service()->template();

		$this->assertTrue($template['isDefault']);
		$this->assertStringContainsString('{verein}', $template['subject']);
		$this->assertStringContainsString('{name}', $template['body']);
		$this->assertSame(WelcomeMailService::PLACEHOLDERS, $template['placeholders']);
	}

	public function testGespeicherteVorlageWirdGeliefert(): void {
		$service = $this->service();
		$service->saveTemplate('  Hallo {vorname}  ', "Text {cloud}\n");

		$template = $service->template();

		$this->assertFalse($template['isDefault']);
		$this->assertSame('Hallo {vorname}', $template['subject']);
		$this->assertSame('Text {cloud}', $template['body']);
	}

	public function testBeideFelderLeerStelltDenStandardtextWiederHer(): void {
		$service = $this->service();
		$service->saveTemplate('Betreff', 'Text');
		$service->saveTemplate('', '  ');

		$this->assertTrue($service->template()['isDefault']);
		$this->assertArrayNotHasKey(WelcomeMailService::KEY_SUBJECT, $this->stored);
	}

	public function testNurEinLeeresFeldWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->service()->saveTemplate('Betreff', '');
	}

	public function testZuLangerBetreffWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->service()->saveTemplate(str_repeat('x', 201), 'Text');
	}

	public function testSendenErsetztDiePlatzhalterUndGehtAnDieMailadresseDerAkte(): void {
		$this->members->method('find')->with(7)->willReturn($this->member());
		$this->stored[WelcomeMailService::KEY_SUBJECT] = 'Willkommen bei {verein}, {vorname}';
		$this->stored[WelcomeMailService::KEY_BODY] = "Guten Tag {name},\n\nMitgliedsnummer {mitgliedsnummer}, dabei seit {beitritt}.\n\nZur Cloud: {cloud}";

		$subjects = [];
		$this->template->method('setSubject')->willReturnCallback(function (string $subject) use (&$subjects): void {
			$subjects[] = $subject;
		});
		$paragraphs = [];
		$this->template->method('addBodyText')->willReturnCallback(function (string $html, $plain = false) use (&$paragraphs): void {
			$paragraphs[] = $plain;
		});
		$this->message->expects($this->once())->method('setTo')->with(['anna@example.org' => 'Anna Koch'])->willReturnSelf();
		$this->audit->expects($this->once())->method('log')->with('Willkommens-Mail versendet', 'member', 7);

		$this->service()->send(7);

		$this->assertSame(['Willkommen bei Popchor Magdeburg e.V., Anna'], $subjects);
		$this->assertSame([
			'Guten Tag Anna Koch,',
			'Mitgliedsnummer 42, dabei seit 01.10.2026.',
			'Zur Cloud: https://cloud.example.org/',
		], $paragraphs);
	}

	public function testBetreffUndTextFuerDenEinzelfallUeberschreibenDieVorlage(): void {
		$this->members->method('find')->willReturn($this->member());
		$subjects = [];
		$this->template->method('setSubject')->willReturnCallback(function (string $subject) use (&$subjects): void {
			$subjects[] = $subject;
		});
		$paragraphs = [];
		$this->template->method('addBodyText')->willReturnCallback(function (string $html, $plain = false) use (&$paragraphs): void {
			$paragraphs[] = $plain;
		});

		$this->service()->send(7, 'Hallo {vorname}', 'Schön, dass du da bist, {vorname}!');

		$this->assertSame(['Hallo Anna'], $subjects);
		$this->assertSame(['Schön, dass du da bist, Anna!'], $paragraphs);
	}

	public function testDerHtmlTeilDerMailIstMaskiert(): void {
		$this->members->method('find')->willReturn($this->member());
		$html = [];
		$this->template->method('addBodyText')->willReturnCallback(function (string $text, $plain = false) use (&$html): void {
			$html[] = $text;
		});

		$this->service()->send(7, 'Betreff', '<b>Hallo</b> {name}');

		$this->assertSame(['&lt;b&gt;Hallo&lt;/b&gt; Anna Koch'], $html);
	}

	public function testOhneMailadresseWirdNichtGesendet(): void {
		$this->members->method('find')->willReturn($this->member(null));
		$this->mailer->expects($this->never())->method('send');

		$this->expectException(\InvalidArgumentException::class);

		$this->service()->send(7);
	}

	public function testUnbekanntesMitgliedMeldetDoesNotExist(): void {
		$this->members->method('find')->willThrowException(new DoesNotExistException(''));

		$this->expectException(DoesNotExistException::class);

		$this->service()->send(99);
	}

	public function testScheitertDerVersandWirdDasAlsFehlerGemeldetUndNichtProtokolliert(): void {
		$this->members->method('find')->willReturn($this->member());
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createEMailTemplate')->willReturn($this->template);
		$mailer->method('createMessage')->willReturn($this->message);
		$mailer->method('send')->willThrowException(new \Exception('SMTP down'));
		$this->mailer = $mailer;
		$this->audit->expects($this->never())->method('log');

		$this->expectException(\RuntimeException::class);

		$this->service()->send(7);
	}
}
