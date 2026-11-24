<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCP\Group\ISubAdmin;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\VerificationToken\IVerificationToken;

/**
 * Übergabe eines Nextcloud-Kontos, das die Verwaltung für ein Mitglied neu
 * angelegt hat.
 *
 * „Neu angelegt" heißt, serverseitig prüfbar: Das Konto hat sich noch nie
 * angemeldet, und die angemeldete Person darf es in Nextcloud verwalten
 * (dieselbe Regel wie Nextclouds Benutzerverwaltung). Ein fremdes Konto, das
 * sich über Profil-Mail und Namen als Mitglied ausgibt (#295), musste sich
 * dafür anmelden und fällt heraus; seine Verknüpfung braucht weiter die
 * Bestätigung per Mail-Link ({@see MemberLinkConfirmationService}).
 *
 * Für ein neu angelegtes Konto gilt die Verknüpfung sofort, und die
 * Willkommensmail bringt den Link zum Festlegen des Passworts selbst mit
 * ({@see WelcomeMailService}) – statt einer eigenen Einladung von Nextcloud.
 */
class AccountHandoverService {

	public function __construct(
		private IUserManager $userManager,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private ISubAdmin $subAdmin,
		private IVerificationToken $verificationToken,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function isFreshManagedAccount(string $uid): bool {
		$target = $this->userManager->get($uid);
		$actor = $this->userSession->getUser();
		if ($target === null || $actor === null || $target->getLastLogin() !== 0 || $actor->getUID() === $target->getUID()) {
			return false;
		}
		$actorUid = $actor->getUID();
		return $this->groupManager->isAdmin($actorUid)
			|| ($this->groupManager->isDelegatedAdmin($actorUid) && !$this->groupManager->isAdmin($target->getUID()))
			|| $this->subAdmin->isUserAccessible($actor, $target);
	}

	/**
	 * Link zum Festlegen des Passworts für ein neu angelegtes Konto – derselbe
	 * Token und dieselbe Seite wie Nextclouds eigene Einladung (`lostpassword`,
	 * an die Mailadresse des Kontos gebunden, 7 Tage gültig). Ein neuer Link
	 * ersetzt einen älteren.
	 *
	 * @return string|null null, wenn das Konto nicht (mehr) als neu angelegt gilt
	 */
	public function setPasswordUrl(string $uid): ?string {
		$user = $this->userManager->get($uid);
		if ($user === null || !$this->isFreshManagedAccount($uid)) {
			return null;
		}
		$token = $this->verificationToken->create($user, 'lostpassword', $user->getEMailAddress() ?? '');
		return $this->urlGenerator->linkToRouteAbsolute('core.lost.resetform', ['userId' => $uid, 'token' => $token]);
	}
}
