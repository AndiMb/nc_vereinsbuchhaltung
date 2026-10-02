<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCP\IConfig;
use OCP\IUserSession;

/**
 * Self-Service-Zugang (Spec §3.4 „Zugang"): keine Rolle, sondern zwei
 * unabhängige Bedingungen, die BEIDE gelten müssen:
 *
 *  - `self_service_enabled` – globaler An/Aus-Schalter der App, nur ab
 *    Rolle Verwalter änderbar (siehe SettingsController).
 *  - Kontoverknüpfung – das angemeldete NC-Konto muss per `nc_user_id`
 *    genau einem {@see Member} zugeordnet sein.
 *
 * Zentrale Stelle für diese Prüfung: die PermissionMiddleware (vierter
 * instanceof-Sonderfall, gate für den SelfController) und die „me"-Auskunft
 * (PermissionController, steuert die Sichtbarkeit von „Mein Beitrag" in der
 * SPA) nutzen beide diese Klasse – nie eigene, potenziell abweichende Logik.
 */
class SelfServiceService {

	public function __construct(
		private IConfig $config,
		private MemberMapper $memberMapper,
		private IUserSession $userSession,
	) {
	}

	public function isEnabled(): bool {
		return $this->config->getAppValue(Application::APP_ID, 'self_service_enabled', '0') === '1';
	}

	/**
	 * Das per `nc_user_id` verknüpfte Mitglied des angemeldeten Kontos, falls
	 * vorhanden – unabhängig von {@see isEnabled()}, damit hasAccess() beide
	 * Bedingungen unabhängig voneinander prüfen kann.
	 */
	public function currentMember(): ?Member {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}
		return $this->memberMapper->findByNcUserId($user->getUID());
	}

	/** Ob der aktuelle Request in den Self-Service darf: Schalter UND Kontoverknüpfung. */
	public function hasAccess(): bool {
		return $this->isEnabled() && $this->currentMember() !== null;
	}

	/**
	 * Für die „me"-Auskunft: ob die SPA den Bereich „Mein Beitrag" zeigen
	 * soll, plus die aufgelöste member_id (nur informativ – Anfragen selbst
	 * lösen sie immer erneut über die Middleware auf, siehe SelfController).
	 *
	 * @return array{available: bool, memberId: int|null}
	 */
	public function describeCurrent(): array {
		$member = $this->isEnabled() ? $this->currentMember() : null;
		return [
			'available' => $member !== null,
			'memberId' => $member?->getId(),
		];
	}
}
