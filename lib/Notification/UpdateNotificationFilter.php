<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Notification;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\UpdateHintSettings;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Verwirft Nextclouds Benachrichtigung „Vereinsbuchhaltung wurde aktualisiert“
 * (App updatenotification), wenn die Hinweise auf neue Versionen aus sind.
 *
 * Eine Abmeldung pro App sieht Nextcloud nicht vor. Der Notification-Manager
 * schickt aber jede Benachrichtigung durch alle Notifier; wirft einer
 * AlreadyProcessedException, löscht er sie, und Glocke, Push und Mail zeigen
 * sie nicht an. Alles andere lehnt der Filter ab wie jeder fremde Notifier.
 */
class UpdateNotificationFilter implements INotifier {

	public function __construct(
		private UpdateHintSettings $settings,
		private IFactory $l10nFactory,
	) {
	}

	public function getID(): string {
		return Application::APP_ID . '_update_hints';
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Vereinsbuchhaltung');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		$ownUpdateNotice = $notification->getApp() === 'updatenotification'
			&& $notification->getSubject() === 'app_updated'
			&& ($notification->getSubjectParameters()[0] ?? null) === Application::APP_ID;
		if (!$ownUpdateNotice || $this->settings->isEnabled()) {
			throw new UnknownNotificationException();
		}
		throw new AlreadyProcessedException();
	}
}
