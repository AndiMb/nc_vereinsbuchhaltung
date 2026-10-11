<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;

/**
 * Willkommens-Mail an ein neues Mitglied: ein Text des Vereins, der an die Mailadresse
 * der Mitgliedsakte geht – unabhängig davon, ob das Mitglied ein Nextcloud-Konto hat
 * (die Einladung zum Konto verschickt Nextcloud selbst, siehe
 * `src/lib/nextcloudAccount.js`). Der Text liegt als Vorlage in den Einstellungen
 * (Betreff und Text mit Platzhaltern) und lässt sich beim Aufnehmen eines Mitglieds
 * für diesen einen Fall überschreiben.
 *
 * Der Verein schreibt den Text selbst und damit in der Anrede, die zu ihm passt: die
 * Mail folgt hier bewusst keiner Du-/Sie-Regel der App. Nur der Standardtext kommt
 * aus der Sprache der anfragenden Person.
 */
class WelcomeMailService {

	public const KEY_SUBJECT = 'welcome_mail_subject';
	public const KEY_BODY = 'welcome_mail_body';

	/** Platzhalter in `{…}`: Schlüssel => wird beim Senden ersetzt, siehe {@see values()}. */
	public const PLACEHOLDERS = ['name', 'vorname', 'verein', 'mitgliedsnummer', 'beitritt', 'cloud'];

	private const MAX_SUBJECT = 200;
	private const MAX_BODY = 8000;

	public function __construct(
		private IConfig $config,
		private IMailer $mailer,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
		private MemberMapper $memberMapper,
		private AuditService $audit,
	) {
	}

	/**
	 * Die Vorlage der Einstellungen, ohne gespeicherte Vorlage der Standardtext.
	 *
	 * @return array{subject:string, body:string, isDefault:bool, placeholders:list<string>}
	 */
	public function template(): array {
		$subject = trim($this->config->getAppValue(Application::APP_ID, self::KEY_SUBJECT, ''));
		$body = trim($this->config->getAppValue(Application::APP_ID, self::KEY_BODY, ''));
		$default = $this->defaultTemplate();
		return [
			'subject' => $subject !== '' ? $subject : $default['subject'],
			'body' => $body !== '' ? $body : $default['body'],
			'isDefault' => $subject === '' && $body === '',
			'placeholders' => self::PLACEHOLDERS,
		];
	}

	/**
	 * Speichert die Vorlage. Beide Felder leer = zurück zum Standardtext.
	 *
	 * @throws \InvalidArgumentException wenn nur eines der Felder leer oder ein Text zu lang ist
	 */
	public function saveTemplate(string $subject, string $body): void {
		$subject = trim($subject);
		$body = trim($body);
		if ($subject === '' && $body === '') {
			$this->config->deleteAppValue(Application::APP_ID, self::KEY_SUBJECT);
			$this->config->deleteAppValue(Application::APP_ID, self::KEY_BODY);
			return;
		}
		$this->validate($subject, $body);
		$this->config->setAppValue(Application::APP_ID, self::KEY_SUBJECT, $subject);
		$this->config->setAppValue(Application::APP_ID, self::KEY_BODY, $body);
	}

