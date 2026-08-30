<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Listener;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\WatchFolderService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Räumt die Einstellungen ab, die auf einen gelöschten Nextcloud-Nutzer zeigen.
 *
 * Belegablage und überwachter Ordner sind die einzigen Einstellungen, die auf
 * einen Nextcloud-Nutzer verweisen. Verschwindet der, bliebe sonst ein Name
 * stehen, hinter dem niemand mehr steht: die Belegablage schriebe in ein Home,
 * das es nicht mehr gibt, und der Wachordner suchte dort vergeblich. Das
 * Ereignis ist der einzige Weg, auf dem die App davon erfährt – aufräumen tun
 * die Einstellungen dann ihre Besitzer selbst.
 *
 * Zweite Ebene bleibt SettingsController::validateUser(); warum, steht dort.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {

	public function __construct(
		private AttachmentStorageService $attachmentStorage,
		private WatchFolderService $watchFolder,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof UserDeletedEvent)) {
			return;
		}
		$uid = $event->getUser()->getUID();

		if ($this->attachmentStorage->forgetUser($uid)) {
			$this->logger->warning('Belegablage lag im Home des gelöschten Nutzers {uid} – zurück auf die app-interne Ablage', [
				'app' => Application::APP_ID,
				'uid' => $uid,
			]);
		}
		if ($this->watchFolder->forgetUser($uid)) {
			$this->logger->warning('Überwachter Ordner lag im Home des gelöschten Nutzers {uid} – abgeschaltet', [
				'app' => Application::APP_ID,
				'uid' => $uid,
			]);
		}
	}
}
