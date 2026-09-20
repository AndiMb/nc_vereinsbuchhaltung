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
 * Formatiert Aktivitäts-Einträge für den Feed „Mein Beitrag" (Spec §3.4:
 * „OCP\Activity-Feed (jede Änderung)"). Ein einziger Provider für BEIDE
 * Aktionskataloge - Mandat (#75: Erteilen/IBAN-Änderung/Kontoinhaberwechsel/
 * Widerruf, siehe {@see SelfServiceMandateService}) und Beitrag (#76:
 * Betrag/Turnus/Kontaktdaten) -, weil ein Feed "Mein Beitrag" aus
 * Mitgliedssicht einheitlich aussehen soll, nicht nach internem Ticket
 * getrennt. Unterschieden wird dabei rein über `getSubject()` (die
 * Subjekt-Strings sind über beide Kataloge hinweg eindeutig), NICHT über
 * `getType()` - der Widerruf nutzt für die abweichende Mail-Voreinstellung
 * einen zweiten Einstellungs-Typ ({@see SelfServiceMandateRevocationSetting}),
 * den dieser eine Provider ebenfalls bedient.
 *
 * Übersetzt bewusst erst hier, nicht schon beim Veröffentlichen ({@see
 * \OCA\Vereinsbuchhaltung\Service\SelfServiceActivityPublisher}): der Feed
 * kann in einer anderen Sprache gelesen werden, als in der die Änderung
 * vorgenommen wurde – `$params` trägt deshalb nur fachliche Rohwerte
 * (Cent-Beträge etc.), keinen bereits übersetzten Text.
 *
 * `$language` kommt vom Activity-Manager separat vom aktuellen Request (ein
 * Feed-Eintrag kann später, ggf. in einer anderen Session/Sprache, gerendert
 * werden) - deshalb {@see IFactory} statt eines injizierten `IL10N`.
 */
class SelfServiceProvider implements IProvider {

	public const SUBJECT_CONTRIBUTION_AMOUNT_CHANGED = 'contribution_amount_changed';
	public const SUBJECT_CONTRIBUTION_INTERVAL_CHANGED = 'contribution_interval_changed';
	public const SUBJECT_CONTACT_UPDATED = 'contact_updated';

	public function __construct(
		private IFactory $l10nFactory,
	) {
	}

	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
		if ($event->getApp() !== Application::APP_ID) {
			throw new UnknownActivityException('Fremde App: ' . $event->getApp());
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $language);
		$params = $event->getSubjectParameters();

		$text = match ($event->getSubject()) {
			SelfServiceMandateService::SUBJECT_GRANTED => $l->t('SEPA-Lastschriftmandat elektronisch erteilt'),
			SelfServiceMandateService::SUBJECT_IBAN_CHANGED => $l->t('Bankverbindung des SEPA-Lastschriftmandats geändert'),
			SelfServiceMandateService::SUBJECT_HOLDER_CHANGED => $l->t('Kontoinhaber gewechselt, neues Mandat erteilt'),
			SelfServiceMandateService::SUBJECT_REVOKED => $l->t('SEPA-Lastschriftmandat widerrufen'),
			self::SUBJECT_CONTRIBUTION_AMOUNT_CHANGED => $l->t(
				'Monatsbeitrag geändert: %1$s € → %2$s € (wirkt ab %3$s)',
				[$this->euro($params['from'] ?? 0), $this->euro($params['to'] ?? 0), $params['effectiveFrom'] ?? '?'],
			),
			self::SUBJECT_CONTRIBUTION_INTERVAL_CHANGED => $l->t(
				'Turnus geändert: alle %1$d Monate → alle %2$d Monate (wirkt ab %3$s)',
				[$params['from'] ?? 0, $params['to'] ?? 0, $params['effectiveFrom'] ?? '?'],
			),
			self::SUBJECT_CONTACT_UPDATED => $l->t('Kontaktdaten aktualisiert'),
			default => throw new UnknownActivityException('Unbekanntes Self-Service-Ereignis: ' . $event->getSubject()),
		};

		$event->setParsedSubject($text);
		return $event;
	}

	private function euro(int $cents): string {
		return number_format($cents / 100, 2, ',', '.');
	}
}
