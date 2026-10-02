<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Service\SelfServiceReceiptMailService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Self-Service-Quittungsmail (Spec §3.11/T33): EIN Vorlagen-Skelett mit vier
 * aus-/einblendbaren Slots statt sechs Fließtexten, plus die separate
 * Warn-Mail an die alte Adresse bei einem E-Mail-Wechsel (maskierte neue
 * Adresse, kein Rücknahme-Link).
 */
class SelfServiceReceiptMailServiceTest extends TestCase {

	private IMailer&MockObject $mailer;
	private IEMailTemplate&MockObject $template;
	private IMessage&MockObject $message;

	protected function setUp(): void {
		$this->mailer = $this->createMock(IMailer::class);
		$this->template = $this->createMock(IEMailTemplate::class);
		$this->template->method('renderSubject')->willReturn('Betreff');
		$this->mailer->method('createEMailTemplate')->willReturn($this->template);
		$this->message = $this->createMock(IMessage::class);
		$this->message->method('setTo')->willReturnSelf();
		$this->message->method('setSubject')->willReturnSelf();
		$this->message->method('useTemplate')->willReturnSelf();
		$this->mailer->method('createMessage')->willReturn($this->message);
	}

	private function service(): SelfServiceReceiptMailService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => $default,
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		return new SelfServiceReceiptMailService($this->mailer, $config, $l10n);
	}

	private function member(): Member {
		$member = new Member();
		$member->setMemberType(Member::TYPE_PERSON);
		$member->setFirstName('Katrin');
		$member->setLastName('Brunner');
		return $member;
	}

	public function testSendReceiptBlendetNurGesetzteSlotsEin(): void {
		$bodyTexts = [];
		$this->template->method('addBodyText')->willReturnCallback(function (string $text) use (&$bodyTexts) {
			$bodyTexts[] = $text;
			return $this->template;
		});

		$this->mailer->expects($this->once())->method('send')->with($this->message);

		$this->service()->sendReceipt(
			$this->member(),
			'katrin@example.org',
			'Ihr Monatsbeitrag wurde geändert',
			'Ihr Monatsbeitrag wurde von 10,00 € auf 15,00 € geändert.',
			'2026-07-01',
			'2026-07-05',
		);

		$joined = implode("\n", $bodyTexts);
		$this->assertStringContainsString('Ihr Monatsbeitrag wurde von 10,00 € auf 15,00 € geändert.', $joined);
		$this->assertStringContainsString('Wirkt ab: 2026-07-01', $joined);
		$this->assertStringContainsString('Voraussichtlich erster betroffener Einzug: 2026-07-05', $joined);
		// Kein Stellvertretungs-Hinweis, weil onBehalfNote nicht übergeben wurde.
		$this->assertStringNotContainsString('Kassenführung', $joined);
	}

	public function testSendReceiptMitStellvertretungsHinweis(): void {
		$bodyTexts = [];
		$this->template->method('addBodyText')->willReturnCallback(function (string $text) use (&$bodyTexts) {
			$bodyTexts[] = $text;
			return $this->template;
		});

		$this->service()->sendReceipt(
			$this->member(),
			'katrin@example.org',
			'Betreff',
			'Was auch immer geändert wurde.',
			null,
			null,
			'Auf telefonischen Wunsch am 1.6. geändert.',
		);

		$joined = implode("\n", $bodyTexts);
		$this->assertStringContainsString('Auf telefonischen Wunsch am 1.6. geändert.', $joined);
		$this->assertStringNotContainsString('Wirkt ab:', $joined);
	}

	public function testSendOldAddressWarningMaskiertNeueAdresseUndHatKeinenLink(): void {
		$bodyTexts = [];
		$this->template->method('addBodyText')->willReturnCallback(function (string $text) use (&$bodyTexts) {
			$bodyTexts[] = $text;
			return $this->template;
		});
		$this->template->expects($this->never())->method('addBodyButton');

		$this->mailer->expects($this->once())->method('send');

		$this->service()->sendOldAddressWarning($this->member(), 'katrin.alt@example.org', 'katrin.neu@example.org');

		$joined = implode("\n", $bodyTexts);
		$this->assertStringContainsString('k•••••••••@e••••••.org', $joined);
		$this->assertStringNotContainsString('katrin.neu@example.org', $joined);
	}

	public function testMaskEmailBehaeltErstenBuchstabenUndDomainendungSichtbar(): void {
		$this->assertSame('k•••••@e••••••.org', SelfServiceReceiptMailService::maskEmail('katrin@example.org'));
	}
}
