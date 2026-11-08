<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Listener;

use OCA\Vereinsbuchhaltung\Db\PermissionMapper;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\User\Events\UserDeletedEvent;

/**
 * Entfernt die Rollen eines gelöschten Nextcloud-Nutzers oder einer gelöschten
 * Gruppe (#298).
 *
 * Eine Rolle hängt nur an der ID (`principal_id`). Bliebe sie nach dem Löschen
 * stehen, erbte jedes später angelegte Konto oder jede Gruppe mit derselben ID
 * die Rolle – bis hin zum Verwalter –, ohne dass jemand sie neu vergeben hätte.
 * IDs werden wiederverwendet, etwa bei LDAP/SSO-Gruppen oder gleichen
 * Benutzernamen. Jede Entfernung steht im Protokoll.
 *
 * @template-implements IEventListener<Event>
 */
class PermissionPrincipalDeletedListener implements IEventListener {

	public function __construct(
		private PermissionMapper $permissions,
		private AuditService $audit,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof UserDeletedEvent) {
			$this->revoke('user', $event->getUser()->getUID());
		} elseif ($event instanceof GroupDeletedEvent) {
			$this->revoke('group', $event->getGroup()->getGID());
		}
	}

	private function revoke(string $type, string $id): void {
		foreach ($this->permissions->deleteByPrincipal($type, $id) as $removed) {
			$this->audit->log('Berechtigung entfernt', 'permission', $removed->getId(), [
				'typ' => $type,
				'wer' => $id,
				'rolle' => $removed->getRole(),
				'grund' => $type === 'user' ? 'Nextcloud-Nutzer gelöscht' : 'Nextcloud-Gruppe gelöscht',
			]);
		}
	}
}
