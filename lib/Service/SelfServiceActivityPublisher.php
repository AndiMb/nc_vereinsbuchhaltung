<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Activity\SelfServiceSetting;
use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCP\Activity\IManager;

/**
 * Veröffentlicht Activity-Feed-Einträge für Self-Service-Änderungen (Spec
 * §3.4: „OCP\Activity-Feed (jede Änderung)") – dünne Kapselung um
 * {@see IManager}, damit die aufrufenden Services (SelfContributionService,
 * SelfController) nicht selbst die fluent-API von {@see \OCP\Activity\IEvent}
 * zusammensetzen müssen und sich das in Tests durch einen einfachen Mock
 * dieser Klasse ersetzen lässt.
 *
 * `$affectedNcUserId` ist immer gesetzt: der Self-Service-Kanal setzt per
 * Definition eine Kontoverknüpfung voraus (siehe SelfServiceService), es gibt
 * also nie einen Self-Service-Vorgang ohne NC-Konto, an dessen Feed sich das
 * Ereignis hängen ließe.
 */
class SelfServiceActivityPublisher {

	public function __construct(
		private IManager $manager,
	) {
	}

	/** @param array<string,mixed> $subjectParams */
	public function publish(string $affectedNcUserId, string $subject, array $subjectParams, string $objectType, int $objectId): void {
		$event = $this->manager->generateEvent();
		$event->setApp(Application::APP_ID)
			->setType(SelfServiceSetting::TYPE)
			->setAffectedUser($affectedNcUserId)
			->setAuthor($affectedNcUserId)
			->setTimestamp(time())
			->setSubject($subject, $subjectParams)
			->setObject($objectType, $objectId);
		$this->manager->publish($event);
	}
}
