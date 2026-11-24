<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Mail\IMailer;

/**
 * Bittet ein Mitglied mit Nextcloud-Konto, sein SEPA-Lastschriftmandat selbst
 * in „Mein Bereich" einzurichten – ohne dass die Verwaltung vorher eine IBAN
 * erfassen muss. Ist die Kontoverknüpfung noch nicht bestätigt, enthält
 * dieselbe Mail den Bestätigungsschritt ({@see MemberSetupSteps}).
 */
class MandateRequestMailService {

	public function __construct(
		private MemberMapper $members,
		private MemberSetupSteps $setupSteps,
		private RecipientL10n $recipientL10n,
		private IMailer $mailer,
		private IAppConfig $config,
		private ClubMailBranding $mailBranding,
		private AuditService $audit,
		private IL10N $l10n,
	) {
	}

	/**
	 * @return array{email: string}
	 * @throws DoesNotExistException wenn es das Mitglied nicht (mehr) gibt
	 * @throws \InvalidArgumentException wenn das Mitglied sein Mandat nicht selbst einrichten kann
	 * @throws UserFacingException wenn die Mail nicht zugestellt werden konnte
	 */
	public function send(int $memberId): array {
		$member = $this->members->find($memberId);
		$l = $this->recipientL10n->forMember($member);
		$steps = $this->setupSteps->forMandateRequest($member, $l);
		$email = trim((string)$member->getEmail());
		$clubName = trim($this->config->getValueString(Application::APP_ID, 'club_name', '')) ?: $l->t('Ihr Verein');

		$subject = $l->t('Bitte richten Sie Ihr SEPA-Lastschriftmandat ein');
		$mail = $this->mailer->createEMailTemplate('vereinsbuchhaltung.mandateRequest');
		$mail->setSubject($subject);
		$this->mailBranding->addHeader($mail);
		$mail->addHeading($subject);
		$intro = $l->t('Guten Tag %s,', [$member->displayName()]);
		$mail->addBodyText(htmlspecialchars($intro, ENT_QUOTES), $intro);
		$request = $l->t('%s bittet Sie, Ihr SEPA-Lastschriftmandat selbst einzurichten. Das geht in wenigen Minuten in Ihrem Nextcloud-Konto.', [$clubName]);
		$mail->addBodyText(htmlspecialchars($request, ENT_QUOTES), $request);
		MemberSetupSteps::render($mail, $steps, $l);
		$this->mailBranding->addFooter($mail, $l->getLanguageCode());

		$message = $this->mailer->createMessage();
		$this->mailBranding->apply($message);
		$message->setTo([$email => $member->displayName()]);
		$message->setSubject($mail->renderSubject());
		$message->useTemplate($mail);
		try {
			$failed = $this->mailer->send($message);
		} catch (\Throwable $e) {
			throw new UserFacingException($this->l10n->t('Die Mail mit der Mandats-Anfrage konnte nicht zugestellt werden.'), 0, $e);
		}
		if ($failed !== []) {
			throw new UserFacingException($this->l10n->t('Die Mail mit der Mandats-Anfrage konnte nicht zugestellt werden.'));
		}
		$this->audit->log('Mandat per Mail angefragt', 'member', (int)$member->getId(), ['schritte' => count($steps)]);
		return ['email' => $email];
	}
}
