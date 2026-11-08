<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use OCP\Security\ISecureRandom;

/**
 * Bestätigung der Kontoverknüpfung durch das Mitglied (#295).
 *
 * Die Verwaltung verknüpft ein Mitglied mit einem Nextcloud-Konto, oft auf
 * einen Vorschlag hin, der über die Mailadresse gefunden wurde. Diese Adresse
 * setzt jede Nutzerin und jeder Nutzer im eigenen Nextcloud-Profil selbst und
 * unbestätigt – ein fremdes Konto kann sich also als Mitglied ausgeben, und die
 * Verwaltung sieht den Unterschied nicht. Deshalb gilt eine Verknüpfung erst,
 * wenn das Konto beweist, dass es die beim Verein hinterlegte Mailadresse des
 * Mitglieds liest: an diese Adresse geht ein Einmal-Link, und bestätigen kann
 * ihn nur, wer mit genau dem verknüpften Konto angemeldet ist.
 *
 * Gespeichert wird nur der SHA-256-Hash des Links (wie bei Passwort-Reset-
 * Links); der Link ist {@see VALIDITY_DAYS} Tage gültig und nach der
 * Bestätigung verbraucht.
 */
class MemberLinkConfirmationService {

	public const VALIDITY_DAYS = 7;
	private const TOKEN_LENGTH = 64;

	public const STATUS_PENDING = 'pending';
	public const STATUS_CONFIRMED = 'confirmed';
	public const STATUS_WRONG_ACCOUNT = 'wrong_account';
	public const STATUS_INVALID = 'invalid';
	public const STATUS_EXPIRED = 'expired';

	public function __construct(
		private MemberMapper $members,
		private ISecureRandom $random,
		private IMailer $mailer,
		private IURLGenerator $urlGenerator,
		private IAppConfig $config,
		private IL10N $l10n,
		private RecipientL10n $recipientL10n,
		private ClubMailBranding $mailBranding,
		private AuditService $audit,
	) {
	}

	/**
	 * Erzeugt einen neuen Bestätigungslink für die ausstehende Verknüpfung und
	 * schickt ihn an die Mailadresse des Mitglieds. Ein älterer Link verfällt
	 * damit (immer nur einer je Mitglied).
	 *
	 * @return array{email: string, expiresAt: string}
	 * @throws \InvalidArgumentException wenn nichts zu bestätigen ist oder keine Mailadresse hinterlegt ist
	 * @throws UserFacingException wenn die Mail nicht zugestellt werden konnte
	 */
	public function send(Member $member): array {
		$uid = (string)$member->getNcUserId();
		if ($uid === '') {
			throw new \InvalidArgumentException($this->l10n->t('Das Mitglied ist mit keinem Nextcloud-Konto verknüpft.'));
		}
		if ($member->isNcLinkConfirmed()) {
			throw new \InvalidArgumentException($this->l10n->t('Die Verknüpfung ist bereits bestätigt.'));
		}
		if ($member->isRedacted()) {
			throw new \InvalidArgumentException($this->l10n->t('Ein anonymisiertes Mitglied lässt sich nicht verknüpfen.'));
		}
		$email = trim((string)$member->getEmail());
		if ($email === '') {
			throw new \InvalidArgumentException($this->l10n->t('Für dieses Mitglied ist keine Mailadresse hinterlegt. Der Bestätigungslink geht an diese Adresse – bitte zuerst eine hinterlegen.'));
		}

		$token = $this->random->generate(self::TOKEN_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC);
		$expiresAt = $this->now(new \DateInterval('P' . self::VALIDITY_DAYS . 'D'));
		$member->setNcLinkTokenHash($this->hash($token));
		$member->setNcLinkTokenExpiresAt($expiresAt);
		$this->members->update($member);

		$url = $this->urlGenerator->linkToRouteAbsolute('vereinsbuchhaltung.memberLink.show', ['token' => $token]);
		if ($this->sendMail($member, $email, $uid, $url) !== []) {
			throw new UserFacingException($this->l10n->t('Die Mail mit dem Bestätigungslink konnte nicht zugestellt werden.'));
		}
		$this->audit->log('Kontoverknüpfung: Bestätigungslink versendet', 'member', (int)$member->getId(), ['konto' => $uid]);
		return ['email' => $email, 'expiresAt' => $expiresAt];
	}

