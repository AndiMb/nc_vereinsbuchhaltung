<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCP\IL10N;
use OCP\IUserManager;

/**
 * Prüft und benennt einen „Zahler": entweder ein Nextcloud-Konto dieser
 * Instanz (member_uid) oder ein frei benannter Zahler (member_label) – nie
 * beides oder keins. Gemeinsam genutzt von SepaMandateService und
 * MembershipFeeService (siehe Migration 000124 für die Begründung, warum es
 * keine eigene Mitglieder-Tabelle gibt).
 */
class MemberReferenceValidator {

	public function __construct(
		private IUserManager $userManager,
		private IL10N $l10n,
	) {
	}

	/**
	 * @return array{0: ?string, 1: ?string} [memberUid, memberLabel] normalisiert
	 * @throws \InvalidArgumentException bei ungültigen/widersprüchlichen Angaben
	 */
	public function validate(?string $memberUid, ?string $memberLabel): array {
		$memberUid = $memberUid !== null && trim($memberUid) !== '' ? trim($memberUid) : null;
		$memberLabel = $memberLabel !== null && trim($memberLabel) !== '' ? mb_substr(trim($memberLabel), 0, 255) : null;

		if ($memberUid !== null && $memberLabel !== null) {
			throw new \InvalidArgumentException($this->l10n->t('Bitte entweder einen Nextcloud-Nutzer oder einen freien Zahlernamen angeben, nicht beides.'));
		}
		if ($memberUid === null && $memberLabel === null) {
			throw new \InvalidArgumentException($this->l10n->t('Bitte einen Nextcloud-Nutzer oder einen freien Zahlernamen angeben.'));
		}
		if ($memberUid !== null && !$this->userManager->userExists($memberUid)) {
			throw new \InvalidArgumentException($this->l10n->t('Diesen Nextcloud-Nutzer gibt es nicht: %s', [$memberUid]));
		}
		return [$memberUid, $memberLabel];
	}

	public function displayName(?string $memberUid, ?string $memberLabel): string {
		if ($memberUid !== null) {
			return $this->userManager->get($memberUid)?->getDisplayName() ?? $memberUid;
		}
		return $memberLabel ?? '';
	}
}
