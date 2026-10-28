<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCP\IConfig;
use OCP\L10N\IFactory;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Util;

/**
 * Absender, Logo und Fußzeile der E-Mails, die die App verschickt (Issue #205).
 * Ohne Anpassung trägt jede Mail den Namen und das Logo der Cloud; wer die
 * Cloud nicht nach dem Verein benennen will, sähe sonst überall den
 * Cloud-Namen. Hier treten die Vereinsangaben an deren Stelle:
 *
 * - Absendername ist der Vereinsname. Die Adresse bleibt die aus den
 *   Mail-Einstellungen der Cloud, sonst lehnen Mailserver die Mail wegen
 *   SPF/DMARC ab.
 * - Antworten gehen an die E-Mail des Vereins (Reply-To), wenn eine
 *   hinterlegt ist.
 * - Im Kopf steht das Vereinslogo, eingebettet in die Mail (cid:), damit es
 *   ohne nachgeladene Bilder erscheint.
 * - Die Fußzeile nennt den Verein statt Name und Slogan der Cloud.
 *
 * Fehlt eine Angabe, bleibt es beim Verhalten von Nextcloud. Je Mail erst
 * addHeader()/addFooter() an der Vorlage, dann apply() an der Nachricht; das
 * Logo wird einmal je Request gelesen, Kopf und Anhang passen so zusammen.
 */
class ClubMailBranding {

	public const LOGO_CID = 'vereinslogo';

	/** @var array{content:string,mimeType:string}|false|null false = nicht geladen */
	private array|false|null $logo = false;

	public function __construct(
		private IConfig $config,
		private BrandingService $branding,
		private IMailer $mailer,
		private IFactory $l10nFactory,
	) {
	}

	/** Kopf der Mail: das Vereinslogo, ohne eines das Logo der Cloud. */
	public function addHeader(IEMailTemplate $template): void {
		if ($this->logo() === null) {
			$template->addHeader();
			return;
		}
		$alt = htmlspecialchars($this->clubName(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$template->addBodyText(
			'<img src="cid:' . self::LOGO_CID . '" alt="' . $alt . '" style="display:block;margin:20px auto 10px;max-width:200px;max-height:80px">',
			false,
		);
	}

	/** Fußzeile: der Vereinsname; ohne Antwortadresse dazu der Hinweis, nicht zu antworten. */
	public function addFooter(IEMailTemplate $template, ?string $lang = null): void {
		$name = $this->clubName();
		if ($name === '') {
			$template->addFooter('', $lang);
			return;
		}
		$text = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		if ($this->replyTo() === null) {
			$text .= '<br>' . $this->l10nFactory->get(Application::APP_ID, $lang)->t('Dies ist eine automatisch versendete E-Mail. Bitte antworten Sie nicht darauf.');
		}
		$template->addFooter($text, $lang);
	}

	/** Absendername, Antwortadresse und eingebettetes Logo an der fertigen Nachricht. */
	public function apply(IMessage $message): void {
		$name = $this->clubName();
		if ($name !== '') {
			$message->setFrom([$this->senderAddress() => $name]);
		}
		$replyTo = $this->replyTo();
		if ($replyTo !== null) {
			$message->setReplyTo($name !== '' ? [$replyTo => $name] : [$replyTo]);
		}
		$logo = $this->logo();
		if ($logo !== null) {
			$message->attachInline($logo['content'], self::LOGO_CID, $logo['mimeType']);
		}
	}

	/** Dieselbe Adresse, die Nextcloud ohne eigenen Absender nimmt (OC\Mail\Mailer::send()). */
	protected function senderAddress(): string {
		return Util::getDefaultEmailAddress('no-reply');
	}

	private function clubName(): string {
		return trim($this->config->getAppValue(Application::APP_ID, 'club_name', ''));
	}

	private function replyTo(): ?string {
		$email = trim($this->config->getAppValue(Application::APP_ID, 'club_email', ''));
		return $email !== '' && $this->mailer->validateMailAddress($email) ? $email : null;
	}

	/** @return array{content:string,mimeType:string}|null */
	private function logo(): ?array {
		if ($this->logo === false) {
			$this->logo = $this->branding->getLogo();
		}
		return $this->logo;
	}
}
