<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\ReimbursementMapper;

/**
 * Wer die Auslagenerstattung als Mitglied nutzen darf.
 *
 * Wie beim Self-Service braucht es ein mit dem Nextcloud-Konto verknüpftes
 * Mitglied, aber einen eigenen Schalter: die Auslagen hängen nicht an
 * `self_service_enabled`. Ist die Funktion ausgeschaltet, behalten Mitglieder
 * mit eigenen Anträgen den Lesezugriff darauf (Design §2); nur das Einreichen
 * entfällt, das prüft der {@see ReimbursementService}.
 *
 * Zentrale Stelle, wie {@see SelfServiceService}: die PermissionMiddleware
 * (Gate für den SelfReimbursementController) und die „me"-Auskunft nutzen
 * beide diese Klasse.
 */
class ReimbursementAccess {

	public function __construct(
		private ReimbursementSettings $settings,
		private SelfServiceService $selfService,
		private ReimbursementMapper $mapper,
	) {
	}

	/** Das verknüpfte Mitglied, wenn es die Auslagen sehen darf – sonst null. */
	public function currentMember(): ?Member {
		$member = $this->selfService->currentMember();
		if ($member === null) {
			return null;
		}
		if ($this->settings->isEnabled() || $this->mapper->countByMember($member->getId()) > 0) {
			return $member;
		}
		return null;
	}

	/**
	 * Für die „me"-Auskunft der SPA: ob das Mitglied den Unterreiter „Auslagen"
	 * sieht (`available`) und ob die Verwaltungssicht unter „Buchungen" den
	 * Reiter zeigt (`staffVisible`: Funktion an oder es gibt Anträge).
	 *
	 * @return array{available: bool, staffVisible: bool}
	 */
	public function describeCurrent(): array {
		return [
			'available' => $this->currentMember() !== null,
			'staffVisible' => $this->settings->isEnabled() || $this->mapper->countAll() > 0,
		];
	}
}
