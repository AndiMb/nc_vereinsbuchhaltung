<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\Reimbursement;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Mails zur Auslagenerstattung (docs/reimbursement-design.md §9):
 *
 * - neuer Antrag: an alle Verwalter außer dem Einreicher,
 * - endgültig freigegeben, abgelehnt, ausgezahlt: an das Mitglied.
 *
 * Teilfreigaben lösen keine Mail aus. Jede Mail geht in der Sprache des
 * Empfängers raus ({@see RecipientL10n}). Ein Fehler bei einem Empfänger
 * (keine Adresse, Mailserver) bricht weder den Vorgang noch die Mails an die
 * anderen ab: der Antrag ist längst gespeichert, Mails sind eine Zugabe.
 */
class ReimbursementMailService {

	public function __construct(
		private IMailer $mailer,
		private ClubMailBranding $mailBranding,
		private RecipientL10n $recipientL10n,
		private IUserManager $userManager,
		private MemberMapper $members,
		private ReimbursementSettings $settings,
		private LoggerInterface $logger,
	) {
	}

	/** Neuer Antrag: alle Verwalter außer dem Einreicher erfahren davon. */
	public function submitted(Reimbursement $reimbursement): void {
		$memberName = $this->memberName($reimbursement);
		foreach ($this->settings->approversFor($reimbursement->getSubmittedBy()) as $uid) {
			$user = $this->userManager->get($uid);
			$email = $user?->getEMailAddress();
			if ($user === null || $email === null || $email === '') {
				$this->logger->info('Auslagenerstattung: Verwalter {uid} hat keine E-Mail-Adresse, keine Benachrichtigung.', ['uid' => $uid]);
				continue;
			}
			$l = $this->recipientL10n->forNcUser($uid);
			$this->send(
				'vereinsbuchhaltung.reimbursementSubmitted',
				$email,
				$user->getDisplayName(),
				$l,
				$l->t('Neue Auslage zur Freigabe'),
				[
					$l->t('Guten Tag %s,', [$user->getDisplayName()]),
					$l->t('%1$s hat eine Auslage über %2$s € eingereicht: %3$s', [$memberName, $this->euro($reimbursement->getAmountCents()), $reimbursement->getPurpose()]),
					$l->t('Sie finden den Antrag unter „Buchungen → Auslagen“. Freigegeben wird er, sobald %d Verwalter zugestimmt haben.', [$reimbursement->getRequiredApprovals()]),
				],
			);
		}
	}

	/** Die letzte nötige Freigabe liegt vor: dem Mitglied wurde stattgegeben. */
	public function approved(Reimbursement $reimbursement): void {
		$this->toMember($reimbursement, 'vereinsbuchhaltung.reimbursementApproved', static fn (IL10N $l, Reimbursement $r, Member $m, string $amount): array => [
			$l->t('Ihre Auslage wurde freigegeben'),
			[
				$l->t('Guten Tag %s,', [$m->displayName()]),
				$l->t('Ihrem Antrag auf Erstattung von %1$s € wurde stattgegeben: %2$s', [$amount, $r->getPurpose()]),
				$l->t('Der Betrag wird in Kürze auf Ihr angegebenes Konto überwiesen. Sie bekommen eine weitere Nachricht, sobald die Überweisung vermerkt ist.'),
			],
		]);
	}

	public function rejected(Reimbursement $reimbursement): void {
		$this->toMember($reimbursement, 'vereinsbuchhaltung.reimbursementRejected', static fn (IL10N $l, Reimbursement $r, Member $m, string $amount): array => [
			$l->t('Ihre Auslage wurde abgelehnt'),
			[
				$l->t('Guten Tag %s,', [$m->displayName()]),
				$l->t('Ihr Antrag auf Erstattung von %1$s € wurde abgelehnt: %2$s', [$amount, $r->getPurpose()]),
				$l->t('Begründung: %s', [(string)$r->getRejectionReason()]),
				$l->t('Bei Fragen wenden Sie sich bitte an den Vorstand.'),
			],
		]);
	}

	public function paid(Reimbursement $reimbursement): void {
		$this->toMember($reimbursement, 'vereinsbuchhaltung.reimbursementPaid', static fn (IL10N $l, Reimbursement $r, Member $m, string $amount): array => [
			$l->t('Ihre Auslage wurde erstattet'),
			[
				$l->t('Guten Tag %s,', [$m->displayName()]),
				$l->t('Die Erstattung von %1$s € für „%2$s“ wurde am %3$s überwiesen.', [$amount, $r->getPurpose(), GermanDate::format($r->getPaidAt())]),
			],
		]);
	}

	/**
	 * @param callable(IL10N, Reimbursement, Member, string): array{0: string, 1: list<string>} $compose
	 */
	private function toMember(Reimbursement $reimbursement, string $templateId, callable $compose): void {
		try {
			$member = $this->members->find($reimbursement->getMemberId());
		} catch (DoesNotExistException) {
			return;
		}
		$email = $this->memberEmail($member);
		if ($email === null) {
			$this->logger->info('Auslagenerstattung: Mitglied {id} hat keine E-Mail-Adresse, keine Benachrichtigung.', ['id' => $member->getId()]);
			return;
		}
		$l = $this->recipientL10n->forMember($member);
		[$subject, $paragraphs] = $compose($l, $reimbursement, $member, $this->euro($reimbursement->getAmountCents()));
		$this->send($templateId, $email, $member->displayName(), $l, $subject, $paragraphs);
	}

	/** Die Adresse aus den Mitgliedsdaten, sonst die des verknüpften Kontos. */
	private function memberEmail(Member $member): ?string {
		$email = trim((string)$member->getEmail());
		if ($email !== '') {
			return $email;
		}
		$ncUserId = $member->getNcUserId();
		$fromAccount = $ncUserId !== null ? $this->userManager->get($ncUserId)?->getEMailAddress() : null;
		return $fromAccount !== null && $fromAccount !== '' ? $fromAccount : null;
	}

	/**
	 * @param list<string> $paragraphs
	 */
	private function send(string $templateId, string $email, string $recipientName, IL10N $l, string $subject, array $paragraphs): void {
		try {
			$template = $this->mailer->createEMailTemplate($templateId);
			$template->setSubject($subject);
			$this->mailBranding->addHeader($template);
			$template->addHeading($subject);
			foreach ($paragraphs as $paragraph) {
				$template->addBodyText($paragraph);
			}
			$this->mailBranding->addFooter($template, $l->getLanguageCode());

			$message = $this->mailer->createMessage();
			$this->mailBranding->apply($message);
			$message->setTo([$email => $recipientName]);
			$message->setSubject($template->renderSubject());
			$message->useTemplate($template);
			$this->mailer->send($message);
		} catch (\Throwable $e) {
			$this->logger->warning('Auslagenerstattung: Mail {template} konnte nicht gesendet werden: {message}', [
				'template' => $templateId,
				'message' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}

	private function memberName(Reimbursement $reimbursement): string {
		try {
			return $this->members->find($reimbursement->getMemberId())->displayName();
		} catch (DoesNotExistException) {
			return '';
		}
	}

	private function euro(int $cents): string {
		return number_format($cents / 100, 2, ',', '.');
	}
}