	/**
	 * Was die Bestätigungsseite für diesen Link und das angemeldete Konto zeigt.
	 *
	 * @return array{status: string, member: ?Member}
	 */
	public function view(string $token, string $uid): array {
		$member = $this->resolve($token);
		if ($member === null) {
			return ['status' => self::STATUS_INVALID, 'member' => null];
		}
		if ($member->getNcLinkTokenExpiresAt() === null || $member->getNcLinkTokenExpiresAt() < $this->now()) {
			return ['status' => self::STATUS_EXPIRED, 'member' => null];
		}
		if ($member->getNcUserId() !== $uid) {
			// Bewusst ohne Namen des Mitglieds: wer mit dem falschen Konto
			// angemeldet ist, erfährt nicht, zu wem der Link gehört.
			return ['status' => self::STATUS_WRONG_ACCOUNT, 'member' => null];
		}
		return ['status' => self::STATUS_PENDING, 'member' => $member];
	}

	/**
	 * Bestätigt die Verknüpfung, wenn der Link gültig ist und das angemeldete
	 * Konto genau das verknüpfte ist. Der Link ist danach verbraucht.
	 *
	 * @return array{status: string, member: ?Member}
	 */
	public function confirm(string $token, string $uid): array {
		$view = $this->view($token, $uid);
		if ($view['status'] !== self::STATUS_PENDING || $view['member'] === null) {
			return $view;
		}
		$member = $view['member'];
		$member->setNcLinkConfirmedAt($this->now());
		$member->setNcLinkTokenHash(null);
		$member->setNcLinkTokenExpiresAt(null);
		$member = $this->members->update($member);
		$this->audit->log('Kontoverknüpfung bestätigt', 'member', (int)$member->getId(), ['konto' => $uid], $uid);
		return ['status' => self::STATUS_CONFIRMED, 'member' => $member];
	}

	/** Setzt den Bestätigungsstand zurück – bei neuer Verknüpfung, Lösen oder Löschen des Kontos. */
	public static function reset(Member $member): void {
		$member->setNcLinkConfirmedAt(null);
		$member->setNcLinkTokenHash(null);
		$member->setNcLinkTokenExpiresAt(null);
	}

	private function resolve(string $token): ?Member {
		if (strlen($token) !== self::TOKEN_LENGTH || !ctype_alnum($token)) {
			return null;
		}
		$member = $this->members->findByLinkTokenHash($this->hash($token));
		if ($member === null || $member->isNcLinkConfirmed() || $member->isRedacted()
			|| !hash_equals((string)$member->getNcLinkTokenHash(), $this->hash($token))) {
			return null;
		}
		return $member;
	}

	/** @return string[] die Empfänger, an die der Versand scheiterte (leer = zugestellt) */
	private function sendMail(Member $member, string $email, string $uid, string $url): array {
		// Die Mail liest das Mitglied, nicht das (noch unbestätigte) Konto: deshalb
		// nicht dessen Sprache, sondern die Vorgabe (siehe RecipientL10n::forMember).
		$l = $this->recipientL10n->forMember($member);
		$clubName = $this->config->getValueString(Application::APP_ID, 'club_name', '') ?: $l->t('Ihr Verein');

		$template = $this->mailer->createEMailTemplate('vereinsbuchhaltung.memberLinkConfirmation');
		$template->setSubject($l->t('Bitte bestätigen Sie Ihr Nextcloud-Konto'));
		$this->mailBranding->addHeader($template);
		$template->addHeading($l->t('Nextcloud-Konto bestätigen'));
		$template->addBodyText($l->t('%1$s möchte Ihre Mitgliedschaft mit dem Nextcloud-Konto „%2$s" verknüpfen. Damit sehen Sie dort Ihre Daten, Beiträge und Mandate.', [$clubName, $uid]));
		$template->addBodyText($l->t('Ist das Ihr Konto, melden Sie sich damit an und bestätigen Sie über die Schaltfläche. Ist es nicht Ihr Konto, klicken Sie bitte nicht und geben Sie dem Verein Bescheid.'));
		$template->addBodyButton($l->t('Konto bestätigen'), $url);
		$template->addBodyText($l->t('Dieser Link ist %d Tage gültig und nur einmal verwendbar.', [self::VALIDITY_DAYS]));
		$this->mailBranding->addFooter($template, $l->getLanguageCode());

		$message = $this->mailer->createMessage();
		$this->mailBranding->apply($message);
		$message->setTo([$email => $member->displayName()]);
		$message->setSubject($template->renderSubject());
		$message->useTemplate($template);
		return $this->mailer->send($message);
	}

	private function hash(string $token): string {
		return hash('sha256', $token);
	}

	private function now(?\DateInterval $add = null): string {
		$date = new \DateTime(); // UTC-Zeitstempel (#263)
		if ($add !== null) {
			$date->add($add);
		}
		return $date->format('Y-m-d H:i:s');
	}
}
