<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\ContributionGroupMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Mail\IEMailTemplate;

/**
 * Die nächsten Schritte eines Mitglieds als Teil einer Mail: Passwort für ein
 * neu angelegtes Konto festlegen, die Kontoverknüpfung bestätigen, das
 * SEPA-Lastschriftmandat in „Mein Bereich" einrichten. Willkommensmail
 * ({@see WelcomeMailService}) und Mandats-Anfrage ({@see MandateRequestMailService})
 * bündeln damit, was sonst drei getrennte Mails wären.
 */
class MemberSetupSteps {

	public function __construct(
		private AccountHandoverService $accountHandover,
		private MemberLinkConfirmationService $linkConfirmation,
		private SelfServiceService $selfService,
		private DirectDebitEligibilityResolver $eligibility,
		private AssignmentMapper $assignments,
		private ContributionGroupMapper $groups,
		private IURLGenerator $urlGenerator,
		private ClubClock $clock,
		// Fehlermeldungen gehen an die Verwaltung, in ihrer Sprache; die Schritte selbst in der des Mitglieds.
		private IL10N $l10n,
	) {
	}

	/**
	 * Schritte der Willkommensmail: das Passwort, wenn die Verwaltung das Konto
	 * gerade angelegt hat, und das Mandat, wenn das Mitglied per Lastschrift
	 * zahlen soll, noch keines hat und es in „Mein Bereich" erteilen kann. Eine
	 * noch ausstehende Verknüpfung hat ihren Bestätigungslink schon mit eigener
	 * Mail bekommen; ohne Bestätigung führt der Mandats-Schritt ins Leere und
	 * fehlt deshalb.
	 *
	 * @return list<array{text: string, button: string, url: string}>
	 */
	public function forWelcome(Member $member, IL10N $l): array {
		$steps = $this->passwordStep($member, $l);
		$assignment = $this->directDebitAssignment($member);
		if ($assignment !== null && $member->isNcLinkConfirmed() && $this->mandateWanted($member)) {
			$steps[] = $this->mandateStep($assignment, $l);
		}
		return $steps;
	}

	/**
	 * Schritte der Mandats-Anfrage: Passwort (neu angelegtes Konto),
	 * Bestätigung einer noch ausstehenden Verknüpfung (ein neuer Link ersetzt
	 * den älteren) und das Mandat.
	 *
	 * @return list<array{text: string, button: string, url: string}>
	 * @throws \InvalidArgumentException wenn das Mitglied sein Mandat nicht selbst einrichten kann
	 */
	public function forMandateRequest(Member $member, IL10N $l): array {
		$this->assertMandateRequestPossible($member);
		$steps = $this->passwordStep($member, $l);
		if (!$member->isNcLinkConfirmed()) {
			$link = $this->linkConfirmation->issueLink($member);
			$steps[] = [
				'text' => $l->t('Ist das Ihr Konto, melden Sie sich damit an und bestätigen Sie über die Schaltfläche. Ist es nicht Ihr Konto, klicken Sie bitte nicht und geben Sie dem Verein Bescheid.'),
				'button' => $l->t('Konto bestätigen'),
				'url' => $link['url'],
			];
		}
		$steps[] = $this->mandateStep($this->directDebitAssignment($member), $l);
		return $steps;
	}

	/**
	 * Hängt die Schritte an die Mail, ab zwei Schritten nummeriert.
	 *
	 * @param list<array{text: string, button: string, url: string}> $steps
	 */
	public static function render(IEMailTemplate $mail, array $steps, IL10N $l): void {
		if ($steps === []) {
			return;
		}
		$numbered = count($steps) > 1;
		$mail->addBodyText(htmlspecialchars($l->t('So geht es weiter:'), ENT_QUOTES), $l->t('So geht es weiter:'));
		foreach ($steps as $index => $step) {
			$text = $numbered ? ($index + 1) . '. ' . $step['text'] : $step['text'];
			$mail->addBodyText(htmlspecialchars($text, ENT_QUOTES), $text);
			$mail->addBodyButton($step['button'], $step['url']);
		}
	}

	/** @throws \InvalidArgumentException */
	private function assertMandateRequestPossible(Member $member): void {
		$reason = match (true) {
			$member->isRedacted() => $this->l10n->t('Ein anonymisiertes Mitglied lässt sich nicht anschreiben.'),
			trim((string)$member->getEmail()) === '' => $this->l10n->t('Für dieses Mitglied ist keine Mailadresse hinterlegt.'),
			(string)$member->getNcUserId() === '' => $this->l10n->t('Das Mitglied ist mit keinem Nextcloud-Konto verknüpft. Ohne Konto lässt sich das Mandat nur hier anlegen, auf Papier oder elektronisch mit IBAN.'),
			!$this->selfService->isEnabled() => $this->l10n->t('„Mein Bereich" ist ausgeschaltet. Das Mitglied kann sein Mandat dort erst erteilen, wenn er in den Einstellungen eingeschaltet ist.'),
			!$this->mandateWanted($member) => $this->l10n->t('Das Mitglied hat bereits ein einzugsfähiges Mandat.'),
			default => null,
		};
		if ($reason !== null) {
			throw new \InvalidArgumentException($reason);
		}
	}

	/** @return list<array{text: string, button: string, url: string}> */
	private function passwordStep(Member $member, IL10N $l): array {
		$uid = (string)$member->getNcUserId();
		$url = $uid !== '' ? $this->accountHandover->setPasswordUrl($uid) : null;
		if ($url === null) {
			return [];
		}
		return [[
			'text' => $l->t('Legen Sie ein Passwort für Ihr Nextcloud-Konto „%s" fest. Der Link ist 7 Tage gültig.', [$uid]),
			'button' => $l->t('Passwort festlegen'),
			'url' => $url,
		]];
	}

	/** @return array{text: string, button: string, url: string} */
	private function mandateStep(?Assignment $assignment, IL10N $l): array {
		$text = $l->t('Damit Ihr Mitgliedsbeitrag und andere Beträge bequem per Lastschrift eingezogen werden können, hinterlegen Sie bitte unter „Mein Bereich" Ihre IBAN und erteilen Sie das SEPA-Lastschriftmandat.');
		if ($assignment !== null && count($this->groups->find($assignment->getGroupId())->getAllowedIntervalsArray()) > 1) {
			$text .= ' ' . $l->t('Dort können Sie auch wählen, wie oft der Beitrag eingezogen wird.');
		}
		return [
			'text' => $text,
			'button' => $l->t('„Mein Bereich" öffnen'),
			'url' => $this->urlGenerator->linkToRouteAbsolute('vereinsbuchhaltung.page.catchAll', ['path' => 'self']),
		];
	}

	/** Ob das Mitglied sein Mandat selbst einrichten soll: „Mein Bereich" ist an, ein einzugsfähiges Mandat fehlt. */
	private function mandateWanted(Member $member): bool {
		return $this->selfService->isEnabled() && !$this->eligibility->hasCollectibleMandate($member->getId());
	}

	/** Die laufende oder künftige Zuweisung des Mitglieds mit Zahlungsart Lastschrift, falls es eine gibt. */
	private function directDebitAssignment(Member $member): ?Assignment {
		$today = $this->clock->today();
		foreach ($this->assignments->findByMember((int)$member->getId()) as $assignment) {
			$validTo = $assignment->getValidTo();
			if ($assignment->getPaymentMethod() === Assignment::PAYMENT_METHOD_DIRECT_DEBIT
				&& ($validTo === null || ($validTo >= $today && $validTo >= $assignment->getValidFrom()))) {
				return $assignment;
			}
		}
		return null;
	}
}
