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
 * Verwirft Nextclouds Benachrichtigung „Vereinsbuchhaltung wurde auf
 * Version … aktualisiert“, wenn der Verein die Hinweise auf neue Versionen
 * ausgeschaltet hat (UpdateHintSettings).
 *
 * Die Benachrichtigung stammt nicht von dieser App, sondern von
 * `updatenotification` (Nextcloud 29+): Nach einem App-Update legt deren
 * Hintergrundjob AppUpdatedNotifications sie für jedes Konto an, für das die
 * App aktiviert ist – also auch für Mitglieder, die nur „Mein Bereich“ sehen.
 * Ihr Knopf „Was ist neu“ öffnet den Abschnitt aus CHANGELOG.en.md. Eine
 * Abmeldung pro App sieht Nextcloud nicht vor; der Schalter dort
 * (`occ config:app:set updatenotification app_updated.enabled --value=false
 * --type=boolean`) gilt für alle Apps der Instanz.
 *
 * Der Weg hier nutzt, dass der Notification-Manager JEDE Benachrichtigung
 * durch ALLE registrierten Notifier schickt, auch nachdem einer sie
 * aufbereitet hat (OC\Notification\Manager::prepare()). Wirft einer
 * AlreadyProcessedException, löscht der Manager die Benachrichtigung für
 * diese Person und zeigt sie nicht an – derselbe Weg, den Apps für veraltete
 * eigene Benachrichtigungen nehmen. Glocke, Push und Mail-Zusammenfassung
 * bereiten über prepare() auf und sehen sie deshalb alle nicht mehr. Die
 * Reihenfolge der Notifier spielt keine Rolle.
 *
 * Alles andere lehnt der Filter mit UnknownNotificationException ab, so wie
 * jeder Notifier fremde Benachrichtigungen ablehnt. Er wird für jede
 * Benachrichtigung der Instanz gefragt; deshalb prüft er zuerst die billigen
 * Merkmale und liest die Einstellung erst für einen Treffer.
 */
class UpdateNotificationFilter implements INotifier {

	/** App und Betreff, unter denen `updatenotification` App-Updates meldet. */
	public const SOURCE_APP = 'updatenotification';
	public const SOURCE_SUBJECT = 'app_updated';

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
		if (!self::isOwnUpdateNotice($notification) || $this->settings->isEnabled()) {
			throw new UnknownNotificationException();
		}
		throw new AlreadyProcessedException();
	}

	/** Meldet die Benachrichtigung ein Update genau dieser App? */
	public static function isOwnUpdateNotice(INotification $notification): bool {
		return $notification->getApp() === self::SOURCE_APP
			&& $notification->getSubject() === self::SOURCE_SUBJECT
			&& ($notification->getSubjectParameters()[0] ?? null) === Application::APP_ID;
	}
}
