<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Activity;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\SelfServiceMandateService;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\L10N\IFactory;

/**
 * Formatiert Aktivitäts-Einträge für den Feed „Mein Beitrag" (Spec §3.4).
 *
 * Erste konkrete Subjekt-Typen seit Issue #75: der Mandats-Aktionskatalog
 * (Erteilen/Bestätigen/IBAN-Änderung/Kontoinhaberwechsel/Widerruf, siehe
 * {@see SelfServiceMandateService}). Der Beitrags-Teil (#76) ergänzt diesen
 * `match` um weitere Subjekte, statt einen eigenen Provider zu registrieren -
 * ein Feed "Mein Beitrag" soll aus Mitgliedssicht einheitlich aussehen.
 *
 * `$language` kommt vom Activity-Manager separat vom aktuellen Request (ein
 * Feed-Eintrag kann später, ggf. in einer anderen Session/Sprache, gerendert
 * werden) - deshalb {@see IFactory} statt eines injizierten `IL10N`.
 */
class SelfServiceProvider implements IProvider {

	public function __construct(
		private IFactory $l10nFactory,
	) {
	}

	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
		if ($event->getApp() !== Application::APP_ID) {
			throw new UnknownActivityException('Fremde App: ' . $event->getApp());
		}
		$l10n = $this->l10nFactory->get(Application::APP_ID, $language);
		$subject = match ($event->getSubject()) {
			SelfServiceMandateService::SUBJECT_GRANTED => $l10n->t('SEPA-Lastschriftmandat elektronisch erteilt'),
			SelfServiceMandateService::SUBJECT_IBAN_CHANGED => $l10n->t('Bankverbindung des SEPA-Lastschriftmandats geändert'),
			SelfServiceMandateService::SUBJECT_HOLDER_CHANGED => $l10n->t('Kontoinhaber gewechselt, neues Mandat erteilt'),
			SelfServiceMandateService::SUBJECT_REVOKED => $l10n->t('SEPA-Lastschriftmandat widerrufen'),
			default => throw new UnknownActivityException('Unbekanntes Self-Service-Ereignis: ' . $event->getSubject()),
		};
		$event->setParsedSubject($subject);
		return $event;
	}
}