	/**
	 * Verschickt die Willkommens-Mail an die Mailadresse des Mitglieds.
	 *
	 * @param string|null $subject Betreff für diese eine Mail, ohne Angabe der aus der Vorlage
	 * @param string|null $body Text für diese eine Mail, ohne Angabe der aus der Vorlage
	 * @throws DoesNotExistException wenn es das Mitglied nicht (mehr) gibt
	 * @throws \InvalidArgumentException wenn das Mitglied keine Mailadresse hat oder Betreff/Text fehlen
	 * @throws \RuntimeException wenn der Mailversand scheitert
	 */
	public function send(int $memberId, ?string $subject = null, ?string $body = null): void {
		$member = $this->memberMapper->find($memberId);
		$email = trim((string)$member->getEmail());
		if ($email === '' || $member->isRedacted()) {
			throw new \InvalidArgumentException($this->l10n->t('Das Mitglied hat keine Mailadresse – die Willkommens-Mail kann nicht gesendet werden.'));
		}

		$template = $this->template();
		$subject = trim($subject ?? '') !== '' ? trim((string)$subject) : $template['subject'];
		$body = trim($body ?? '') !== '' ? trim((string)$body) : $template['body'];
		$this->validate($subject, $body);

		$values = $this->values($member);
		$subject = $this->fill($subject, $values);
		$body = $this->fill($body, $values);

		$mail = $this->mailer->createEMailTemplate('vereinsbuchhaltung.welcomeMail');
		$mail->setSubject($subject);
		$mail->addHeader();
		$mail->addHeading($subject);
		foreach (preg_split('/\R{2,}/', $body) ?: [] as $paragraph) {
			$paragraph = trim($paragraph);
			if ($paragraph === '') {
				continue;
			}
			$mail->addBodyText(nl2br(htmlspecialchars($paragraph, ENT_QUOTES)), $paragraph);
		}
		$mail->addFooter();

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$email => $member->displayName()]);
			$message->setSubject($mail->renderSubject());
			$message->useTemplate($mail);
			$failed = $this->mailer->send($message);
		} catch (\Throwable $e) {
			throw new \RuntimeException($this->l10n->t('Die Willkommens-Mail konnte nicht gesendet werden: %s', [$e->getMessage()]), 0, $e);
		}
		if ($failed !== []) {
			throw new \RuntimeException($this->l10n->t('Die Willkommens-Mail konnte nicht gesendet werden.'));
		}

		$this->audit->log('Willkommens-Mail versendet', 'member', (int)$member->getId(), ['subject' => $subject]);
	}

	/**
	 * Der Standardtext in der Sprache der anfragenden Person. Kurz und ohne Zusagen,
	 * die der Verein nicht gemacht hat – er soll ihn ersetzen können, nicht entfernen müssen.
	 *
	 * @return array{subject:string, body:string}
	 */
	public function defaultTemplate(): array {
		return [
			'subject' => $this->l10n->t('Willkommen bei {verein}'),
			'body' => $this->l10n->t("Guten Tag {name},\n\nwillkommen bei {verein}! Schön, dass Sie dabei sind.\n\nBei Fragen melden Sie sich gern beim Vorstand."),
		];
	}

	/**
	 * @param array<string,string> $values
	 */
	private function fill(string $text, array $values): string {
		$replace = [];
		foreach ($values as $key => $value) {
			$replace['{' . $key . '}'] = $value;
		}
		return strtr($text, $replace);
	}

	/** @return array<string,string> */
	private function values(Member $member): array {
		$club = trim($this->config->getAppValue(Application::APP_ID, 'club_name', ''));
		$name = $member->displayName();
		return [
			'name' => $name,
			'vorname' => trim((string)$member->getFirstName()) !== '' ? trim((string)$member->getFirstName()) : $name,
			'verein' => $club !== '' ? $club : $this->l10n->t('Ihr Verein'),
			'mitgliedsnummer' => (string)($member->getMemberNumber() ?? ''),
			'beitritt' => GermanDate::format($member->getJoinedAt()),
			'cloud' => $this->urlGenerator->getAbsoluteURL('/'),
		];
	}

	/** @throws \InvalidArgumentException */
	private function validate(string $subject, string $body): void {
		if ($subject === '' || $body === '') {
			throw new \InvalidArgumentException($this->l10n->t('Betreff und Text der Willkommens-Mail dürfen nicht leer sein.'));
		}
		if (mb_strlen($subject) > self::MAX_SUBJECT) {
			throw new \InvalidArgumentException($this->l10n->t('Der Betreff ist zu lang (höchstens %d Zeichen).', [self::MAX_SUBJECT]));
		}
		if (mb_strlen($body) > self::MAX_BODY) {
			throw new \InvalidArgumentException($this->l10n->t('Der Text ist zu lang (höchstens %d Zeichen).', [self::MAX_BODY]));
		}
		// Ein Tippfehler im Platzhalter („{Name}“) ginge sonst wörtlich an das Mitglied.
		preg_match_all('/\{([^{}\s]+)\}/u', $subject . "\n" . $body, $found);
		foreach ($found[1] as $token) {
			if (!in_array($token, self::PLACEHOLDERS, true)) {
				throw new \InvalidArgumentException($this->l10n->t(
					'Unbekannter Platzhalter {%1$s}. Erlaubt sind: %2$s',
					[$token, implode(', ', array_map(static fn (string $key): string => '{' . $key . '}', self::PLACEHOLDERS))],
				));
			}
		}
	}
}
