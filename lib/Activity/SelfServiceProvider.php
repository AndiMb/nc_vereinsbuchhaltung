<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Activity;

use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;

/**
 * Formatiert Aktivitäts-Einträge für den Feed „Mein Beitrag" (Spec §3.4).
 *
 * Reines Grundgerüst für Issue #74: die auslösenden Aktionen (Mandat
 * erteilen/ändern, Beitrag anpassen) kommen erst mit #75/#76 und damit auch
 * die ersten konkreten Subjekt-Typen. Bis dahin kennt dieser Provider keinen
 * einzigen Subjekt-Typ und wirft für jedes Event {@see UnknownActivityException}
 * – der korrekte Weg, dem Activity-Manager zu sagen "nicht meins", statt
 * eine Formatierung vorzutäuschen, die es noch nicht gibt.
 */
class SelfServiceProvider implements IProvider {

	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
		throw new UnknownActivityException('Noch kein Self-Service-Ereignis definiert (folgt in #75/#76).');
	}
}
