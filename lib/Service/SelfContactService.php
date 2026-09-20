<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Activity\SelfServiceProvider;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Exception\ForbiddenException;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * Kontaktstammdaten-Pflege im Self-Service (Spec §3.4 Aktionskatalog „Darf":
 * „Kontaktstammdaten pflegen", Issue #76). Self-Service-Hülle um
 * {@see MemberService::updateOwnContactData()} – IDOR-Schutz (member_id
 * ausschließlich über {@see ActorContextService}, nie aus einem Parameter),
 * Quittungsmail und Activity-Feed-Eintrag.
 *
 * E-Mail-Wechsel (Spec §3.4/T33): die normale Quittung geht an die NEUE
 * Adresse (bzw. das NC-Konto, falls (noch) keine Mitglieds-Mailadresse
 * hinterlegt ist), zusätzlich ein eigener Warn-Text an die ALTE Adresse
 * ({@see SelfServiceReceiptMailService::sendOldAddressWarning()}).
 */
class SelfContactService {

	public function __construct(
		private ActorContextService $actorContext,
		private MemberService $memberService,
		private SelfServiceReceiptMailService $receiptMail,
		private SelfServiceActivityPublisher $activity,
		private IUserManager $userManager,
		private IL10N $l10n,
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 * @throws \InvalidArgumentException bei ungültigen Eingaben
	 */
	public function update(array $data): Member {
		$memberId = $this->requireMemberId();
		$result = $this->memberService->updateOwnContactData($memberId, $data);
		/** @var Member $member */
		$member = $result['member'];

		if ($member->getNcUserId() !== null) {
			$this->activity->publish($member->getNcUserId(), SelfServiceProvider::SUBJECT_CONTACT_UPDATED, [], 'member', $memberId);
		}

		$recipient = $this->resolveRecipient($member);
		if ($recipient !== null) {
			$this->receiptMail->sendReceipt(
				$member,
				$recipient,
				$this->l10n->t('Ihre Kontaktdaten wurden aktualisiert'),
				$this->l10n->t('Ihre Kontaktdaten wurden soeben in der Vereinsbuchhaltung aktualisiert.'),
				null,
				null,
			);
		}

		if ($result['emailChanged'] && $result['oldEmail'] !== null) {
			$newEmail = $member->getEmail();
			if ($newEmail !== null && $newEmail !== '') {
				$this->receiptMail->sendOldAddressWarning($member, $result['oldEmail'], $newEmail);
			}
		}

		return $member;
	}

	/** Dieselbe Priorität wie {@see ContributionPreNotificationService::resolveRecipient()}. */
	private function resolveRecipient(Member $member): ?string {
		if ($member->getEmail() !== null && $member->getEmail() !== '') {
			return $member->getEmail();
		}
		$user = $member->getNcUserId() !== null ? $this->userManager->get($member->getNcUserId()) : null;
		$accountEmail = $user?->getEMailAddress();
		return ($user !== null && $accountEmail !== null && $accountEmail !== '') ? $accountEmail : null;
	}

	private function requireMemberId(): int {
		$memberId = $this->actorContext->memberId();
		if ($memberId === null) {
			throw new ForbiddenException($this->l10n->t('Kein Self-Service-Zugang.'));
		}
		return $memberId;
	}
}
