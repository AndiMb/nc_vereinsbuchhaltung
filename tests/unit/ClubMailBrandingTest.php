<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\BrandingService;
use OCA\Vereinsbuchhaltung\Service\ClubMailBranding;
use OCP\IConfig;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;

/**
 * Absender, Logo und Fußzeile der Vereins-Mails (Issue #205): Vereinsangaben
 * statt Name und Logo der Cloud, ohne Angaben das Verhalten von Nextcloud.
 */
class ClubMailBrandingTest extends TestCase {

	/**
	 * @param array<string, string> $settings
	 * @param array{content:string,mimeType:string}|null $logo
	 */
	private function branding(array $settings = [], ?array $logo = null): ClubMailBranding {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $settings[$key] ?? $default,
		);
		$logos = $this->createMock(BrandingService::class);
		$logos->expects($this->atMost(1))->method('getLogo')->willReturn($logo);
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturnCallback(fn (string $email): bool => str_contains($email, '@'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		return new class($config, $logos, $mailer, $factory) extends ClubMailBranding {
			protected function senderAddress(): string {
				return 'no-reply@cloud.example';
			}
		};
	}

	public function testOhneVereinsangabenBleibtEsBeimVerhaltenVonNextcloud(): void {
		$branding = $this->branding();
		$template = $this->createMock(IEMailTemplate::class);
		$template->expects($this->once())->method('addHeader');
		$template->expects($this->never())->method('addBodyText');
		$template->expects($this->once())->method('addFooter')->with('', 'de');
		$message = $this->createMock(IMessage::class);
		$message->expects($this->never())->method('setFrom');
		$message->expects($this->never())->method('setReplyTo');
		$message->expects($this->never())->method('attachInline');

		$branding->addHeader($template);
		$branding->addFooter($template, 'de');
		$branding->apply($message);
	}

	public function testVereinsnameWirdAbsendernameUndVereinsmailDieAntwortadresse(): void {
		$branding = $this->branding(['club_name' => 'TSV Beispiel e. V.', 'club_email' => 'vorstand@tsv.example']);
		$message = $this->createMock(IMessage::class);
		$message->expects($this->once())->method('setFrom')->with(['no-reply@cloud.example' => 'TSV Beispiel e. V.']);
		$message->expects($this->once())->method('setReplyTo')->with(['vorstand@tsv.example' => 'TSV Beispiel e. V.']);

		$branding->apply($message);
	}

	public function testUngueltigeVereinsmailWirdKeineAntwortadresse(): void {
		$branding = $this->branding(['club_name' => 'TSV', 'club_email' => 'kein-mail']);
		$message = $this->createMock(IMessage::class);
		$message->expects($this->never())->method('setReplyTo');

		$branding->apply($message);
	}

	public function testFusszeileNenntDenVereinUndOhneAntwortadresseDenHinweis(): void {
		$template = $this->createMock(IEMailTemplate::class);
		$template->expects($this->once())->method('addFooter')
			->with('Müller &amp; Söhne<br>Dies ist eine automatisch versendete E-Mail. Bitte antworten Sie nicht darauf.', 'en');

		$this->branding(['club_name' => 'Müller & Söhne'])->addFooter($template, 'en');
	}

	public function testMitAntwortadresseEntfaelltDerHinweisNichtZuAntworten(): void {
		$template = $this->createMock(IEMailTemplate::class);
		$template->expects($this->once())->method('addFooter')->with('TSV', null);

		$this->branding(['club_name' => 'TSV', 'club_email' => 'info@tsv.example'])->addFooter($template);
	}

	public function testVereinslogoErsetztDenKopfUndWirdEingebettet(): void {
		$branding = $this->branding(['club_name' => 'TSV "Eintracht"'], ['content' => 'PNGDATA', 'mimeType' => 'image/png']);
		$template = $this->createMock(IEMailTemplate::class);
		$template->expects($this->never())->method('addHeader');
		$template->expects($this->once())->method('addBodyText')->with(
			$this->logicalAnd(
				$this->stringContains('src="cid:' . ClubMailBranding::LOGO_CID . '"'),
				$this->stringContains('alt="TSV &quot;Eintracht&quot;"'),
			),
			false,
		);
		$message = $this->createMock(IMessage::class);
		$message->expects($this->once())->method('attachInline')->with('PNGDATA', ClubMailBranding::LOGO_CID, 'image/png');

		$branding->addHeader($template);
		$branding->apply($message);
	}
}
